import WebTorrent from 'webtorrent'
import path from 'node:path'
import fs from 'node:fs'
import os from 'node:os'
import { v4 as uuid } from 'uuid'

import {
  analisarArquivo,
  iniciarConversao,
  aguardarBufferInicial,
  localizarMoov,
} from './hls.js'
import { logger } from '../utils/logger.js'

/**
 * Gerenciador de sessões de reprodução.
 *
 * Cada sessão representa uma tentativa de assistir a um filme: ela conecta o
 * torrent, escolhe o arquivo de vídeo, decide o modo de conversão e publica o
 * HLS que o player consome. As sessões vivem em memória porque são efêmeras —
 * morrem quando o usuário fecha o player.
 *
 * O cliente WebTorrent é único e compartilhado: abrir um cliente por sessão
 * multiplicaria as conexões de rede sem necessidade.
 */

/*
 * O transporte uTP (utp-native) provoca segfault neste ambiente de container:
 * o processo morre com SIGSEGV sem chance de tratar o erro, derrubando todas
 * as sessões. Desligamos apenas o uTP e mantemos TCP, DHT e trackers, que são
 * suficientes para montar a malha e baixar o filme.
 */
const cliente = new WebTorrent({ utp: false })

/** Mapa `sessao_id → sessão`. */
const sessoes = new Map()

/** Extensões de vídeo que consideramos ao escolher o arquivo do torrent. */
const EXTENSOES_VIDEO = ['.mkv', '.mp4', '.avi', '.mov', '.m4v', '.webm']

/**
 * Cria uma sessão e começa a preparar a reprodução.
 *
 * A função devolve o id imediatamente: a conexão do torrent e a conversão
 * acontecem em segundo plano, e o frontend acompanha o andamento pelo status.
 *
 * @param {object} opcoes
 * @param {string} opcoes.magnet link magnet da fonte escolhida
 * @param {number|string} opcoes.filmeId identificador do filme (para log)
 * @returns {{sessao_id: string, status: string}}
 */
export function criarSessao({ magnet, filmeId }) {
  const id = uuid()
  const diretorio = path.join(os.tmpdir(), `stretor-${id}`)

  const sessao = {
    id,
    filmeId,
    magnet,
    status: 'conectando',
    mensagem: 'Conectando à fonte...',
    diretorio,
    torrent: null,
    comando: null,
    playlist: null,
    erro: null,
    progresso: null,
    download: null,
    criadaEm: Date.now(),
  }

  sessoes.set(id, sessao)

  // Não aguardamos: o frontend consulta o status enquanto isso roda.
  prepararSessao(sessao).catch((erro) => {
    logger.error(`[sessao ${id}] falha ao preparar:`, erro.message)
    sessao.status = 'erro'
    sessao.erro = erro.message
  })

  return { sessao_id: id, status: sessao.status }
}

/**
 * Conecta o torrent e inicia a conversão.
 *
 * Há dois caminhos, decididos pela posição do índice do contêiner:
 *
 * - **MKV/WebM e MP4 *faststart*** trazem o índice no começo e fluem por um
 *   pipe. Abrimos um fluxo do arquivo e o entregamos ao FFmpeg, que publica os
 *   segmentos conforme os bytes chegam — o player começa antes do fim do
 *   download.
 * - **MP4/MOV com o `moov` no fim** não fluem por pipe: o FFmpeg lê o MP4
 *   sequencialmente e não consegue buscar o índice depois de atravessar o
 *   `mdat`. Reordenar o fluxo também não serve, porque as tabelas de amostras
 *   do `moov` guardam offsets absolutos do arquivo original. Nesses casos
 *   aguardamos o download completo e convertemos do disco.
 *
 * @param {object} sessao
 */
