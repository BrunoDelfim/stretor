import ffmpeg from 'fluent-ffmpeg'
import path from 'node:path'
import fs from 'node:fs'
import { spawn } from 'node:child_process'

import { logger } from '../utils/logger.js'
import { descreverIdioma } from '../utils/idiomas.js'

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
 * Intervalo máximo entre keyframes, em segundos, que ainda toleramos no remux.
 *
 * No modo `remux` usamos `-c copy` e o muxer HLS só corta em keyframes. Se o
 * encode de origem tem keyframes a cada ~10 s, saem segmentos de 10.4 s
 * intercalados com outros de 0.9 s: o `#EXT-X-TARGETDURATION` infla e o
 * `liveSyncPosition` do hls.js fica imprevisível, fazendo o player saltar.
 *
 * Quando o intervalo medido passa deste limite, preferimos transcodificar o
 * vídeo (`video`) — mais caro, mas com `-force_key_frames` produz segmentos
 * uniformes. O limite é folgado em relação a `DURACAO_SEGMENTO` para não
 * descartar encodes que já têm keyframes próximos do ideal.
 */
const INTERVALO_KEYFRAME_MAXIMO = 6

/**
 * Quantos keyframes amostrar ao medir o intervalo.
 *
 * Ler o arquivo inteiro em busca de keyframes custaria caro num torrent de
 * vários GB. Uma amostra dos primeiros keyframes já revela o padrão do encode,
 * que é regular por natureza.
 */
const KEYFRAMES_AMOSTRADOS = 12

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
 * @returns {Promise<{modo: string, videoCodec: string, audioCodec: string, duracao: number|null, inicioFonte: number|null, idiomaAudio: string|null, idiomaAudioRotulo: string|null, idiomasAudio: Array<object>}>}
 */
export async function analisarArquivo(arquivo) {
  const metadados = await new Promise((resolve, reject) => {
    ffmpeg.ffprobe(arquivo, (erro, dados) => {
      if (erro) return reject(erro)

      resolve(dados)
    })
  })

  const streams = metadados?.streams ?? []
  const video = streams.find((s) => s.codec_type === 'video')
  const audio = streams.find((s) => s.codec_type === 'audio')

  const videoCodec = (video?.codec_name ?? '').toLowerCase()
  const audioCodec = (audio?.codec_name ?? '').toLowerCase()

  /*
   * O idioma da faixa de áudio vive nas tags do contêiner e é a única prova de
   * que a dublagem existe: o nome do arquivo promete "Dublado", mas quem diz o
   * que está lá dentro é a faixa. Registramos todas porque um lançamento
   * "dual áudio" traz duas ou mais, e é a soma delas que confirma a promessa.
   */
  const faixasAudio = streams.filter((s) => s.codec_type === 'audio').map(descreverFaixaAudio)
  const faixaPadrao = faixasAudio.find((faixa) => faixa.padrao) ?? faixasAudio[0] ?? null

  // `format.duration` é a duração do contêiner inteiro; é mais confiável
  // que a duração de um stream isolado quando há faixas de tamanhos
  // diferentes. Convertemos para número porque o ffprobe devolve string.
  const duracao = Number.parseFloat(metadados?.format?.duration ?? '')

  /*
   * `start_time` é onde o contêiner diz que a timeline começa. Num encode
   * saudável é zero ou alguns milissegundos. Um valor alto significa que o
   * arquivo foi montado com deslocamento (ou já é um recorte), e como o muxer
   * HLS ancora a saída com `-avoid_negative_ts make_zero`, esse deslocamento
   * desaparece: o filme passa a "começar" em 00:00 num ponto que não é o início
   * real. Registramos o valor para conseguir enxergar esse caso nos logs.
   */
  const inicioFonte = Number.parseFloat(metadados?.format?.start_time ?? '')

  /*
   * Só medimos os keyframes quando o vídeo é compatível — é o único cenário em
   * que a decisão entre `remux` e `video` depende disso. Nos demais o codec já
   * determina o modo e a varredura seria trabalho desperdiçado.
   */
  const videoOk = VIDEO_COMPATIVEIS.includes(videoCodec)
  const intervaloKeyframes = videoOk ? await medirIntervaloKeyframes(arquivo) : null

  return {
    modo: decidirModo(videoCodec, audioCodec, intervaloKeyframes),
    videoCodec,
    audioCodec,
    intervaloKeyframes,
    duracao: Number.isFinite(duracao) ? duracao : null,
    inicioFonte: Number.isFinite(inicioFonte) ? inicioFonte : null,
    idiomaAudio: faixaPadrao?.codigo ?? null,
    idiomaAudioRotulo: faixaPadrao?.rotulo ?? null,
    idiomasAudio: faixasAudio,
  }
}

