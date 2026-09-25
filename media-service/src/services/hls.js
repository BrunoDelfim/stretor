import ffmpeg from 'fluent-ffmpeg'
import path from 'node:path'
import fs from 'node:fs'

import { logger } from '../utils/logger.js'

/**
 * Pipeline de conversão para HLS.
 *
 * O navegador não toca MKV com as faixas típicas de torrent (H.264/HEVC +
 * AC3/DTS), então a conversão acontece aqui. A escolha do modo é o que separa
 * uma reprodução instantânea de uma que trava a CPU:
 *
 * - `remux`   — vídeo e áudio já são compatíveis; só troca o contêiner para HLS.
 * - `audio`   — o vídeo serve, mas o áudio não; converte só o áudio para AAC.
 * - `video`   — o codec de vídeo é incompatível; transcodifica o vídeo.
 *
 * A entrada é o **fluxo** do torrent, não o arquivo em disco. Ler o arquivo
 * parcial fazia o FFmpeg atravessar as regiões ainda não baixadas (que o disco
 * devolve como zeros) em altíssima velocidade, morrer na primeira lacuna e ser
 * retomado com `-ss`. Cada retomada reiniciava os timestamps e começava fora de
 * um keyframe, produzindo segmentos sem IDR — o meio do filme ficava ilegível e a
 * playlist acumulava numeração inconsistente.
 *
 * Consumindo `arquivo.createReadStream()`, o WebTorrent entrega os bytes em ordem
 * e **bloqueia** até a peça necessária baixar. O FFmpeg enxerga um fluxo
 * contínuo, com timestamps monotônicos, e publica os segmentos conforme o
 * download avança — uma única passada, sem lacunas e sem retomadas.
 */

/** Codecs de vídeo que o navegador toca sem transcodificar. */
const VIDEO_COMPATIVEIS = ['h264', 'avc1', 'avc']

/** Codecs de áudio que o navegador toca sem transcodificar. */
const AUDIO_COMPATIVEIS = ['aac', 'mp3']

/** Duração desejada de cada segmento HLS, em segundos. */
const DURACAO_SEGMENTO = 4

/**
 * Formato de entrada declarado ao FFmpeg conforme a extensão do arquivo.
 *
 * Lendo de um pipe o FFmpeg não consegue buscar, então declarar o formato evita
 * que a sondagem automática dependa de índices que vivem no fim do arquivo. Os
 * contêineres `faststart` — o padrão das fontes de torrent — trazem o cabeçalho
 * no início e funcionam bem deste jeito.
 */
const FORMATOS_CONTAINER = {
  '.mp4': 'mp4',
  '.m4v': 'mp4',
  '.mov': 'mov',
  '.mkv': 'matroska',
  '.webm': 'matroska',
  '.avi': 'avi',
}

/**
 * Extrai os metadados do arquivo para decidir o modo de conversão.
 *
 * @param {string} arquivo caminho do arquivo de vídeo dentro do torrent
 * @returns {Promise<{modo: string, videoCodec: string, audioCodec: string}>}
 */
export function analisarArquivo(arquivo) {
  return new Promise((resolve, reject) => {
    ffmpeg.ffprobe(arquivo, (erro, metadados) => {
      if (erro) return reject(erro)

      const streams = metadados?.streams ?? []
      const video = streams.find((s) => s.codec_type === 'video')
      const audio = streams.find((s) => s.codec_type === 'audio')

      const videoCodec = (video?.codec_name ?? '').toLowerCase()
      const audioCodec = (audio?.codec_name ?? '').toLowerCase()

      resolve({
        modo: decidirModo(videoCodec, audioCodec),
        videoCodec,
        audioCodec,
      })
    })
  })
}

/**
 * Decide o modo de conversão a partir dos codecs encontrados.
 *
 * A ordem de verificação vai do mais barato para o mais caro: se o vídeo já é
 * compatível, nunca transcodificamos o vídeo — no máximo o áudio.
 */
export function decidirModo(videoCodec, audioCodec) {
  const videoOk = VIDEO_COMPATIVEIS.includes(videoCodec)
  const audioOk = AUDIO_COMPATIVEIS.includes(audioCodec)

  if (videoOk && audioOk) return 'remux'
  if (videoOk) return 'audio'

  return 'video'
}

/**
 * Converte o fluxo do torrent para HLS, publicando os segmentos conforme ficam
 * prontos.
 *
 * @param {object} opcoes
 * @param {import('webtorrent').TorrentFile} opcoes.arquivo arquivo do torrent
 * @param {string} opcoes.diretorio pasta onde a playlist e os segmentos serão escritos
 * @param {string} opcoes.modo modo de conversão (`remux`, `audio` ou `video`)
 * @param {(progresso: object) => void} [opcoes.aoProgredir] callback de progresso
 * @returns {{parar: () => void}}
 */