async function prepararSessao(sessao) {
  const torrent = await adicionarTorrent(sessao.magnet)
  sessao.torrent = torrent

  sessao.status = 'aguardando'
  sessao.mensagem = 'Aguardando dados da fonte...'

  const arquivo = escolherArquivoDeVideo(torrent)

  if (!arquivo) {
    throw new Error('A fonte não contém um arquivo de vídeo reconhecido.')
  }

  // `arquivo.path` é relativo à pasta do torrent (ex.: "Filme (1999)/filme.mp4"),
  // não ao sistema de arquivos. Sem juntar com `torrent.path`, o FFmpeg procura
  // o arquivo no diretório de trabalho do processo e falha com "No such file".
  const caminho = path.join(torrent.path, arquivo.path)

  /*
   * Selecionar o arquivo inteiro (prioridade 1) manda o WebTorrent baixar em
   * ordem, do começo para frente — exatamente a ordem em que o FFmpeg lê. Sem
   * uma prioridade explícita o valor vira 0 ("sem prioridade"), o que ainda
   * desperta o interesse do torrent mas não deixa a intenção óbvia.
   */
  arquivo.select(1)

  /*
   * O download corre junto com a conversão; só registramos o andamento para
   * dar contexto no overlay. Não bloqueia.
   */
  acompanharDownload(sessao, torrent)

  /*
   * Descobrimos se o índice está acessível desde o começo. Enquanto o arquivo
   * não tem bytes suficientes, `localizarMoov` devolve `null`; nesse caso
   * assumimos o caminho progressivo e deixamos o `analisarComEspera` aguardar o
   * cabeçalho — se for um MP4 com `moov` no fim, a análise só vai concluir
   * quando o download alcançar o índice, e aí caímos no caminho não progressivo.
   */
  const indiceNoFim = await indiceEstaNoFim(sessao, arquivo, caminho)

  const analise = await analisarComEspera(caminho, arquivo)

  sessao.status = 'convertendo'
  sessao.mensagem = 'Preparando a conversão...'

  logger.info(
    `[sessao ${sessao.id}] modo=${analise.modo} video=${analise.videoCodec} audio=${analise.audioCodec} progressivo=${!indiceNoFim}`
  )

  sessao.mensagem = mensagemDoModo(analise.modo)

  if (indiceNoFim) {
    /*
     * Sem pipe: o índice só é legível com o arquivo inteiro em disco. Esperamos
     * o download terminar e convertemos do caminho — o FFmpeg busca o `moov`
     * normalmente porque o arquivo é buscável.
     */
    sessao.mensagem = 'Baixando o filme...'
    await aguardarDownload(sessao, torrent)

    sessao.comando = iniciarConversao({
      caminho,
      extensao: path.extname(arquivo.name),
      diretorio: sessao.diretorio,
      modo: analise.modo,
      aoProgredir: (progresso) => {
        sessao.progresso = progresso
      },
    })
  } else {
    /*
     * O fluxo é criado agora e fica aberto até o fim do download. O FFmpeg o
     * consome na medida em que os bytes chegam, publicando os segmentos.
     */
    const fluxo = arquivo.createReadStream()

    fluxo.on('error', (erro) => {
      logger.warn(`[sessao ${sessao.id}] erro no fluxo do torrent:`, erro.message)
    })

    sessao.comando = iniciarConversao({
      fluxo,
      extensao: path.extname(arquivo.name),
      diretorio: sessao.diretorio,
      modo: analise.modo,
      aoProgredir: (progresso) => {
        sessao.progresso = progresso
      },
    })
  }

  // Só liberamos o player quando há segmentos suficientes para tocar sem
  // travar — é o buffer que protege conexões lentas.
  await aguardarBufferInicial(sessao.diretorio)

  sessao.status = 'pronto'
  sessao.mensagem = 'Pronto para reproduzir'
  sessao.playlist = path.join(sessao.diretorio, 'playlist.m3u8')
}

/**
 * Verifica se o índice do contêiner está no fim do arquivo.
 *
 * Para MKV/WebM e MP4 *faststart* o índice fica no começo e o fluxo progressivo
 * funciona. Para MP4/MOV com o `moov` no fim, o pipe não serve e é preciso
 * esperar o download completo.
 *
 * Enquanto o arquivo não tem bytes suficientes para localizar o `moov`,
 * devolvemos `false` (caminho progressivo): se for um MP4 com índice no fim, a
 * `analisarComEspera` só vai concluir quando o índice chegar, e aí o download
 * já estará completo de qualquer forma.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {string} caminho caminho do arquivo em disco
 * @returns {Promise<boolean>}
 */
