import ffmpeg from 'fluent-ffmpeg'
import path from 'node:path'
import fs from 'node:fs'
import { spawn } from 'node:child_process'

import { logger } from '../utils/logger.js'
import { descreverIdioma, normalizarIdioma } from '../utils/idiomas.js'

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

/**
 * Formatos de pixel que o MSE decodifica sem transcodificar.
 *
 * O `codec_name` sozinho não basta: um H.264 **10-bit** (`yuv420p10le`) também
 * se chama `h264`, então passava como "compatível" e o vídeo era copiado
 * (`remux`/`audio`). O `SourceBuffer` do Chrome aceita o `mimeCodec` (`avc1...`)
 * mas **rejeita o `appendBuffer`** de um stream 10-bit — o hls.js emite
 * `bufferAppendingError` seguido de `mediaSourceRequiresReset` e a reprodução
 * morre com "não foi possível carregar o vídeo". Era o sintoma das séries novas
 * (Lanterns), que costumam vir em 10-bit, enquanto as antigas são 8-bit.
 *
 * Só os formatos 8-bit abaixo são copiáveis. Qualquer outro (10-bit, 12-bit,
 * HDR) força a transcodificação para `yuv420p`.
 */
const PIX_FMT_COMPATIVEIS = ['yuv420p', 'yuvj420p']

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
 * Extensões que o demuxer HLS do FFmpeg aceita ver num segmento de playlist.
 *
 * A lista é a mesma que o FFmpeg 8 traz de fábrica (`ffmpeg -h demuxer=hls`) —
 * declarada aqui porque passar a opção **substitui** o padrão, e perder `aac`,
 * `vtt` ou `fmp4` quebraria playlists legítimas — mais a família de extensões
 * falsas que os agregadores de embed usam para despistar: o mesmo MPEG-TS é
 * anunciado como `.js`, `.css`, `.woff` ou `.html`, girando a cada segmento.
 *
 * O conteúdo desses arquivos é o de sempre (a extensão é decorativa: o CDN
 * devolve os mesmos bytes para qualquer sufixo), mas o FFmpeg recusa o playlist
 * inteiro quando encontra um segmento fora da lista — o que fazia a fonte do
 * embed morrer com `Invalid data found when processing input`.
 */
const EXTENSOES_SEGMENTO_HLS = [
  // O padrão do FFmpeg, preservado.
  '3gp', 'aac', 'avi', 'ac3', 'eac3', 'flac', 'mkv', 'm3u8', 'm4a', 'm4s', 'm4v',
  'mpg', 'mov', 'mp2', 'mp3', 'mp4', 'mpeg', 'mpegts', 'ogg', 'ogv', 'oga', 'ts',
  'vob', 'vtt', 'wav', 'webvtt', 'cmfv', 'cmfa', 'ec3', 'fmp4', 'html',
  // As falsas que os embeds pregam nos segmentos.
  'js', 'css', 'woff', 'woff2', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
  'ico', 'txt', 'json', 'xml', 'pdf', 'php', 'htm', 'wasm', 'bin', 'dat',
]

/**
 * Agente que as entradas HTTP remotas apresentam ao CDN.
 *
 * Os provedores de VOD (a fonte endereçável pelo id do TMDB) entregam a URL
 * assinada, mas o CDN de destino recusa quem não se anuncia como navegador: o
 * FFmpeg e o `curl` mandam um agente próprio ("Lavf/...") e recebem `403`
 * antes mesmo de a leitura começar. Sem este agente, a fonte que o backend
 * acabou de resolver morre na primeira sondagem — o `ffprobe` devolve "Server
 * returned 403 Forbidden" e a sessão cai para o modo conservador em cima de uma
 * fonte saudável. É o mesmo agente que o `ClienteHttp` do backend usa, o que
 * mantém o par resolução/leitura coerente para o host. O `MEDIA_USER_AGENT`
 * permite trocá-lo sem mexer no código.
 */
const AGENTE_DE_NAVEGADOR =
  process.env.MEDIA_USER_AGENT ||
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'

/**
 * Diz se a origem é um endereço remoto (e não um arquivo em disco).
 *
 * É a distinção que separa o torrent do stream direto dentro da conversão: um
 * caminho local é buscável e dispensa ajustes de sondagem, enquanto a URL
 * remota é lida pelo FFmpeg com requisições HTTP.
 */
function ehOrigemRemota(origem) {
  return /^https?:\/\//i.test(String(origem ?? ''))
}

/**
 * Diz se a origem é uma playlist HLS remota.
 *
 * Só nesse caso as opções abaixo podem ser passadas: `extension_picky` e
 * `allowed_segment_extensions` são opções do demuxer HLS, e num MP4 o FFmpeg
 * aborta a abertura com `Option not found` em vez de ignorá-las.
 */
