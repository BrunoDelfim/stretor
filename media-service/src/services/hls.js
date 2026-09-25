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
 * A entrada é um **fluxo** alimentado pelo torrent, e o pipe fica aberto até o
 * download terminar. Assim o FFmpeg publica cada segmento conforme os bytes
 * chegam, em vez de esperar o arquivo inteiro — é o que permite começar a
 * assistir com o download ainda em andamento.
 *
 * O que decide se isso funciona é a posição do índice do contêiner:
 *
 * - MKV/WebM e MP4 *faststart* trazem o índice no começo e fluem pelo pipe sem
 *   problema (confirmado em teste: os segmentos crescem junto com os dados).
 * - MP4 comum traz o `moov` no **fim**; num pipe não há como voltar para lê-lo
 *   depois de atravessar o `mdat`, e o FFmpeg aborta com `partial file`. Nesses
 *   casos o chamador precisa garantir que o `moov` já esteja em disco antes de
 *   abrir o pipe — responsabilidade de `sessoes.js`.
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
 * Declarar o formato evita que a sondagem automática erre o contêiner em
 * arquivos com extensão enganosa (comum em encodes caseiros). Num pipe a
 * sondagem tem menos contexto, então informar o formato é ainda mais útil.
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
 * Além dos codecs, devolvemos a duração total. O Plyr não consegue deduzi-la de
 * uma playlist `EVENT` em crescimento — o hls.js a trata como transmissão ao
 * vivo e reporta `Infinity` — então o frontend usa este valor como fonte de
 * verdade enquanto a conversão não termina.
 *
 * @param {string} arquivo caminho do arquivo de vídeo dentro do torrent
 * @returns {Promise<{modo: string, videoCodec: string, audioCodec: string, duracao: number|null}>}
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

      // `format.duration` é a duração do contêiner inteiro; é mais confiável
      // que a duração de um stream isolado quando há faixas de tamanhos
      // diferentes. Convertemos para número porque o ffprobe devolve string.
      const duracao = Number.parseFloat(metadados?.format?.duration ?? '')

      resolve({
        modo: decidirModo(videoCodec, audioCodec),
        videoCodec,
        audioCodec,
        duracao: Number.isFinite(duracao) ? duracao : null,
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
 * Converte um vídeo para HLS, publicando os segmentos conforme ficam prontos.
 *
 * A entrada pode ser um **fluxo** (alimentado pelo torrent, mantido aberto até o
 * download terminar) ou um **caminho** em disco. O fluxo é o caminho rápido:
 * o FFmpeg consome os bytes na medida em que chegam e o player começa antes do
 * fim do download. Só funciona quando o índice do contêiner está no começo
 * (MKV/WebM, MP4 *faststart*). Para MP4 com o `moov` no fim é preciso passar o
 * caminho, com o arquivo inteiro já em disco.
 *
 * @param {object} opcoes
 * @param {import('node:stream').Readable} [opcoes.fluxo] fluxo do arquivo de vídeo
 * @param {string} [opcoes.caminho] caminho do arquivo em disco (alternativa ao fluxo)
 * @param {string} opcoes.extensao extensão do arquivo (define o formato de entrada)
 * @param {string} opcoes.diretorio pasta onde a playlist e os segmentos serão escritos
 * @param {string} opcoes.modo modo de conversão (`remux`, `audio` ou `video`)
 * @param {(progresso: object) => void} [opcoes.aoProgredir] callback de progresso
 * @returns {{parar: () => void}}
 */
export function iniciarConversao({ fluxo, caminho, extensao, diretorio, modo, aoProgredir }) {
  fs.mkdirSync(diretorio, { recursive: true })

  const playlist = path.join(diretorio, 'playlist.m3u8')
  const padraoSegmento = path.join(diretorio, 'segmento-%d.ts')

  const controle = { parado: false, comando: null }

  const comando = ffmpeg(fluxo ?? caminho)

  /*
   * Num pipe não há nome de arquivo para o FFmpeg deduzir o contêiner, então
   * declaramos o formato a partir da extensão. Sem isso a sondagem pode errar
   * encodes caseiros com extensão enganosa.
   */
  const formato = FORMATOS_CONTAINER[extensao?.toLowerCase()]

  if (formato) {
    comando.inputFormat(formato)
  }

  /*
   * Normalizamos os timestamps porque encodes antigos trazem um start time
   * diferente de zero e o `#EXTINF` da playlist precisa bater com os PTS reais,
   * senão o hls.js trava no MSE (anexa o buffer, mas o playhead não avança).
   *
   * `+genpts` gera PTS monotônicos. NÃO usamos `-copyts` nem `-start_at_zero`:
   * eles preservam os timestamps de origem, mas o muxer HLS então corta os
   * segmentos em pontos que não coincidem com os keyframes e os *parameter sets*
   * (SPS/PPS) do H.264 acabam no segmento errado — o decodificador acusa
   * `non-existing PPS 0` e nenhum frame sai. O `-avoid_negative_ts make_zero`
   * sozinho já ancora a timeline em zero sem mexer no alinhamento dos segmentos.
   */
  comando.inputOptions(['-fflags +genpts'])

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
      // Ancora a timeline em zero sem deslocar os cortes dos segmentos.
      '-avoid_negative_ts make_zero',
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

      // Um erro aqui é real (arquivo corrompido, codec sem suporte). Registramos
      // e a sessão falha, deixando o cliente tentar a próxima fonte.
      logger.error('[hls] conversão interrompida:', erro.message)
    })
    .on('end', () => {
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

      try {
        controle.comando?.kill('SIGKILL')
      } catch {
        // O processo já pode ter terminado sozinho.
      }
    },
  }
}