async function indiceEstaNoFim(sessao, arquivo, caminho) {
  const extensao = path.extname(arquivo.name).toLowerCase()

  // Só MP4/MOV têm o `moov` no fim; os demais contêineres trazem o índice no
  // começo e fluem pelo pipe sem preparação.
  if (!['.mp4', '.m4v', '.mov'].includes(extensao)) {
    return false
  }

  const moov = localizarMoov(caminho, arquivo.length)

  if (!moov) {
    return false
  }

  // Um `moov` nos primeiros megabytes é o caso *faststart*: flui pelo pipe.
  if (moov.inicio < 1024 * 1024) {
    return false
  }

  logger.info(
    `[sessao ${sessao.id}] moov no fim (offset ${moov.inicio}); aguardando o download completo`
  )

  return true
}

/**
 * Aguarda o download do torrent terminar.
 *
 * Usado apenas no caminho não progressivo (MP4 com `moov` no fim), em que a
 * conversão precisa do arquivo inteiro em disco. O progresso é refletido no
 * overlay para o usuário saber que ainda está baixando.
 *
 * @param {object} sessao
 * @param {import('webtorrent').Torrent} torrent
 */
function aguardarDownload(sessao, torrent) {
  return new Promise((resolve) => {
    if (torrent.progress >= 1) {
      resolve()
      return
    }

    const concluir = () => {
      torrent.off('done', concluir)
      resolve()
    }

    torrent.on('done', concluir)
  })
}

/**
 * Adiciona o magnet ao cliente e resolve quando o torrent estiver pronto.
 *
 * Se o mesmo torrent já estiver no cliente — o usuário reabriu o filme sem que
 * a sessão anterior tivesse sido encerrada — reaproveitamos a instância em vez
 * de tentar adicionar de novo. O `cliente.add` recusa duplicatas com "Cannot add
 * duplicate torrent", o que derrubava a segunda tentativa de assistir.
 *
 * No WebTorrent 2.x o `cliente.get()` devolve uma Promise, então a função é
 * assíncrona para poder aguardá-la.
 *
 * @param {string} magnet
 * @returns {Promise<import('webtorrent').Torrent>}
 */
async function adicionarTorrent(magnet) {
  const existente = await cliente.get(magnet)

  if (existente) {
    if (existente.ready) {
      return existente
    }

    return new Promise((resolve, reject) => {
      existente.once('ready', () => resolve(existente))
      existente.once('error', reject)
    })
  }

  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      reject(new Error('Tempo esgotado ao conectar na fonte.'))
    }, 45000)

    const torrent = cliente.add(magnet, { path: os.tmpdir() })

    torrent.on('ready', () => {
      clearTimeout(timeout)
      resolve(torrent)
    })

    torrent.on('error', (erro) => {
      clearTimeout(timeout)
      reject(erro)
    })
  })
}

/**
 * Escolhe o maior arquivo de vídeo do torrent.
 *
 * Torrents de filme costumam trazer amostras e arquivos extras; o maior arquivo
 * com extensão de vídeo é quase sempre o filme em si.
 *
 * @param {import('webtorrent').Torrent} torrent
 */
function escolherArquivoDeVideo(torrent) {
  const videos = torrent.files.filter((arquivo) =>
    EXTENSOES_VIDEO.includes(path.extname(arquivo.name).toLowerCase())
  )

  if (videos.length === 0) {
    return null
  }

  return videos.sort((a, b) => b.length - a.length)[0]
}

/**
 * Acompanha o download em segundo plano, sem bloquear a conversão.
 *
 * Diferente da versão anterior, que esperava o arquivo inteiro, aqui só
 * registramos o andamento. O download corre junto com a conversão, então o
 * percentual serve de contexto no overlay enquanto os segmentos são gerados.
 *
 * @param {object} sessao
 * @param {import('webtorrent').Torrent} torrent
 */
function acompanharDownload(sessao, torrent) {
  const atualizar = () => {
    const percentual = Math.round(torrent.progress * 100)

    sessao.download = { percentual }
  }

  const concluir = () => {
    torrent.off('download', atualizar)
    torrent.off('done', concluir)

    sessao.download = { percentual: 100 }
  }

  if (torrent.progress >= 1) {
    concluir()
    return
  }

  torrent.on('download', atualizar)
  torrent.on('done', concluir)

  atualizar()
}

/**
 * Analisa o arquivo insistindo até o cabeçalho ficar legível.
 *
 * Alguns MP4 trazem o átomo `moov` no fim do arquivo, então o ffprobe só
 * consegue ler os metadados depois que o torrent baixou aquele trecho. Como
 * não dá para saber de antemão quanto falta, tentamos de novo a cada segundo
 * até conseguir — o download segue em paralelo.
 *
 * @param {string} caminho caminho absoluto do arquivo no disco
 * @param {import('webtorrent').TorrentFile} arquivo arquivo do torrent
 */
