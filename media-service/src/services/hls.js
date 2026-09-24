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
 * O FFmpeg lê o arquivo do torrent como um arquivo local comum, então ele
 * avança até a "fronteira" do que já foi baixado e aborta com erro. Isso não é
 * uma falha real: é o comportamento esperado de um download em andamento. Por
 * isso a conversão é supervisionada — quando o FFmpeg para por falta de dados,
 * esperamos o torrent avançar e retomamos de onde paramos, anexando os novos
 * segmentos à mesma playlist.
 *
 * @param {object} opcoes
 * @param {string} opcoes.arquivo caminho do arquivo de vídeo
 * @param {string} opcoes.diretorio pasta onde a playlist e os segmentos serão escritos
 * @param {string} opcoes.modo modo de conversão (`remux`, `audio` ou `video`)
 * @param {() => number} [opcoes.progressoDownload] progresso do torrent (0 a 1)
 * @param {(progresso: object) => void} [opcoes.aoProgredir] callback de progresso
 * @returns {{parar: () => void}}
 */
export function iniciarConversao({ arquivo, diretorio, modo, progressoDownload, aoProgredir }) {
  fs.mkdirSync(diretorio, { recursive: true })

  const playlist = path.join(diretorio, 'playlist.m3u8')
  const padraoSegmento = path.join(diretorio, 'segmento-%d.ts')

  const controle = { parado: false, comando: null, inicioSegmento: 0 }

  /**
   * Executa uma passada do FFmpeg a partir de `inicioSegmento` segundos.
   *
   * A primeira passada cria a playlist; as de retomada usam `-hls_flags
   * append_list` para continuar a numeração dos segmentos sem sobrescrever os
   * anteriores.
   */
  const executarPassada = () => {
    if (controle.parado) return

    const comando = ffmpeg(arquivo)
    const retomando = controle.inicioSegmento > 0

    // Retomar exige buscar no ponto exato onde a passada anterior parou.
    if (retomando) {
      comando.inputOptions([`-ss ${controle.inicioSegmento}`])
    }

    aplicarModo(comando, modo)

    const opcoes = [
      // HLS com segmentos curtos: o player começa antes de o filme inteiro
      // estar convertido, que é o ponto do streaming em tempo real.
      '-f hls',
      `-hls_time ${DURACAO_SEGMENTO}`,
      `-hls_list_size ${SEGMENTOS_NA_JANELA}`,
      '-hls_segment_type mpegts',
      `-hls_segment_filename ${padraoSegmento}`,
      // `event` mantém todos os segmentos na playlist e faz o FFmpeg declarar
      // `#EXT-X-PLAYLIST-TYPE:EVENT`. Sem isso o hls.js enxerga a playlist como
      // transmissão ao vivo e nunca libera a reprodução.
      '-hls_playlist_type event',
      '-movflags +faststart',
    ]

    /*
     * `append_list` só faz sentido na retomada: ela manda o FFmpeg continuar a
     * numeração dentro de uma playlist já existente. Na primeira passada, a
     * flag impedia o FFmpeg de escrever o cabeçalho da playlist corretamente e
     * a deixava sem `#EXT-X-ENDLIST`, o que prendia o player em buffering.
     */
    if (retomando) {
      opcoes.push('-hls_flags append_list')
    }

    comando
      .outputOptions(opcoes)
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

        logger.warn('[hls] passada interrompida:', erro.message)

        // O FFmpeg parou porque alcançou o fim dos dados baixados. Avançamos o
        // ponto de retomada para o último segmento produzido e esperamos o
        // torrent baixar mais antes de tentar de novo.
        controle.inicioSegmento = ultimoSegmentoEmSegundos(playlist, controle.inicioSegmento)

        aguardarMaisDados(progressoDownload)
          .then(executarPassada)
          .catch((motivo) => logger.error('[hls] conversão abandonada:', motivo.message))
      })
      .on('end', () => {
        logger.info('[hls] conversão concluída')
        // A playlist só é considerada completa quando declara o fim. Sem essa
        // tag o hls.js continua esperando segmentos que nunca virão.
        finalizarPlaylist(playlist)
      })

    controle.comando = comando
    comando.run()
  }

  executarPassada()

  return {
    parar: () => {
      controle.parado = true
      try {
        controle.comando?.kill('SIGKILL')
      } catch {
        // O processo já pode ter terminado sozinho.
      }
    },
  }
}

/**
 * Descobre até que segundo da linha do tempo a conversão já produziu segmentos.
 *
 * Cada segmento tem duração fixa, então a quantidade de `.ts` na playlist dá o
 * ponto exato de retomada. Usamos o maior valor entre o que já sabíamos e o que
 * a playlist mostra, para nunca retroceder.
 */
function ultimoSegmentoEmSegundos(playlist, atual) {
  try {
    const conteudo = fs.readFileSync(playlist, 'utf8')
    const segmentos = (conteudo.match(/\.ts/g) ?? []).length

    return Math.max(atual, segmentos * DURACAO_SEGMENTO)
  } catch {
    return atual
  }
}

/**
 * Anexa `#EXT-X-ENDLIST` à playlist, declarando que não virão mais segmentos.
 *
 * O FFmpeg não escreve essa tag sozinho quando a conversão termina em uma
 * passada de retomada. Sem ela, o hls.js trata a playlist como transmissão ao
 * vivo e fica em buffering indefinidamente.
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
 * Espera o torrent baixar mais dados antes de retomar a conversão.
 *
 * Se o download já terminou, não há o que esperar: devolvemos na hora para que
 * a última passada processe o restante do arquivo.
 */
function aguardarMaisDados(progressoDownload, timeoutMs = 120000) {
  const inicio = Date.now()
  const progressoInicial = progressoDownload?.() ?? 0

  return new Promise((resolve, reject) => {
    const verificar = () => {
      const progresso = progressoDownload?.() ?? 1

      /*
       * Exigir 1% de avanço é rígido demais para torrents lentos: um arquivo de
       * vários GB precisa de dezenas de MB para cruzar essa marca, e a conversão
       * ficava parada esperando. Aceitamos também um avanço absoluto pequeno,
       * que já dá ao FFmpeg o que processar.
       */
      const avancouPercentual = progresso > progressoInicial + 0.01
      const avancouAbsoluto = progresso - progressoInicial >= 0.0005

      if (progresso >= 1 || avancouPercentual || avancouAbsoluto) {
        return resolve(true)
      }

      if (Date.now() - inicio > timeoutMs) {
        return reject(new Error('O download estagnou e a conversão não pôde continuar.'))
      }

      setTimeout(verificar, 500)
    }

    verificar()
  })
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