/**
 * Descreve uma faixa de áudio a partir do stream devolvido pelo ffprobe.
 *
 * O rótulo prefere o mapa de idiomas, mas cai no `title` da faixa quando o
 * contêiner não trouxe `language` — alguns lançamentos nomeiam a faixa como
 * "Português (BR)" em vez de marcar o código, e perder essa informação seria
 * justamente perder a confirmação da dublagem.
 *
 * @param {object} stream stream de áudio do ffprobe
 * @param {number} indice posição da faixa na ordem do arquivo
 * @returns {{indice: number, codigo: string|null, rotulo: string|null, canais: number|null, padrao: boolean}}
 */
function descreverFaixaAudio(stream, indice) {
  const codigo = stream?.tags?.language ?? null
  const titulo = String(stream?.tags?.title ?? '').trim() || null

  return {
    indice,
    codigo,
    rotulo: descreverIdioma(codigo) ?? titulo,
    canais: stream?.channels ?? null,
    // `default` é a bandeira que o muxer usa para dizer qual faixa o player
    // escolhe sozinho — é a que o usuário vai ouvir sem mexer em nada.
    padrao: stream?.disposition?.default === 1,
  }
}

/**
 * Mede o intervalo médio entre keyframes do vídeo, em segundos.
 *
 * O `ffprobe` lista os pacotes com a flag `K` nos keyframes. Lemos apenas os
 * primeiros `KEYFRAMES_AMOSTRADOS` para não varrer o arquivo inteiro — o padrão
 * de keyframes de um encode é regular, então a amostra representa o todo.
 *
 * Devolve `null` quando não há keyframes suficientes para medir (arquivo curto
 * ou cabeçalho incompleto); nesse caso o chamador mantém a decisão por codec.
 *
 * @param {string} arquivo caminho do arquivo de vídeo
 * @returns {Promise<number|null>} intervalo médio em segundos
 */
function medirIntervaloKeyframes(arquivo) {
  return new Promise((resolve) => {
    /*
     * O `ffprobe` do fluent-ffmpeg fixa `-show_streams -show_format` e não
     * expõe pacotes, então invocamos o binário direto. `FFPROBE_PATH` é
     * respeitado pelo fluent-ffmpeg e serve de referência quando definido.
     */
    const binario = process.env.FFPROBE_PATH || 'ffprobe'

    const processo = spawn(
      binario,
      [
        '-v',
        'error',
        '-select_streams',
        'v:0',
        '-show_entries',
        'packet=pts_time,flags',
        '-of',
        'csv=p=0',
        arquivo,
      ],
      { windowsHide: true }
    )

    const tempos = []
    let buffer = ''

    const coletar = (texto) => {
      buffer += texto

      const linhas = buffer.split('\n')
      // A última linha pode estar incompleta; guardamos para o próximo chunk.
      buffer = linhas.pop() ?? ''

      for (const linha of linhas) {
        // Cada linha vem como "pts_time,flags" (ex.: "12.345000,K__").
        const [tempo, flags] = linha.split(',')

        if (!flags?.includes('K')) continue

        const segundos = Number.parseFloat(tempo)

        if (Number.isFinite(segundos)) tempos.push(segundos)

        if (tempos.length >= KEYFRAMES_AMOSTRADOS) {
          processo.kill('SIGKILL')
          return
        }
      }
    }

    processo.stdout.on('data', (dados) => coletar(dados.toString()))
    processo.stderr.on('data', () => {})

    // Qualquer falha na medição não pode derrubar a análise: sem o intervalo,
    // `decidirModo` mantém a decisão por codec.
    processo.on('error', () => resolve(null))
    processo.on('close', () => resolve(calcularIntervalo(tempos)))
  })
}

/**
 * Calcula o intervalo médio a partir dos tempos de keyframe coletados.
 *
 * Usamos a média das diferenças entre keyframes consecutivos. Com menos de dois
 * keyframes não há intervalo a medir.
 */
function calcularIntervalo(tempos) {
  if (tempos.length < 2) return null

  let soma = 0

  for (let i = 1; i < tempos.length; i += 1) {
    soma += tempos[i] - tempos[i - 1]
  }

  return soma / (tempos.length - 1)
}