/**
 * Finaliza a playlist: troca `EVENT` por `VOD` e anexa `#EXT-X-ENDLIST`.
 *
 * Enquanto a conversão corre, a playlist é `EVENT` e cresce — o hls.js a trata
 * como transmissão ao vivo e reporta duração `Infinity`, o que impede o Plyr de
 * mostrar o tempo total e mover a barra. Ao terminar, declaramos `VOD` com
 * `#EXT-X-ENDLIST`: o hls.js passa a enxergar uma playlist completa, calcula a
 * duração a partir dos `#EXTINF` e o Plyr a exibe sozinho — sem nenhuma
 * manipulação do player.
 */
function finalizarPlaylist(playlist) {
  try {
    let conteudo = fs.readFileSync(playlist, 'utf8')

    if (conteudo.includes('#EXT-X-ENDLIST')) return

    // A playlist do FFmpeg traz `#EXT-X-PLAYLIST-TYPE:EVENT`. Trocamos por
    // `VOD` para o hls.js tratá-la como conteúdo completo, não ao vivo.
    conteudo = conteudo.replace(
      '#EXT-X-PLAYLIST-TYPE:EVENT',
      '#EXT-X-PLAYLIST-TYPE:VOD'
    )

    conteudo = `${conteudo.trimEnd()}\n#EXT-X-ENDLIST\n`

    fs.writeFileSync(playlist, conteudo)
    logger.info('[hls] playlist finalizada como VOD com #EXT-X-ENDLIST')
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
 * Como a conversão agora corre junto com o download, o buffer precisa cobrir o
 * tempo de baixar e converter o próximo trecho. Numa conexão de 4 Mbps
 * (~500 KB/s) e segmentos de 4 s, 8 segmentos (~32 s de vídeo) dão folga
 * confortável para a reprodução não alcançar a conversão.
 *
 * @param {string} diretorio pasta da sessão
 * @param {number} minimoSegmentos quantidade mínima de segmentos prontos
 * @param {number} timeoutMs tempo máximo de espera
 */
export function aguardarBufferInicial(diretorio, minimoSegmentos = 8, timeoutMs = 180000) {
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

/**
 * Descobre a posição do átomo `moov` num MP4/MOV.
 *
 * Percorremos a lista de átomos do topo do arquivo (`ftyp`, `free`, `mdat`,
 * `moov`...) somando os tamanhos até encontrar o `moov`. O que interessa é
 * saber se ele está no começo (faststart, flui pelo pipe) ou no fim (precisa
 * ser baixado antes de abrir o pipe).
 *
 * Devolve `null` quando não é possível determinar — arquivo ainda incompleto,
 * contêiner diferente de MP4, ou átomo com tamanho de 64 bits que não
 * conseguimos ler. Nesse caso o chamador assume o pior cenário (esperar o
 * índice) para não arriscar uma playlist vazia.
 *
 * @param {string} caminho caminho do arquivo em disco
 * @param {number} tamanhoArquivo tamanho total do arquivo em bytes
 * @returns {{inicio: number, fim: number} | null}
 */
export function localizarMoov(caminho, tamanhoArquivo) {
  let fd

  try {
    fd = fs.openSync(caminho, 'r')

    const cabecalho = Buffer.alloc(16)
    let posicao = 0

    while (posicao < tamanhoArquivo) {
      const lidos = fs.readSync(fd, cabecalho, 0, 16, posicao)

      if (lidos < 8) return null

      let tamanho = cabecalho.readUInt32BE(0)
      const tipo = cabecalho.toString('latin1', 4, 8)

      // Tamanho 1 indica um campo de 64 bits logo após o tipo.
      if (tamanho === 1) {
        if (lidos < 16) return null
        const alto = cabecalho.readUInt32BE(8)
        const baixo = cabecalho.readUInt32BE(12)
        tamanho = alto * 2 ** 32 + baixo
      }

      // Tamanho 0 significa "até o fim do arquivo".
      if (tamanho === 0) {
        tamanho = tamanhoArquivo - posicao
      }

      if (tipo === 'moov') {
        return { inicio: posicao, fim: posicao + tamanho }
      }

      // Um tamanho absurdo indica que lemos lixo (arquivo incompleto).
      if (tamanho < 8 || posicao + tamanho > tamanhoArquivo) {
        return null
      }

      posicao += tamanho
    }

    return null
  } catch {
    return null
  } finally {
    if (fd !== undefined) {
      try {
        fs.closeSync(fd)
      } catch {
        // O descritor já pode ter sido fechado.
      }
    }
  }
}