function ehPlaylistHlsRemota(origem, extensao = '') {
  if (!ehOrigemRemota(origem)) return false

  return String(extensao ?? '').toLowerCase() === '.m3u8' || /\.m3u8([?#]|$)/i.test(String(origem))
}

/**
 * Opções de entrada que destravam playlists de embed.
 *
 * As duas travas do FFmpeg existem para proteger contra playlist adulterada, e
 * são exatamente o que a ofuscação dos agregadores atinge:
 *
 * - `allowed_segment_extensions` recusa o playlist inteiro ao encontrar um
 *   segmento com extensão fora da lista (o `.js`/`.css`/`.woff` falso);
 * - `extension_picky` recusa o segmento quando a extensão da URL não casa com
 *   o formato que a sondagem detectou (MPEG-TS anunciado como `.js`).
 *
 * A origem aqui não é entrada do usuário: é a URL que o resolvedor do backend
 * devolveu. Como a lista é conhecida e a leitura continua sendo em fluxo — o
 * FFmpeg busca um segmento de cada vez, sem baixar o arquivo inteiro antes de
 * começar —, afrouxar as duas travas para esta entrada não abre nada além do
 * que o próprio provedor precisa para tocar.
 *
 * @param {string} origem URL de entrada da conversão
 * @param {string} [extensao] extensão declarada da fonte (`.m3u8`, `.mp4`...)
 * @returns {string[]} opções para o FFmpeg, vazias quando não se aplicam
 */
export function opcoesEntradaHlsRemota(origem, extensao = '') {
  if (!ehPlaylistHlsRemota(origem, extensao)) return []

  return ['-extension_picky', '0', '-allowed_segment_extensions', EXTENSOES_SEGMENTO_HLS.join(',')]
}

/**
 * Ajustes de conexão para uma entrada HTTP remota.
 *
 * O FFmpeg abre o playlist e cada segmento numa conexão própria, e sem estes
 * ajustes um único soluço de rede no meio do filme encerra a conversão — a
 * sessão morre com a fonte saudável. `reconnect` religa a conexão caída,
 * `reconnect_streamed` permite religar um fluxo já aberto, `reconnect_delay_max`
 * limita a espera entre as tentativas e `reconnect_on_http_error` cobre as
 * respostas que o CDN devolve quando está congestionado (`429`) ou com a borda
 * reiniciando (`5xx`).
 *
 * São opções do protocolo HTTP, e por isso valem para qualquer URL remota — ao
 * contrário das do demuxer HLS, que só existem em playlist. O `-user_agent`
 * entra aqui pelo mesmo motivo: é o cabeçalho que decide se o CDN do provedor
 * atende ou devolve `403`, e vale tanto para a playlist quanto para o arquivo
 * progressivo.
 *
 * Cada opção vai em **pares separados** (`'-reconnect', '1'`), e não como uma
 * string só (`'-reconnect 1'`). O motivo é o `fluent-ffmpeg`: ele só divide uma
 * opção pelos espaços quando ela tem **exatamente um**, então `-reconnect 1`
 * funcionaria, mas `-user_agent Mozilla/5.0 (X11; ...)` seria passado inteiro e
 * viraria `Unrecognized option`. Pior: o `ffprobe` do próprio pacote **não
 * divide nada** — concatena cada item do array ao comando. Pares separados
 * servem aos dois caminhos sem depender do split.
 *
 * @param {string} origem URL de entrada da conversão
 * @returns {string[]} opções para o FFmpeg, vazias quando a origem é local
 */
export function opcoesRedeRemota(origem) {
  if (!ehOrigemRemota(origem)) return []

  return [
    '-user_agent', AGENTE_DE_NAVEGADOR,
    '-reconnect', '1',
    '-reconnect_streamed', '1',
    '-reconnect_delay_max', '5',
    '-reconnect_on_http_error', '5xx,429',
  ]
}

/**
 * Sinais de que a falha veio do caminho até a fonte, e não da fonte.
 *
 * A distinção existe porque os dois casos pedem reações opostas e a mensagem do
 * erro não os separa: o `ffprobe` reporta "exited with code 1" tanto para um
 * servidor que recusou quanto para um nome que não resolveu. Um servidor que não
 * respondeu agora merece nova tentativa — foi o que aconteceu com o DNS do
 * container no primeiro episódio: o `lookup` a frio voltou `EAI_AGAIN` (o
 * `Failed to resolve hostname ... Try again` do FFmpeg) e a mesma URL abriu
 * normalmente um minuto depois. Já um `Invalid data found` diz que aquele
 * endereço não é o que promete, e insistir só gasta orçamento.
 */
const SINAIS_DE_REDE = [
  'EAI_AGAIN',
  'ETIMEDOUT',
  'ECONNRESET',
  'ECONNREFUSED',
  'EHOSTUNREACH',
  'ENETUNREACH',
  'EPIPE',
  'I/O error',
  'Failed to resolve',
  'Temporary failure in name resolution',
  'Try again',
  'Server returned 5',
  'Server returned 429',
  'timed out',
]

/**
 * Sinais de que o problema foi resolver o **nome** da fonte.
 *
 * É o caso mais grave da família de rede: se nem o FFmpeg consegue traduzir o
 * host em endereço, transcodificar não muda nada. O chamador usa esta marca
 * para falhar rápido, em vez de entregar a sessão a um modo que já nasce morto.
 */
const SINAIS_DE_RESOLUCAO = [
  'EAI_AGAIN',
  'Failed to resolve',
  'Temporary failure in name resolution',
  'Name or service not known',
  'nodename nor servname',
]

/** Diz se algum dos sinais aparece no código ou na mensagem do erro. */
function contemAlgum(texto, sinais) {
  const alvo = String(texto ?? '').toLowerCase()

  return sinais.some((sinal) => alvo.includes(sinal.toLowerCase()))
}

/**
 * Diz se o erro é do caminho até a fonte e vale uma nova tentativa.
 *
 * @param {Error} erro erro vindo do ffprobe, do FFmpeg ou do resolvedor de nomes
 */
export function ehFalhaTransitoria(erro) {
  return contemAlgum(`${erro?.code ?? ''} ${erro?.message ?? ''}`, SINAIS_DE_REDE)
}

/**
 * Diz se o erro foi de resolução de nome.
 *
 * @param {Error} erro erro vindo do ffprobe, do FFmpeg ou do resolvedor de nomes
 */
export function ehFalhaDeResolucao(erro) {
  return contemAlgum(`${erro?.code ?? ''} ${erro?.message ?? ''}`, SINAIS_DE_RESOLUCAO)
}

/**
 * Reduz o erro a uma linha, para o log e para a mensagem do usuário.
 *
 * O erro do `fluent-ffmpeg` carrega o comando inteiro e o banner do FFmpeg
 * dentro da mensagem; num log de sessão isso enterra a causa sob a configuração
 * de compilação. A primeira linha é a que diz o que aconteceu.
 *
 * @param {Error} erro
 * @returns {string} resumo curto, com o código quando existir
 */
export function resumirErro(erro) {
  const linhas = String(erro?.message ?? erro ?? '').trim().split('\n')
  const primeira = (linhas.find((linha) => linha.trim() !== '') ?? '').trim()
  const cortada = primeira.length > 160 ? `${primeira.slice(0, 160)}…` : primeira

  return erro?.code ? `${erro.code}: ${cortada}` : cortada
}

/**
 * Repete uma operação enquanto a falha for transitória.
 *
 * A espera cresce a cada volta (`esperaMs * tentativa`): um segundo de intervalo
 * já basta para o DNS do container responder, e esperar mais no começo só
 * adiaria o resultado quando a primeira tentativa falha por um motivo definitivo.
 *
 * @template T
 * @param {() => Promise<T>} operacao
 * @param {{tentativas?: number, esperaMs?: number}} [opcoes]
 * @returns {Promise<T>}
 */
export async function comRetentativa(operacao, { tentativas = 3, esperaMs = 1000 } = {}) {
  let ultimoErro = null

  for (let tentativa = 1; tentativa <= tentativas; tentativa += 1) {
    try {
      return await operacao()
    } catch (erro) {
      ultimoErro = erro

      if (tentativa === tentativas || !ehFalhaTransitoria(erro)) break

      logger.warn(
        `[rede] tentativa ${tentativa} de ${tentativas} falhou (${resumirErro(erro)}); repetindo em ${esperaMs * tentativa}ms`
      )

      await new Promise((resolve) => setTimeout(resolve, esperaMs * tentativa))
    }
  }

  throw ultimoErro
}

/**
 * Extrai os metadados do arquivo para decidir o modo de conversão.
 *
 * Além dos codecs, devolvemos a duração total. O Plyr não consegue deduzi-la de
 * uma playlist `EVENT` em crescimento — o hls.js a trata como transmissão ao
 * vivo e reporta `Infinity` — então o frontend usa este valor como fonte de
 * verdade enquanto a conversão não termina.
 *
 * Devolvemos também o `pixFmt` do vídeo, mesmo quando vazio. O chamador precisa
 * distinguir "é 8-bit" de "ainda não sei": um cabeçalho lido pela metade traz o
 * `codec_name` sem o `pix_fmt`, e tratar os dois como iguais foi o que copiou um
 * 10-bit e derrubou o MSE.
 *
 * @param {string} arquivo caminho do arquivo de vídeo dentro do torrent
 * @param {string} [extensao] extensão declarada da fonte, quando já se conhece
 *   (a fonte direta a traz da URL); serve para reconhecer a playlist HLS
 * @returns {Promise<{modo: string, videoCodec: string, pixFmt: string, audioCodec: string, duracao: number|null, inicioFonte: number|null, idiomaAudio: string|null, idiomaAudioRotulo: string|null, idiomasAudio: Array<object>}>}
 */
export async function analisarArquivo(arquivo, extensao = '') {
  const remoto = ehOrigemRemota(arquivo)

  /*
   * O ffprobe devolve "No such file or directory" quando o arquivo ainda não
   * existe em disco — o que acontece nos primeiros porcentos do download. Esse
   * erro é indistinguível, na mensagem, de um arquivo corrompido, então o
   * rotulamos aqui para o chamador poder esperar em vez de desistir da fonte.
   *
   * A guarda vale só para arquivo local: numa fonte direta o "arquivo" é uma
   * URL, que nunca existe em disco, e o ffprobe a lê por HTTP.
   */
  if (!remoto && !fs.existsSync(arquivo)) {
    const erro = new Error(`Arquivo ainda não existe em disco: ${arquivo}`)
    erro.code = 'ENOENT'
    throw erro
  }

  /*
   * A sondagem de uma origem remota pode falhar por um soluço do caminho — o
   * DNS recém-resolvido, a conexão derrubada na primeira tentativa — e passar na
   * seguinte. Repetir aqui é o que evita que esse instante vire uma decisão
   * errada: sem isso o erro subia, a sessão caía no modo conservador e a
   * conversão morria na abertura, sempre na primeira tentativa de cada episódio.
   * Num arquivo local a repetição seria inútil (o erro é "não existe" ou "não é
   * vídeo") e só custaria tempo.
   */
  const sondar = () =>
    new Promise((resolve, reject) => {
      ffmpeg(arquivo).ffprobe(
        null,
        [...opcoesRedeRemota(arquivo), ...opcoesEntradaHlsRemota(arquivo, extensao)],
        (erro, dados) => {
          if (erro) return reject(erro)

          resolve(dados)
        },
      )
    })

  const metadados = remoto ? await comRetentativa(sondar, { tentativas: 3, esperaMs: 1000 }) : await sondar()

  const streams = metadados?.streams ?? []
  const video = streams.find((s) => s.codec_type === 'video')

  const videoCodec = (video?.codec_name ?? '').toLowerCase()

  /*
   * O formato de pixel é o que separa um H.264 copiável de um que o MSE recusa.
   * Um encode 10-bit (`yuv420p10le`) tem `codec_name = 'h264'` igual a um 8-bit,
   * mas o `SourceBuffer` do navegador só decodifica 8-bit — copiá-lo produz
   * `bufferAppendingError` e a reprodução morre. Lemos aqui para que
   * `decidirModo` force a transcodificação quando não for 8-bit.
   */
  const pixFmt = (video?.pix_fmt ?? '').toLowerCase()

  /*
   * O idioma da faixa de áudio vive nas tags do contêiner e é a única prova de
   * que a dublagem existe: o nome do arquivo promete "Dublado", mas quem diz o
   * que está lá dentro é a faixa. Registramos todas porque um lançamento
   * "dual áudio" traz duas ou mais, e é a soma delas que confirma a promessa.
   */
  const faixasAudio = streams.filter((s) => s.codec_type === 'audio').map(descreverFaixaAudio)
  const faixaPadrao = escolherFaixaAudio(faixasAudio)

  /*
   * O codec de áudio que decide o modo é o da faixa escolhida, não o da
   * primeira do arquivo. Num dual áudio as faixas podem ter codecs diferentes
   * (a dublagem em AC3 e o original em AAC, por exemplo); decidir pelo codec
   * errado levaria a um `remux` que copia um áudio que o MSE não decodifica.
   */
  const audioCodec = (faixaPadrao?.codec ?? '').toLowerCase()

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
   *
   * Numa fonte direta a medição também fica de fora, por um motivo prático: a
   * janela de leitura atravessa a rede, e num playlist HLS ela obrigaria a
   * baixar minutos de vídeo **antes** de a conversão começar. O playlist já
   * entrega a informação equivalente de graça — a duração dos próprios
   * segmentos é o intervalo de keyframes que o provedor usou —, então a decisão
   * segue pelo codec e a sessão começa sem essa espera.
   */
  const videoOk = VIDEO_COMPATIVEIS.includes(videoCodec)
  const intervaloKeyframes = videoOk && !remoto ? await medirIntervaloKeyframes(arquivo) : null

  return {
    modo: decidirModo(videoCodec, audioCodec, intervaloKeyframes, pixFmt),
    videoCodec,
    pixFmt,
    audioCodec,
    intervaloKeyframes,
    duracao: Number.isFinite(duracao) ? duracao : null,
    inicioFonte: Number.isFinite(inicioFonte) ? inicioFonte : null,
    idiomaAudio: faixaPadrao?.codigo ?? null,
    idiomaAudioRotulo: faixaPadrao?.rotulo ?? null,
    idiomasAudio: faixasAudio,
    /*
     * Índice da faixa de áudio escolhida para a saída. A conversão mapeia só
     * esta faixa: sem isso o FFmpeg levaria todas as faixas do arquivo para o
     * mesmo segmento e o hls.js não decodificaria o resultado.
     */
    indiceAudio: faixaPadrao?.indice ?? null,
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
 * @returns {{indice: number, codigo: string|null, rotulo: string|null, codec: string, canais: number|null, padrao: boolean}}
 */
function descreverFaixaAudio(stream, indice) {
  const codigo = stream?.tags?.language ?? null
  const titulo = String(stream?.tags?.title ?? '').trim() || null

  return {
    indice,
    codigo,
    rotulo: descreverIdioma(codigo) ?? titulo,
    // Codec da faixa: é ele que decide se o áudio pode ser copiado (`remux`)
    // ou precisa ser convertido para AAC (`audio`).
    codec: (stream?.codec_name ?? '').toLowerCase(),
    canais: stream?.channels ?? null,
    // `default` é a bandeira que o muxer usa para dizer qual faixa o player
    // escolhe sozinho — é a que o usuário vai ouvir sem mexer em nada.
    padrao: stream?.disposition?.default === 1,
  }
}

/**
 * Escolhe qual faixa de áudio será levada para a saída HLS.
 *
 * Um lançamento "dual áudio" traz duas ou mais faixas no mesmo arquivo. Sem
 * escolher uma, o FFmpeg mapeia todas para o segmento MPEG-TS e o hls.js não
 * consegue decodificar o resultado — o `SourceBuffer` rejeita o trecho com
 * múltiplos áudios e o player falha. Além disso, a faixa que tocaria seria a
 * primeira do arquivo, que num dual costuma ser o idioma original.
 *
 * A preferência é a dublagem em português: é o que o usuário pediu ao escolher
 * uma fonte "Dublado". Só caímos para a faixa marcada como padrão (ou a
 * primeira) quando não há nenhuma em PT-BR — aí a fonte não entregou o que
 * prometia, e o selo de idioma no player denuncia isso.
 *
 * @param {Array<object>} faixas faixas de áudio descritas por `descreverFaixaAudio`
 * @returns {object|null}
 */
function escolherFaixaAudio(faixas) {
  if (!faixas.length) return null

  const portugues = faixas.find(descrevePortugues)

  if (portugues) return portugues

  return faixas.find((faixa) => faixa.padrao) ?? faixas[0]
}

/**
 * Diz se uma faixa de áudio é a dublagem em português.
 *
 * A tag `language` é a prova mais forte, mas não é a única: muitos lançamentos
 * "dual áudio" deixam a dublagem sem código de idioma e a identificam apenas no
 * `title` da faixa ("Português (BR)", "DUBLADO", "PT-BR"). Olhar só para o
 * código descartava justamente a faixa que o usuário pediu ao escolher uma fonte
 * "Dublado", e o player caía no áudio original — foi o que aconteceu com o
 * "Lanterns", que tocou em inglês apesar do rótulo.
 *
 * O título é comparado em minúsculas e sem acento para casar "Português",
 * "portugues" e "PORTUGUÊS BR" sem depender da grafia do encoder.
 *
 * @param {object} faixa faixa descrita por `descreverFaixaAudio`
 * @returns {boolean}
 */
function descrevePortugues(faixa) {
  const codigo = normalizarIdioma(faixa.codigo)

  if (codigo === 'por' || codigo === 'pt' || codigo.startsWith('pt-')) {
    return true
  }

  const titulo = normalizarIdioma(faixa.rotulo)

  return (
    titulo.includes('portug') ||
    titulo.includes('dublado') ||
    titulo.includes('dublagem') ||
    titulo.includes('pt-br') ||
    titulo.includes('ptbr')
  )
}

/**
 * Diz se o arquivo traz alguma faixa de áudio em português.
 *
 * É o fato que o porteiro de idioma consulta: a fonte prometeu dublagem no nome,
 * mas só a sondagem do arquivo prova se a faixa existe. Um release só com áudio
 * original — o caso do americano do EZTV que o Torrentio rotulava como
 * "Dublado" — devolve `false` e a fonte é descartada em vez de tocar em inglês.
 *
 * @param {Array<object>} faixas faixas descritas por `descreverFaixaAudio`
 * @returns {boolean}
 */
export function temFaixaPortuguesa(faixas = []) {
  return faixas.some(descrevePortugues)
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

    /*
     * `-read_intervals` limita a varredura a uma janela do arquivo. Sem ele o
     * ffprobe percorre o arquivo inteiro até juntar os keyframes da amostra — e
     * no caminho de fluxo o arquivo ainda está sendo baixado, então a leitura
     * trava nos buracos do download e a medição volta vazia. Com a janela, a
     * leitura para assim que os keyframes da amostra aparecem.
     *
     * A janela é generosa (alguns minutos) para caber os 12 keyframes mesmo num
     * encode esparso de ~10 s entre keyframes.
     */
    const processo = spawn(
      binario,
      [
        '-v',
        'error',
        '-select_streams',
        'v:0',
        '-read_intervals',
        '%+300',
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
    let encerrado = false

    const finalizar = () => {
      if (encerrado) return
      encerrado = true
      resolve(calcularIntervalo(tempos))
    }

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
          /*
           * Já temos a amostra. Matamos o processo e resolvemos na hora, sem
           * esperar o `close`: num arquivo parcial o ffprobe pode demorar para
           * encerrar sozinho depois de ler o trecho disponível.
           */
          processo.kill('SIGKILL')
          finalizar()
          return
        }
      }
    }

    processo.stdout.on('data', (dados) => coletar(dados.toString()))
    processo.stderr.on('data', () => {})

    // Qualquer falha na medição não pode derrubar a análise: sem o intervalo,
    // `decidirModo` mantém a decisão por codec.
    processo.on('error', () => {
      encerrado = true
      resolve(null)
    })
    processo.on('close', finalizar)
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
 * Diz se a profundidade de cor decide alguma coisa para este codec de vídeo.
 *
 * Só faz sentido esperar pelo `pix_fmt` quando o codec seria **copiado** — é o
 * único caso em que ele muda a decisão. Para HEVC, AV1 ou VP9 o modo já é
 * `video` de qualquer forma, e exigir o `pix_fmt` só atrasaria a análise sem
 * motivo.
 *
 * @param {string} videoCodec codec de vídeo detectado
 * @returns {boolean}
 */
export function profundidadeRelevante(videoCodec) {
  return VIDEO_COMPATIVEIS.includes((videoCodec ?? '').toLowerCase())
}

/**
 * Decide o modo de conversão a partir dos codecs encontrados.
 *
 * A ordem de verificação vai do mais barato para o mais caro: se o vídeo já é
 * compatível, nunca transcodificamos o vídeo — no máximo o áudio.
 *
 * Duas exceções promovem para `video` (transcodificação):
 *
 * 1. **Keyframes esparsos no `remux`.** Como `-c copy` não permite forçar
 *    keyframes, os segmentos sairiam irregulares e o player saltaria. Nesse caso
 *    aceitamos o custo de CPU em troca de uma timeline regular — condição para o
 *    seek e para a duração correta.
 * 2. **Formato de pixel não confirmado como 8-bit.** Um H.264 10-bit
 *    (`yuv420p10le`) tem o mesmo `codec_name` de um 8-bit, mas o `SourceBuffer`
 *    do navegador só decodifica 8-bit: copiá-lo produz `bufferAppendingError` e
 *    a reprodução morre. Exigimos a **prova** de que é 8-bit; quando o `pix_fmt`
 *    não veio (cabeçalho lido pela metade), tratamos como suspeito e
 *    transcodificamos — copiar às cegas é justamente o que derrubava o player.
 *
 * @param {string} videoCodec codec de vídeo detectado
 * @param {string} audioCodec codec de áudio detectado
 * @param {number|null} [intervaloKeyframes] intervalo médio entre keyframes
 * @param {string} [pixFmt] formato de pixel do vídeo (ex.: `yuv420p10le`)
 */
export function decidirModo(videoCodec, audioCodec, intervaloKeyframes = null, pixFmt = '') {
  const videoOk = VIDEO_COMPATIVEIS.includes(videoCodec)
  const audioOk = AUDIO_COMPATIVEIS.includes(audioCodec)

  /*
   * A ausência do `pix_fmt` **não** é sinal verde: significa que ainda não
   * sabemos a profundidade de cor. Exigimos que o formato esteja entre os 8-bit
   * conhecidos; só assim é seguro copiar o vídeo.
   */
  const pixelOk = PIX_FMT_COMPATIVEIS.includes(pixFmt)

  if (videoOk && audioOk && pixelOk) {
    const keyframesEsparsos =
      intervaloKeyframes !== null && intervaloKeyframes > INTERVALO_KEYFRAME_MAXIMO

    return keyframesEsparsos ? 'video' : 'remux'
  }

  if (videoOk && pixelOk) return 'audio'

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
  indiceAudio = null,
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

  const controle = { parado: false, comando: null, erro: null }

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
   * Numa playlist HLS remota afrouxamos as duas travas de extensão do demuxer.
   * Sem isso o FFmpeg recusa o playlist inteiro ao topar com o primeiro
   * segmento de extensão falsa — `.js`, `.css`, `.woff` —, que é como os
   * agregadores de embed escondem o MPEG-TS. A leitura continua em fluxo: o
   * FFmpeg busca um segmento de cada vez, publica o trecho correspondente e só
   * então segue para o próximo.
   *
   * Junto vão os ajustes de reconexão do HTTP: uma entrada remota é lida em
   * conexões curtas (o playlist e cada segmento), e sem eles um soluço de rede
   * no meio do filme encerra a conversão inteira.
   */
  const opcoesEntrada = [...opcoesRedeRemota(caminho), ...opcoesEntradaHlsRemota(caminho, extensao)]

  if (opcoesEntrada.length) {
    comando.inputOptions(opcoesEntrada)
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

  /*
   * Mapeamento explícito das faixas.
   *
   * Sem `-map`, o FFmpeg leva TODAS as faixas de áudio do arquivo para a saída.
   * Num lançamento dual áudio isso produz um segmento MPEG-TS com dois áudios
   * no mesmo programa, que o hls.js não consegue decodificar — o `SourceBuffer`
   * rejeita o trecho e o player falha com "não foi possível exibir o vídeo".
   * Era exatamente o sintoma das fontes dubladas.
   *
   * Mapeamos o primeiro vídeo e apenas a faixa de áudio escolhida (a dublada em
   * PT-BR quando existe). O `?` no fim torna o mapeamento opcional: se a faixa
   * não existir, o FFmpeg não aborta — apenas segue sem áudio, e o erro real
   * aparece no log em vez de virar uma falha silenciosa de mapeamento.
   */
  const mapeamentos = ['0:v:0']

  if (indiceAudio !== null) {
    mapeamentos.push(`0:a:${indiceAudio}?`)
  } else {
    mapeamentos.push('0:a:0?')
  }

  comando.outputOptions(mapeamentos.map((mapa) => `-map ${mapa}`))

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

      /*
       * A causa é guardada além de registrada. Quem acompanha a conversão
       * precisa saber que o processo morreu para não esperar o prazo inteiro por
       * algo que já não existe, e para separar um soluço de rede — que vale
       * reabrir a conversão — de um arquivo que o FFmpeg recusou de vez.
       */
      controle.erro = erro

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
    /*
     * O erro que derrubou o processo, quando houve. O `parar` não entra aqui:
     * um encerramento pedido pelo chamador não é falha, e a sessão que pediu
     * para morrer não quer a conversão reaberta.
     */
    erro: () => controle.erro,
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
 * Fixa o AAC de saída na configuração que os navegadores conseguem ler.
 *
 * O encoder AAC do FFmpeg não tem configuração equivalente para `5.1(side)` — o
 * layout que o E-AC-3 dos lançamentos dublados costuma trazer. Sem equivalente
 * ele grava `channel_configuration = 0` no ADTS e passa a descrever os canais
 * num PCE (Program Config Element) dentro dos quadros de áudio.
 *
 * Nenhum navegador aceita esse cabeçalho. Num segmento real deste caso o
 * `ffprobe` devolvia `aac, sample_rate=0, channels=0, channel_layout=unknown` e
 * o decodificador rejeitava todos os quadros de áudio do trecho, enquanto o
 * vídeo copiado no mesmo arquivo decodificava inteiro — prova de que a entrada
 * estava sã e o defeito nascia no reencode do áudio. No MSE o fim da linha é
 * `PipelineStatus::CHUNK_DEMUXER_ERROR_APPEND_FAILED: RunSegmentParserLoop:
 * stream parsing failed` no SourceBuffer de áudio; como o Chromium encerra o
 * MediaSource no primeiro append inválido, todo `appendBuffer` seguinte falha
 * em cascata e o hls.js esgota as recuperações de mídia.
 *
 * `-ac 2` e `-ar 48000` produzem um AAC-LC estéreo canônico, com
 * `channel_configuration = 2` e taxa de amostragem explícita no ADTS — o
 * cabeçalho que qualquer decodificador reconhece. O downmix é o que o navegador
 * entregaria de qualquer forma: surround 5.1 não sobrevive ao caminho web.
 */
function fixarAacCanonico(comando) {
  return comando.audioCodec('aac').audioBitrate('192k').audioChannels(2).audioFrequency(48000)
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
    comando.videoCodec('copy')
    fixarAacCanonico(comando)
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
   *
   * NÃO fixamos `-level`. Um nível declarado à mão vira uma promessa no SPS/PPS
   * que o encoder não cumpre quando o conteúdo real a excede: uma série nova em
   * 1080p de bitrate alto (ou 2160p) estoura o Nível 4.0, mas o FFmpeg escreve
   * o nível assim mesmo. O navegador então cria o `SourceBuffer` com um codec
   * (`avc1.4d4028`) que não corresponde ao stream e o decoder rejeita os
   * segmentos no MSE — a reprodução começa e morre com "não foi possível exibir
   * o vídeo". Sem `-level`, o FFmpeg deriva o nível correto de resolução,
   * bitrate e framerate, e o cabeçalho passa a dizer a verdade.
   *
   * O perfil `main` permanece: é o mais compatível com MSE em todos os
   * navegadores e não carrega a mesma armadilha — o perfil descreve recursos
   * (B-frames, entropia) que o encoder realmente usa, não um teto de banda.
   */
  comando.videoCodec('libx264')

  fixarAacCanonico(comando)

  comando.outputOptions([
      '-preset veryfast',
      '-crf 23',
      '-profile:v main',
      /*
       * `yuv420p` é o único formato de pixel que o MSE decodifica em todos os
       * navegadores. Sem esta linha, um encode de origem 10-bit (ou HDR) seria
       * reencodado mantendo a profundidade de cor e o `SourceBuffer` voltaria a
       * recusar o `appendBuffer` — a transcodificação não teria resolvido nada.
       * Declarar o formato de saída é o que garante o resultado 8-bit.
       */
      '-pix_fmt yuv420p',
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
 * O `timeoutMs` é um teto de segurança, não um veredito de morte. Numa fonte
 * direta (URL HTTP convertida para HLS) a conversão pode ser longa e o relógio
 * sozinho condenaria um trabalho que está andando. Por isso aceitamos um
 * `aoProgredir` opcional: quando ele devolve `true`, a conversão deu sinal de
 * vida desde a última checagem e o prazo é renovado. Só quando o progresso
 * também para é que o tempo esgotado vira erro de verdade.
 *
 * @param {string} diretorio pasta da sessão
 * @param {number} minimoSegmentos quantidade mínima de segmentos prontos
 * @param {number} timeoutMs tempo máximo de espera sem sinal de vida
 * @param {() => boolean} [aoProgredir] devolve `true` se a conversão avançou
 * @param {() => boolean} [estaVivo] devolve `false` se a conversão já morreu
 */
export function aguardarBufferInicial(
  diretorio,
  minimoSegmentos = 8,
  timeoutMs = 180000,
  aoProgredir = null,
  estaVivo = null
) {
  const playlist = path.join(diretorio, 'playlist.m3u8')
  let inicio = Date.now()

  return new Promise((resolve, reject) => {
    const verificar = () => {
      if (fs.existsSync(playlist)) {
        const conteudo = fs.readFileSync(playlist, 'utf8')
        const segmentos = (conteudo.match(/\.ts/g) ?? []).length

        if (segmentos >= minimoSegmentos) {
          return resolve(true)
        }
      }

      /*
       * O processo morreu: esperar até o fim do prazo só serviria para esconder a
       * causa por três minutos. Era o que acontecia na fonte direta quando o
       * FFmpeg encerrava na abertura por um erro de DNS — a tela ficava em
       * "convertendo o vídeo" com o processo já encerrado. Quem conhece a razão é
       * o chamador, que guarda o erro do comando.
       */
      if (estaVivo && !estaVivo()) {
        return reject(new Error('A conversão terminou antes de publicar o buffer inicial.'))
      }

      /*
       * A conversão avançou desde a última checagem: o prazo reinicia. Assim o
       * `timeoutMs` mede estagnação real, e não a duração total da conversão.
       */
      if (aoProgredir?.()) {
        inicio = Date.now()
      } else if (Date.now() - inicio > timeoutMs) {
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