/**
 * Decide o modo de conversão a partir dos codecs encontrados.
 *
 * A ordem de verificação vai do mais barato para o mais caro: se o vídeo já é
 * compatível, nunca transcodificamos o vídeo — no máximo o áudio.
 *
 * A exceção é o `remux` com keyframes esparsos. Como `-c copy` não permite
 * forçar keyframes, os segmentos sairiam irregulares e o player saltaria. Nesse
 * caso promovemos para `video`, aceitando o custo de CPU em troca de uma
 * timeline regular — condição para o seek e para a duração correta.
 *
 * @param {string} videoCodec codec de vídeo detectado
 * @param {string} audioCodec codec de áudio detectado
 * @param {number|null} [intervaloKeyframes] intervalo médio entre keyframes
 */
export function decidirModo(videoCodec, audioCodec, intervaloKeyframes = null) {
  const videoOk = VIDEO_COMPATIVEIS.includes(videoCodec)
  const audioOk = AUDIO_COMPATIVEIS.includes(audioCodec)

  if (videoOk && audioOk) {
    const keyframesEsparsos =
      intervaloKeyframes !== null && intervaloKeyframes > INTERVALO_KEYFRAME_MAXIMO

    return keyframesEsparsos ? 'video' : 'remux'
  }

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
 * @param {number} [opcoes.duracaoEsperada] duração real do filme, usada para não
 *   declarar como VOD uma conversão que parou no meio por falta de dados
 * @returns {{parar: () => void, pausar: () => void, retomar: () => void}}
 */
export function iniciarConversao({
  fluxo,
  caminho,
  extensao,
  diretorio,
  modo,
  duracaoEsperada,
  tempoInicial = 0,
  aoProgredir,
}) {
  fs.mkdirSync(diretorio, { recursive: true })

  const playlist = path.join(diretorio, 'playlist.m3u8')

  /*
   * Cada execução de conversão recebe um carimbo próprio no nome dos segmentos.
   *
   * Depois de um reposicionamento a numeração recomeça em zero: reutilizar
   * `segmento-0.ts` para um trecho diferente faria o navegador devolver o
   * segmento antigo do cache (a resposta é `immutable`), e o vídeo voltaria ao
   * conteúdo anterior à busca. O carimbo torna cada arquivo único por execução,
   * o que mantém o cache agressivo seguro.
   */
  const carimbo = Date.now()
  const padraoSegmento = path.join(diretorio, `segmento-${carimbo}-%d.ts`)

  const controle = { parado: false, comando: null }

  const comando = ffmpeg(fluxo ?? caminho)

  /*
   * O `pipe` do Node não propaga o erro de quem está sendo lido: se o fluxo de
   * entrada quebrar, o FFmpeg simplesmente para de receber dados e fica parado
   * esperando um fim que nunca chega. Escutamos aqui para ter a causa no log —
   * num torrent, quase sempre é o download que morreu ou a sessão derrubada.
   */
  fluxo?.on('error', (erro) => logger.error('[hls] erro no fluxo de entrada:', erro.message))

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
   * Reposicionamento da conversão. Quando a sessão reinicia a partir de um
   * ponto buscado, o `-ss` de entrada faz o FFmpeg começar dali, em vez de
   * reprocessar o filme desde o zero — é o que torna o seek para longe viável.
   *
   * Fica ANTES do `-fflags +genpts`: o seek precisa ser resolvido antes de os
   * PTS serem gerados, senão o deslocamento entra no meio da timeline. Com uma
   * entrada em disco o seek é instantâneo (salta pelo índice); num pipe o
   * FFmpeg descarta os bytes até o ponto, por isso o chamador já entrega o
   * fluxo posicionado o mais perto possível e deixa aqui só o resíduo.
   */
  if (tempoInicial > 0) {
    comando.inputOptions([`-ss ${tempoInicial}`])
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
      /*
       * `independent_segments` declara `#EXT-X-INDEPENDENT-SEGMENTS`. Cada
       * segmento começa num keyframe completo, então o player pode trocar de
       * faixa ou buscar em qualquer ponto sem depender do anterior.
       *
       * `temp_file` só publica o segmento quando ele está inteiro: o FFmpeg
       * escreve em `segmento-N.ts.tmp` e renomeia ao fechar. Sem isso, o nome
       * aparece na playlist antes de o arquivo terminar de ser escrito e um
       * pedido do player logo em seguida lê um trecho pela metade. O hls.js
       * aborta o trecho, recomeça a carga e — antes do primeiro buffer — acaba
       * devolvendo o início para a borda "ao vivo".
       */
      '-hls_flags independent_segments+temp_file',
    ])
    .output(playlist)
    .on('start', (linha) => logger.info('[hls] conversão iniciada:', linha))
    .on('stderr', (linha) => {
      /*
       * O FFmpeg anuncia no stderr o formato e a timeline de entrada e de saída.
       * Num pipe é a única pista de onde a leitura realmente começou: se a
       * entrada não for o contêiner esperado, ou se o `start` não for zero,
       * o motivo de uma reprodução fora do começo aparece aqui — e não por
       * dedução. Sem este filtro o log viraria o banner inteiro do FFmpeg.
       */
      if (!/Input #|Output #|Duration:|start:|Stream #|error|invalid|corrupt|missing/i.test(linha)) {
        return
      }

      logger.info('[hls] ffmpeg:', linha.trim())
    })
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

      /*
       * O FFmpeg ainda pode reescrever a playlist depois do evento `end`: ele
       * faz o flush final do muxer ao encerrar o processo, e essa escrita
       * sobrescreve a nossa. Era por isso que a playlist terminava com
       * `#EXT-X-ENDLIST` (o FFmpeg o adiciona na saída limpa) mas ainda com
       * `#EXT-X-PLAYLIST-TYPE:EVENT` — a troca para `VOD` tinha sido perdida.
       * Com `EVENT` o hls.js segue tratando o conteúdo como ao vivo mesmo após
       * o fim, mantendo a duração infinita e a barra inerte.
       *
       * Esperamos o processo encerrar de fato antes de finalizar a playlist.
       */
      const processo = controle.comando?.ffmpegProc

      if (processo && processo.exitCode === null && !processo.killed) {
        processo.once('close', () => finalizarPlaylist(playlist, duracaoEsperada))
        return
      }

      finalizarPlaylist(playlist, duracaoEsperada)
    })

  controle.comando = comando
  comando.run()

  /*
   * O freio da leitura usa sinais do sistema: `SIGSTOP` congela o processo do
   * FFmpeg com o estado inteiro preservado — o que já foi decodificado continua
   * válido — e `SIGCONT` devolve a execução de onde parou. Matar e reexecutar
   * não serve: custaria de novo toda a CPU já gasta e, num pipe, o fluxo já
   * teria sido consumido.
   */
  const sinalizar = (sinal) => {
    const processo = controle.comando?.ffmpegProc

    if (!processo?.pid || processo.exitCode !== null || processo.killed) return

    try {
      process.kill(processo.pid, sinal)
    } catch (erro) {
      logger.warn(`[hls] não foi possível enviar ${sinal} ao ffmpeg:`, erro.message)
    }
  }

  return {
    parar: () => {
      controle.parado = true

      // Um processo congelado com SIGSTOP ainda responde ao SIGKILL.
      try {
        controle.comando?.kill('SIGKILL')
      } catch {
        // O processo já pode ter terminado sozinho.
      }
    },
    pausar: () => sinalizar('SIGSTOP'),
    retomar: () => sinalizar('SIGCONT'),
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
function finalizarPlaylist(playlist, duracaoEsperada = null) {
  try {
    let conteudo = fs.readFileSync(playlist, 'utf8')

    if (conteudo.includes('#EXT-X-ENDLIST')) return

    /*
     * Nem toda conversão que termina chegou ao fim do filme. No caminho de disco
     * o FFmpeg lê além da fronteira já baixada e um arquivo esparso responde
     * zeros sem erro; no pipe o fluxo acaba quando o download morre. Declarar
     * isso como VOD com ENDLIST faz o player anunciar o filme como concluído
     * num ponto qualquer — foi exatamente o "acabou com 15 segundos". Ou seja:
     * só fechamos como VOD quando o publicado chega perto da duração real.
     */
    const esperada = Number(duracaoEsperada)

    if (Number.isFinite(esperada) && esperada > 0) {
      const publicada = somarDuracaoDaPlaylist(conteudo)
      const tolerancia = Math.max(60, esperada * 0.1)

      if (publicada < esperada - tolerancia) {
        logger.warn(
          `[hls] conversão parou em ${publicada.toFixed(1)}s de ${esperada.toFixed(1)}s — playlist mantida como EVENT`
        )
        return
      }
    }

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
 * Soma os `#EXTINF` da playlist — quanto de mídia já foi publicado.
 *
 * É a posição mais confiável da conversão: o callback de `progress` do FFmpeg
 * tem resolução de segundos e chega devagar, enquanto o `#EXTINF` é escrito a
 * cada segmento fechado. Serve tanto para o freio da leitura quanto para decidir
 * se a conversão de fato chegou ao fim do filme.
 *
 * @param {string} conteudo texto da playlist
 * @returns {number} duração publicada, em segundos
 */
export function somarDuracaoDaPlaylist(conteudo) {
  return conteudo
    .split('\n')
    .map((linha) => linha.trim())
    .filter((linha) => linha.startsWith('#EXTINF:'))
    .reduce((total, linha) => {
      const valor = Number.parseFloat(linha.slice('#EXTINF:'.length))
      return Number.isFinite(valor) ? total + valor : total
    }, 0)
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

  /*
   * Modo vídeo: o mais pesado. O preset veryfast prioriza a fluidez do
   * streaming em tempo real sobre a compressão máxima.
   *
   * `-force_key_frames` só faz sentido aqui, onde há um encoder. Ele é uma
   * opção de **saída** (controla o encoder, não o demuxer) — declará-lo como
   * opção de entrada faz o FFmpeg abortar com "cannot be applied to input url".
   * No `remux` e no `audio` o vídeo é copiado (`-c copy`), não há encoder para
   * forçar keyframes e os cortes continuam presos aos keyframes de origem.
   *
   * Ao reencodar, marcar um keyframe a cada `DURACAO_SEGMENTO` segundos alinha
   * os cortes do muxer HLS e produz uma timeline regular — condição para o seek
   * e para a duração correta.
   */
  comando
    .videoCodec('libx264')
    .audioCodec('aac')
    .audioBitrate('192k')
    .outputOptions([
      '-preset veryfast',
      '-crf 23',
      '-profile:v main',
      '-level 4.0',
      `-force_key_frames expr:gte(t,n_forced*${DURACAO_SEGMENTO})`,
    ])
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
  const moov = mapearCaixas(caminho, tamanhoArquivo).find((caixa) => caixa.tipo === 'moov')

  return moov ? { inicio: moov.inicio, fim: moov.fim } : null
}

/**
 * Lê os átomos de topo de um MP4/MOV usando apenas os cabeçalhos.
 *
 * Cada átomo começa com 4 bytes de tamanho e 4 de tipo, então dá para saltar de
 * um para o outro sem ler o conteúdo — é isso que permite descobrir a caixa
 * `mdat` (e, por consequência, onde o `moov` deveria começar) mesmo com o
 * arquivo pela metade, antes de o índice ter chegado ao disco.
 *
 * A varredura para no primeiro cabeçalho ilegível, o que acontece naturalmente
 * quando o próximo átomo ainda não foi baixado, e também quando o arquivo nem é
 * um MP4 (aí o "tamanho" lido é lixo e estoura o limite).
 *
 * @param {string} caminho caminho do arquivo em disco
 * @param {number} tamanhoArquivo tamanho total do arquivo em bytes
 * @param {number} limiteCaixas teto de átomos lidos (evita laço em lixo)
 * @returns {Array<{tipo: string, inicio: number, fim: number}>}
 */
export function mapearCaixas(caminho, tamanhoArquivo, limiteCaixas = 8) {
  let fd

  try {
    fd = fs.openSync(caminho, 'r')

    const cabecalho = Buffer.alloc(16)
    const caixas = []
    let posicao = 0

    while (posicao < tamanhoArquivo && caixas.length < limiteCaixas) {
      const lidos = fs.readSync(fd, cabecalho, 0, 16, posicao)

      if (lidos < 8) break

      let tamanho = cabecalho.readUInt32BE(0)
      const tipo = cabecalho.toString('latin1', 4, 8)

      // Tamanho 1 indica um campo de 64 bits logo após o tipo.
      if (tamanho === 1) {
        if (lidos < 16) break
        const alto = cabecalho.readUInt32BE(8)
        const baixo = cabecalho.readUInt32BE(12)
        tamanho = alto * 2 ** 32 + baixo
      }

      // Tamanho 0 significa "até o fim do arquivo".
      if (tamanho === 0) {
        tamanho = tamanhoArquivo - posicao
      }

      // Um tamanho absurdo indica que lemos lixo (arquivo incompleto ou outro
      // contêiner que não é ISO BMFF).
      if (tamanho < 8 || posicao + tamanho > tamanhoArquivo) break

      caixas.push({ tipo, inicio: posicao, fim: posicao + tamanho })
      posicao += tamanho
    }

    return caixas
  } catch {
    return []
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
