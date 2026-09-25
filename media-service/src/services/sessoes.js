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
 * Tempo máximo aguardando o primeiro byte de dados, em ms.
 *
 * O evento `ready` do torrent só garante que os metadados do magnet foram
 * lidos — não que exista algum peer disposto a enviar dados. Uma fonte sem
 * peers ficava presa em `analisarComEspera` por 120 s, enquanto o frontend já
 * havia desistido aos 90 s. Este limite falha rápido para o frontend seguir
 * para a próxima fonte, e fica abaixo do `TIMEOUT_FONTE_MS` do frontend para
 * que o backend seja o primeiro a desistir.
 */
const TIMEOUT_DADOS_MS = 30000

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
    duracao: null,
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
   * Antes de qualquer análise, confirmamos que a fonte realmente envia dados.
   * O `ready` do torrent só diz que os metadados foram lidos; sem este passo,
   * uma fonte sem peers ficava 120 s em `analisarComEspera` enquanto o
   * frontend já tinha desistido. Falhamos rápido para o frontend tentar a
   * próxima fonte.
   */
  await aguardarDados(sessao, torrent)

  /*
   * Descobrimos se o índice está acessível desde o começo. Enquanto o arquivo
   * não tem bytes suficientes, `localizarMoov` devolve `null`; nesse caso
   * assumimos o caminho progressivo e deixamos o `analisarComEspera` aguardar o
   * cabeçalho — se for um MP4 com `moov` no fim, a análise só vai concluir
   * quando o download alcançar o índice, e aí caímos no caminho não progressivo.
   */
  const indiceNoFim = await indiceEstaNoFim(sessao, arquivo, caminho)

  const analise = await analisarComEspera(caminho, arquivo)

  /*
   * Guardamos a duração assim que o ffprobe a lê. O Plyr não consegue deduzi-la
   * de uma playlist `EVENT` em crescimento, então o frontend a usa como fonte
   * de verdade enquanto a conversão não termina.
   */
  sessao.duracao = analise.duracao ?? null

  sessao.status = 'convertendo'
  sessao.mensagem = 'Preparando a conversão...'

  logger.info(
    `[sessao ${sessao.id}] modo=${analise.modo} video=${analise.videoCodec} audio=${analise.audioCodec} duracao=${sessao.duracao ?? '?'} progressivo=${!indiceNoFim}`
  )

  sessao.mensagem = mensagemDoModo(analise.modo)

  if (indiceNoFim) {
    /*
     * Sem pipe: o FFmpeg lê o MP4 sequencialmente e não busca o `moov` no fim.
     * A saída é converter do disco, que é buscável — mas esperar o download
     * inteiro deixaria o usuário minutos sem imagem.
     *
     * Priorizamos o trecho do `moov` (o índice) e só então abrimos a conversão.
     * Com o índice em disco, o FFmpeg consegue montar a timeline e lê o `mdat`
     * conforme os bytes chegam, sem precisar do arquivo completo.
     */
    sessao.mensagem = 'Baixando o índice do filme...'
    await priorizarIndice(sessao, arquivo, caminho)

    sessao.mensagem = 'Convertendo o filme...'

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
 * Baixa o trecho do índice (`moov`) antes do resto do arquivo.
 *
 * No caminho não progressivo (MP4 com `moov` no fim) o FFmpeg precisa do índice
 * em disco para montar a timeline. Em vez de esperar o download inteiro,
 * pedimos ao WebTorrent o intervalo exato do `moov` com prioridade máxima e
 * aguardamos só esse trecho. Com o índice presente, a conversão começa do disco
 * e o FFmpeg lê o `mdat` conforme os bytes chegam — o arquivo em disco é
 * buscável, então ele consegue esperar/relê-lo.
 *
 * Se o `moov` não puder ser localizado (arquivo ainda sem bytes suficientes),
 * caímos para a espera do download completo, que é o comportamento antigo.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {string} caminho caminho do arquivo em disco
 */
async function priorizarIndice(sessao, arquivo, caminho) {
  const moov = localizarMoov(caminho, arquivo.length)

  if (!moov) {
    // Sem o índice localizável não há como priorizar: esperamos o download
    // completo, como antes.
    logger.info(`[sessao ${sessao.id}] moov não localizado; aguardando o download completo`)
    await aguardarDownloadCompleto(sessao, arquivo)
    return
  }

  /*
   * `select(prioridade, inicio, fim)` pede ao WebTorrent apenas o intervalo do
   * índice. A prioridade 10 (acima da do arquivo inteiro, que é 1) garante que
   * esses bytes sejam pedidos antes de tudo.
   */
  arquivo.select(10, moov.inicio, moov.fim)

  logger.info(
    `[sessao ${sessao.id}] priorizando o moov (${moov.inicio}–${moov.fim}); convertendo do disco`
  )

  await aguardarTrecho(sessao, arquivo, moov.fim)
}

/**
 * Aguarda o arquivo ter bytes até a posição informada.
 *
 * Usado para esperar o trecho do `moov` chegar ao disco. Verificamos o
 * progresso do arquivo a cada segundo; o download segue em paralelo.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {number} posicao byte até onde precisamos dos dados
 * @param {number} timeoutMs tempo máximo de espera
 */
function aguardarTrecho(sessao, arquivo, posicao, timeoutMs = 120000) {
  const inicio = Date.now()

  return new Promise((resolve, reject) => {
    const verificar = () => {
      // `arquivo.progress` é a fração baixada do arquivo; multiplicada pelo
      // tamanho, dá os bytes disponíveis. Se já cobrem o índice, seguimos.
      const baixado = arquivo.progress * arquivo.length

      if (baixado >= posicao || arquivo.progress >= 1) {
        return resolve()
      }

      if (Date.now() - inicio > timeoutMs) {
        return reject(new Error('Tempo esgotado aguardando o índice do vídeo.'))
      }

      setTimeout(verificar, 1000)
    }

    verificar()
  })
}

/**
 * Aguarda o download completo do arquivo.
 *
 * Reserva do caminho não progressivo, usada quando o `moov` não pôde ser
 * localizado. Tem um teto de tempo para não deixar a sessão presa para sempre
 * caso o download estagne.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {number} timeoutMs tempo máximo de espera
 */
function aguardarDownloadCompleto(sessao, arquivo, timeoutMs = 10 * 60 * 1000) {
  const inicio = Date.now()

  return new Promise((resolve, reject) => {
    const verificar = () => {
      if (arquivo.progress >= 1) {
        return resolve()
      }

      if (Date.now() - inicio > timeoutMs) {
        return reject(new Error('Tempo esgotado aguardando o download do filme.'))
      }

      setTimeout(verificar, 1000)
    }

    verificar()
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
 * Além do percentual, expomos a velocidade e a contagem de peers: sem eles o
 * overlay não distingue uma fonte morta (0 peers) de uma fonte apenas lenta,
 * e o usuário fica sem saber se deve esperar ou trocar de fonte.
 *
 * @param {object} sessao
 * @param {import('webtorrent').Torrent} torrent
 */
function acompanharDownload(sessao, torrent) {
  const atualizar = () => {
    sessao.download = {
      percentual: Math.round(torrent.progress * 100),
      velocidade: torrent.downloadSpeed ?? 0,
      peers: torrent.numPeers ?? 0,
      baixado: torrent.downloaded ?? 0,
    }
  }

  const concluir = () => {
    torrent.off('download', atualizar)
    torrent.off('done', concluir)

    sessao.download = {
      percentual: 100,
      velocidade: 0,
      peers: torrent.numPeers ?? 0,
      baixado: torrent.downloaded ?? 0,
    }
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
 * Aguarda o primeiro byte de dados, falhando rápido se a fonte estiver morta.
 *
 * O evento `ready` do torrent só confirma que os metadados do magnet foram
 * lidos — não que exista peer enviando dados. Sem esta espera, uma fonte sem
 * peers prendia a sessão em `analisarComEspera` por 120 s, muito além do
 * `TIMEOUT_FONTE_MS` do frontend (90 s), deixando a sessão órfã no backend.
 *
 * Resolve assim que qualquer byte chega; rejeita se o tempo esgotar sem
 * tráfego. O frontend então recebe `status: 'erro'` e tenta a próxima fonte.
 *
 * @param {object} sessao
 * @param {import('webtorrent').Torrent} torrent
 */
function aguardarDados(sessao, torrent) {
  return new Promise((resolve, reject) => {
    // Um torrent já completo (ou com dados em disco) não precisa esperar.
    if (torrent.downloaded > 0 || torrent.progress >= 1) {
      resolve()
      return
    }

    let concluido = false

    const finalizar = () => {
      if (concluido) return
      concluido = true
      clearTimeout(temporizador)
      torrent.off('download', aoBaixar)
      resolve()
    }

    const aoBaixar = () => {
      if (torrent.downloaded > 0) {
        finalizar()
      }
    }

    const temporizador = setTimeout(() => {
      if (concluido) return
      concluido = true
      torrent.off('download', aoBaixar)

      reject(
        new Error(
          `A fonte não enviou dados em ${TIMEOUT_DADOS_MS / 1000}s (peers: ${torrent.numPeers ?? 0}).`
        )
      )
    }, TIMEOUT_DADOS_MS)

    torrent.on('download', aoBaixar)

    // O evento `download` só dispara quando chegam bytes; uma verificação
    // imediata cobre o caso de os dados já terem começado antes do listener.
    aoBaixar()
  })
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
    /*
     * O download corre em paralelo à conversão. Além do percentual, expomos a
     * velocidade e a contagem de peers para o overlay distinguir uma fonte
     * morta (0 peers) de uma apenas lenta.
     */
    download: sessao.download ?? null,
    /*
     * Duração total do filme, lida pelo ffprobe. O Plyr não consegue deduzir a
     * duração de uma playlist `EVENT` ainda em crescimento (o hls.js a trata
     * como ao vivo e reporta `Infinity`), então o frontend usa este valor como
     * fonte de verdade enquanto a conversão não termina.
     */
    duracao: sessao.duracao ?? null,
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
 * Testa uma fonte sem criar sessão: conecta o magnet e mede a malha.
 *
 * O backend usa isto para descartar fontes sem peers antes de oferecê-las ao
 * usuário. O `seeds`/`peers` do provedor é estático e pode estar desatualizado;
 * aqui medimos a malha real por alguns segundos.
 *
 * O torrent é removido ao fim do teste para não deixar lixo no cliente — a
 * sessão de reprodução, se houver, adiciona o magnet de novo.
 *
 * @param {string} magnet
 * @param {number} esperaMs tempo de observação da malha
 * @returns {Promise<{ok: boolean, peers: number, seeds: number, velocidade: number}>}
 */
export async function verificarFonte(magnet, esperaMs = 8000) {
  let torrent

  try {
    torrent = await adicionarTorrent(magnet)
  } catch (erro) {
    logger.warn('[verificar] falha ao conectar a fonte:', erro.message)
    return { ok: false, peers: 0, seeds: 0, velocidade: 0 }
  }

  // Observamos a malha por alguns segundos: o número de peers sobe conforme as
  // conexões se estabelecem, então uma leitura imediata subestimaria a fonte.
  await new Promise((resolve) => setTimeout(resolve, esperaMs))

  const peers = torrent.numPeers ?? 0
  const velocidade = torrent.downloadSpeed ?? 0
  const baixou = (torrent.downloaded ?? 0) > 0

  // Consideramos a fonte viva se há peers e algum tráfego (ou se já baixou).
  const ok = peers > 0 && (velocidade > 0 || baixou)

  const resultado = { ok, peers, seeds: peers, velocidade }

  // O teste não deve deixar o torrent vivo: a sessão de reprodução o adiciona
  // de novo quando o usuário escolhe a fonte.
  try {
    cliente.remove(torrent, { destroyStore: true })
  } catch (erro) {
    logger.warn('[verificar] falha ao remover o torrent de teste:', erro.message)
  }

  return resultado
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