async function analisarComEspera(caminho, arquivo, timeoutMs = 120000) {
  const inicio = Date.now()
  let ultimoErro = null

  while (Date.now() - inicio < timeoutMs) {
    try {
      const analise = await analisarArquivo(caminho)

      // Um cabeçalho lido pela metade devolve os streams sem os codecs. Aceitar
      // isso faria o FFmpeg "concluir" sem gerar segmento nenhum, então só
      // consideramos a análise válida quando vídeo e áudio foram identificados.
      if (analise.videoCodec && analise.audioCodec) {
        return analise
      }

      ultimoErro = new Error('cabeçalho incompleto')
    } catch (erro) {
      ultimoErro = erro
    }

    // Se o torrent já terminou e ainda assim não lemos o cabeçalho, não há
    // mais o que esperar: o arquivo está corrompido ou não é um vídeo válido.
    if (arquivo.progress >= 1) {
      break
    }

    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  throw new Error(
    `Não foi possível ler o cabeçalho do vídeo: ${ultimoErro?.message || 'formato desconhecido'}`
  )
}

/** Mensagem exibida no overlay conforme o modo de conversão. */
function mensagemDoModo(modo) {
  if (modo === 'remux') return 'Ajustando o contêiner do vídeo...'
  if (modo === 'audio') return 'Convertendo o áudio...'

  return 'Convertendo o vídeo...'
}

/** Devolve o estado atual de uma sessão. */
export function obterSessao(id) {
  const sessao = sessoes.get(id)

  if (!sessao) {
    return null
  }

  return {
    sessao_id: sessao.id,
    status: sessao.status,
    mensagem: sessao.mensagem,
    erro: sessao.erro,
    progresso: sessao.progresso ?? null,
    // O download corre em paralelo à conversão; expomos o percentual para o
    // overlay mostrar o quanto da fonte já chegou.
    download: sessao.download ?? null,
    // A URL só é exposta quando a playlist está pronta para ser consumida.
    playlist: sessao.status === 'pronto' ? `/api/media/sessao/${sessao.id}/playlist.m3u8` : null,
  }
}

/** Caminho da playlist de uma sessão pronta. */
export function caminhoPlaylist(id) {
  const sessao = sessoes.get(id)

  if (!sessao || sessao.status !== 'pronto') {
    return null
  }

  return sessao.playlist
}

/** Diretório onde os segmentos de uma sessão são escritos. */
export function diretorioSessao(id) {
  return sessoes.get(id)?.diretorio ?? null
}

/**
 * Encerra uma sessão: mata a conversão, remove o torrent e limpa o disco.
 *
 * @param {string} id
 */
export function encerrarSessao(id) {
  const sessao = sessoes.get(id)

  if (!sessao) {
    return false
  }

  try {
    // O supervisor da conversão pode ter uma passada em andamento ou estar
    // esperando mais dados; `parar` cobre os dois casos.
    sessao.comando?.parar()
  } catch (erro) {
    logger.warn(`[sessao ${id}] falha ao encerrar a conversão:`, erro.message)
  }

  try {
    if (sessao.torrent) {
      cliente.remove(sessao.torrent, { destroyStore: true })
    }
  } catch (erro) {
    logger.warn(`[sessao ${id}] falha ao remover o torrent:`, erro.message)
  }

  try {
    fs.rmSync(sessao.diretorio, { recursive: true, force: true })
  } catch (erro) {
    logger.warn(`[sessao ${id}] falha ao limpar o diretório:`, erro.message)
  }

  sessoes.delete(id)

  return true
}

/**
 * Remove sessões antigas que ficaram para trás (player fechado sem aviso).
 * Roda periodicamente para não acumular torrents e processos FFmpeg órfãos.
 */
export function limparSessoesAntigas(idadeMaximaMs = 3 * 60 * 60 * 1000) {
  const agora = Date.now()

  for (const [id, sessao] of sessoes) {
    if (agora - sessao.criadaEm > idadeMaximaMs) {
      logger.info(`[sessao ${id}] removida por inatividade`)
      encerrarSessao(id)
    }
  }
}
