import WebTorrent from 'webtorrent'
import path from 'node:path'
import fs from 'node:fs'
import os from 'node:os'
import { v4 as uuid } from 'uuid'

import { analisarArquivo, iniciarConversao, aguardarBufferInicial } from './hls.js'
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
 * Conecta o torrent, aguarda o arquivo e inicia a conversão.
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

  // O FFmpeg precisa de dados no disco para começar. Priorizamos o arquivo de
  // vídeo para que o WebTorrent baixe primeiro o que interessa.
  arquivo.select()

  await aguardarDadosIniciais(arquivo)

  sessao.status = 'convertendo'
  sessao.mensagem = 'Preparando a conversão...'

  // `arquivo.path` é relativo à pasta do torrent (ex.: "Filme (1999)/filme.mp4"),
  // não ao sistema de arquivos. Sem juntar com `torrent.path`, o FFmpeg procura
  // o arquivo no diretório de trabalho do processo e falha com "No such file".
  const caminho = path.join(torrent.path, arquivo.path)
  const analise = await analisarArquivo(caminho)

  logger.info(
    `[sessao ${sessao.id}] modo=${analise.modo} video=${analise.videoCodec} audio=${analise.audioCodec}`
  )

  sessao.mensagem = mensagemDoModo(analise.modo)

  sessao.comando = iniciarConversao({
    arquivo: caminho,
    diretorio: sessao.diretorio,
    modo: analise.modo,
    aoProgredir: (progresso) => {
      sessao.progresso = progresso
    },
  })

  // Só liberamos o player quando há segmentos suficientes para tocar sem
  // travar — é o buffer que protege conexões lentas.
  await aguardarBufferInicial(sessao.diretorio)

  sessao.status = 'pronto'
  sessao.mensagem = 'Pronto para reproduzir'
  sessao.playlist = path.join(sessao.diretorio, 'playlist.m3u8')
}

/**
 * Adiciona o magnet ao cliente e resolve quando o torrent estiver pronto.
 *
 * @param {string} magnet
 * @returns {Promise<import('webtorrent').Torrent>}
 */
function adicionarTorrent(magnet) {
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
 * Aguarda o arquivo ter dados suficientes para o FFmpeg começar a ler.
 *
 * @param {import('webtorrent').TorrentFile} arquivo
 */
function aguardarDadosIniciais(arquivo, minimoBytes = 5 * 1024 * 1024, timeoutMs = 60000) {
  const inicio = Date.now()

  return new Promise((resolve, reject) => {
    const verificar = () => {
      if (arquivo.downloaded >= minimoBytes) {
        return resolve(true)
      }

      if (Date.now() - inicio > timeoutMs) {
        return reject(new Error('Tempo esgotado aguardando dados da fonte.'))
      }

      setTimeout(verificar, 500)
    }

    verificar()
  })
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
    sessao.comando?.kill('SIGKILL')
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