export function iniciarConversao({ arquivo, diretorio, modo, aoProgredir }) {
  fs.mkdirSync(diretorio, { recursive: true })

  const playlist = path.join(diretorio, 'playlist.m3u8')
  const padraoSegmento = path.join(diretorio, 'segmento-%d.ts')

  const controle = { parado: false, comando: null, fluxo: null, truncado: false }

  /*
   * O read stream do WebTorrent espera as peças que faltam em vez de devolver
   * zeros. É ele que transforma um download parcial num fluxo contínuo para o
   * FFmpeg — e o que dispensa toda a lógica de retomada com `-ss`.
   */
  const fluxo = arquivo.createReadStream()
  controle.fluxo = fluxo

  // Sem um listener, um erro do fluxo (torrent destruído, disco cheio) subiria
  // como exceção não tratada e derrubaria o processo inteiro.
  fluxo.on('error', (erro) => {
    if (controle.parado) return

    controle.truncado = true
    logger.error('[hls] falha no fluxo do torrent:', erro.message)
  })

  const comando = ffmpeg(fluxo)

  const formato = FORMATOS_CONTAINER[path.extname(arquivo.name ?? '').toLowerCase()]

  if (formato) {
    comando.inputFormat(formato)
  }

  aplicarModo(comando, modo)

  comando
    .outputOptions([
      '-f hls',
      `-hls_time ${DURACAO_SEGMENTO}`,
      /*
       * `-hls_list_size 0` mantém todos os segmentos na playlist — o fixo que o
       * `event` já implica, mas declarado para não depender dessa regra.
       */
      '-hls_list_size 0',
      '-hls_segment_type mpegts',
      `-hls_segment_filename ${padraoSegmento}`,
      /*
       * `event` faz o FFmpeg declarar `#EXT-X-PLAYLIST-TYPE:EVENT`. É o que
       * permite ao player acompanhar uma playlist que ainda está crescendo, em
       * vez de enxergá-la como VOD e parar no último segmento disponível quando
       * ela foi carregada.
       */
      '-hls_playlist_type event',
    ])
    .output(playlist)
    .on('start', (linha) => logger.info('[hls] conversão iniciada:', linha))
    .on('progress', (progresso) => {
      aoProgredir?.({
        percentual: progresso.percent ?? null,
        tempoProcessado: progresso.timemark ?? null,
      })
    })
    .on('error', (erro) => {
      if (controle.parado) return

      // Com o fluxo do torrent não existe "fronteira de download" para tratar:
      // um erro aqui é real (contêiner sem cabeçalho no início, arquivo
      // corrompido). Registramos e a sessão falha, deixando o cliente tentar a
      // próxima fonte.
      logger.error('[hls] conversão interrompida:', erro.message)
    })
    .on('end', () => {
      if (controle.truncado) {
        // O fluxo morreu no meio: marcar a playlist como completa faria o
        // player acreditar que recebeu o filme inteiro.
        logger.warn('[hls] conversão encerrada com o fluxo incompleto; playlist não finalizada')
        return
      }

      logger.info('[hls] conversão concluída')
      // A playlist só é considerada completa quando declara o fim. Sem essa tag
      // o hls.js continua esperando segmentos que nunca virão.
      finalizarPlaylist(playlist)
    })

  controle.comando = comando
  comando.run()

  return {
    parar: () => {
      controle.parado = true

      // Fechar o fluxo primeiro destrava qualquer leitura pendente do FFmpeg que
      // esteja esperando uma peça do torrent terminar de baixar.
      try {
        controle.fluxo?.destroy()
      } catch {
        // O fluxo já pode ter terminado.
      }

      try {
        controle.comando?.kill('SIGKILL')
      } catch {
        // O processo já pode ter terminado sozinho.
      }
    },
  }
}

/**
 * Anexa `#EXT-X-ENDLIST` à playlist, declarando que não virão mais segmentos.
 */
function finalizarPlaylist(playlist) {
  try {
    const conteudo = fs.readFileSync(playlist, 'utf8')

    if (conteudo.includes('#EXT-X-ENDLIST')) return

    fs.appendFileSync(playlist, '\n#EXT-X-ENDLIST\n')
    logger.info('[hls] playlist finalizada com #EXT-X-ENDLIST')
  } catch (erro) {
    logger.warn('[hls] falha ao finalizar a playlist:', erro.message)
  }
}

/**
 * Aplica as opções de codec conforme o modo escolhido.
 *
 * No remux usamos `-c copy` (custo de CPU quase zero). No modo áudio copiamos o
 * vídeo e convertemos só o áudio. No modo vídeo, transcodificamos ambos.
 */
function aplicarModo(comando, modo) {
  if (modo === 'remux') {
    comando.videoCodec('copy').audioCodec('copy')
    return
  }

  if (modo === 'audio') {
    comando.videoCodec('copy').audioCodec('aac').audioBitrate('192k')
    return
  }

  // Modo vídeo: o mais pesado. O preset veryfast prioriza a fluidez do
  // streaming em tempo real sobre a compressão máxima.
  comando
    .videoCodec('libx264')
    .audioCodec('aac')
    .audioBitrate('192k')
    .outputOptions(['-preset veryfast', '-crf 23', '-profile:v main', '-level 4.0'])
}

/**
 * Aguarda a playlist existir e ter segmentos suficientes para o player começar
 * sem travar — o buffer inicial que protege conexões lentas.
 *
 * @param {string} diretorio pasta da sessão
 * @param {number} minimoSegmentos quantidade mínima de segmentos prontos
 * @param {number} timeoutMs tempo máximo de espera
 */
export function aguardarBufferInicial(diretorio, minimoSegmentos = 2, timeoutMs = 60000) {
  const playlist = path.join(diretorio, 'playlist.m3u8')
  const inicio = Date.now()

  return new Promise((resolve, reject) => {
    const verificar = () => {
      if (fs.existsSync(playlist)) {
        const conteudo = fs.readFileSync(playlist, 'utf8')
        const segmentos = (conteudo.match(/\.ts/g) ?? []).length

        if (segmentos >= minimoSegmentos) {
          return resolve(true)
        }
      }

      if (Date.now() - inicio > timeoutMs) {
        return reject(new Error('Tempo esgotado aguardando o buffer inicial da conversão.'))
      }

      setTimeout(verificar, 500)
    }

    verificar()
  })
}
