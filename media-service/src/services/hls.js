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
 * Em todos os casos o FFmpeg publica os segmentos conforme os produz, então o
 * player começa a tocar antes de a conversão terminar.
 */

/** Codecs de vídeo que o navegador toca sem transcodificar. */
const VIDEO_COMPATIVEIS = ['h264', 'avc1', 'avc']

/** Codecs de áudio que o navegador toca sem transcodificar. */
const AUDIO_COMPATIVEIS = ['aac', 'mp3']

/** Duração de cada segmento HLS, em segundos. */
const DURACAO_SEGMENTO = 4

/**
 * Quantos segmentos a playlist mantém disponíveis. Uma janela maior dá folga
 * para conexões lentas sem acumular o filme inteiro em disco.
 */
const SEGMENTOS_NA_JANELA = 10

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
 * Inicia a conversão para HLS, publicando os segmentos conforme ficam prontos.
 *
 * @param {object} opcoes
 * @param {string} opcoes.arquivo caminho do arquivo de vídeo
 * @param {string} opcoes.diretorio pasta onde a playlist e os segmentos serão escritos
 * @param {string} opcoes.modo modo de conversão (`remux`, `audio` ou `video`)
 * @param {(progresso: object) => void} [opcoes.aoProgredir] callback de progresso
 * @returns {import('fluent-ffmpeg').FfmpegCommand}
 */
export function iniciarConversao({ arquivo, diretorio, modo, aoProgredir }) {
  fs.mkdirSync(diretorio, { recursive: true })

  const playlist = path.join(diretorio, 'playlist.m3u8')
  const padraoSegmento = path.join(diretorio, 'segmento-%d.ts')

  const comando = ffmpeg(arquivo)

  aplicarModo(comando, modo)

  comando
    .outputOptions([
      // HLS com segmentos curtos: o player começa antes de o filme inteiro
      // estar convertido, que é o ponto do streaming em tempo real.
      '-f hls',
      `-hls_time ${DURACAO_SEGMENTO}`,
      `-hls_list_size ${SEGMENTOS_NA_JANELA}`,
      // `event` mantém todos os segmentos na playlist, permitindo voltar no
      // filme. Por isso não usamos `delete_segments`: ele apagaria justamente
      // os trechos já assistidos, quebrando o retrocesso do player.
      '-hls_flags append_list',
      '-hls_segment_type mpegts',
      `-hls_segment_filename ${padraoSegmento}`,
      // Sem isso o FFmpeg espera o arquivo inteiro para escrever o primeiro
      // segmento, o que anula o propósito do streaming ao vivo.
      '-hls_playlist_type event',
      '-movflags +faststart',
    ])
    .output(playlist)
    .on('start', (linha) => logger.info('[hls] conversão iniciada:', linha))
    .on('progress', (progresso) => {
      aoProgredir?.({
        percentual: progresso.percent ?? null,
        tempoProcessado: progresso.timemark ?? null,
      })
    })
    .on('error', (erro) => logger.error('[hls] falha na conversão:', erro.message))
    .on('end', () => logger.info('[hls] conversão concluída'))
    .run()

  return comando
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
