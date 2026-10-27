/**
 * Tradução de códigos de idioma para um rótulo legível em PT-BR.
 *
 * O ffprobe reporta o idioma da faixa de áudio em ISO 639-2 (três letras, como
 * `por`) e — quando o encoder foi caprichoso — também em ISO 639-1 (duas letras,
 * como `pt`) ou com a região (`pt-br`). O mesmo idioma chega por caminhos
 * diferentes, então normalizamos antes de consultar a tabela. Guardar o código
 * cru e exibi-lo ao usuário não ajuda ninguém; o que interessa é o nome.
 */

const IDIOMAS = {
  por: 'Português',
  pt: 'Português',
  eng: 'Inglês',
  en: 'Inglês',
  spa: 'Espanhol',
  es: 'Espanhol',
  fra: 'Francês',
  fre: 'Francês',
  fr: 'Francês',
  deu: 'Alemão',
  ger: 'Alemão',
  de: 'Alemão',
  ita: 'Italiano',
  it: 'Italiano',
  jpn: 'Japonês',
  ja: 'Japonês',
  kor: 'Coreano',
  ko: 'Coreano',
  zho: 'Chinês',
  chi: 'Chinês',
  zh: 'Chinês',
  rus: 'Russo',
  ru: 'Russo',
  ara: 'Árabe',
  ar: 'Árabe',
  hin: 'Hindi',
  hi: 'Hindi',
  nld: 'Holandês',
  dut: 'Holandês',
  nl: 'Holandês',
  pol: 'Polonês',
  pl: 'Polonês',
  tur: 'Turco',
  tr: 'Turco',
  swe: 'Sueco',
  sv: 'Sueco',
  dan: 'Dinamarquês',
  da: 'Dinamarquês',
  nor: 'Norueguês',
  no: 'Norueguês',
  fin: 'Finlandês',
  fi: 'Finlandês',
  ces: 'Tcheco',
  cze: 'Tcheco',
  cs: 'Tcheco',
  hun: 'Húngaro',
  hu: 'Húngaro',
  ell: 'Grego',
  gre: 'Grego',
  el: 'Grego',
  heb: 'Hebraico',
  he: 'Hebraico',
  tha: 'Tailandês',
  th: 'Tailandês',
  ind: 'Indonésio',
  id: 'Indonésio',
  vie: 'Vietnamita',
  vi: 'Vietnamita',
  ukr: 'Ucraniano',
  uk: 'Ucraniano',
  ron: 'Romeno',
  rum: 'Romeno',
  ro: 'Romeno',
  cat: 'Catalão',
  ca: 'Catalão',
  mul: 'Vários idiomas',
  und: 'Idioma não informado',
}

/**
 * Normaliza um código de idioma para o formato de consulta da tabela.
 *
 * A variação `_`→`-` existe porque alguns encoders gravam `pt_BR`: é o mesmo
 * padrão de POSIX, não de idioma, mas aparece com frequência nos arquivos.
 */
export function normalizarIdioma(codigo) {
  return String(codigo ?? '')
    .trim()
    .toLowerCase()
    .replace(/_/g, '-')
}

/**
 * Devolve o nome do idioma em PT-BR, ou `null` quando não reconhecido.
 *
 * Códigos com região (`pt-br`, `es-419`) caem no idioma base de propósito: o
 * que importa para o usuário é saber que a faixa está em português — distinguir
 * a variante seria ruído.
 */
export function descreverIdioma(codigo) {
  const normalizado = normalizarIdioma(codigo)

  if (!normalizado) return null

  const base = normalizado.split('-')[0]

  return IDIOMAS[normalizado] ?? IDIOMAS[base] ?? null
}

/*
 * Indícios de áudio em PT-BR, espelho fiel de `App\Support\IndiciosPtBr` do
 * backend. A duplicação é consciente: a mesma pergunta ("este release é
 * dublado?") precisa ser respondida nos dois lados — o backend julga o nome do
 * torrent e o media-service lê o conteúdo do pack —, e as duas pontas não
 * compartilham código. Se a lista mudar de um lado, precisa mudar do outro.
 *
 * O critério é conservador de propósito: só a **marca** que fala do áudio (ou da
 * origem brasileira) conta — "dublado", "nacional", "português", "brasileiro", a
 * bandeira e o código colado de PT-BR. O marcador genérico de multi-faixa
 * ("dual", "multi áudio") e o "pt" solto ficam de fora: nenhum dos dois prova
 * que existe uma faixa em português (ver a nota da classe `IndiciosPtBr` no
 * backend).
 */

/*
 * Prova forte dividida em duas, como no PHP: a marca **explícita** de áudio vale
 * inclusive quando o nome também anuncia legenda ("Dublado e Legendado"), e a
 * marca de **idioma** ("português", "portuguese") só vale sem legenda ao lado —
 * "PORTUGUESE.SUBtitles" fala da legenda, não do áudio.
 */
const INDICIOS_PT_BR_AUDIO_EXPLICITO = [
  'dublado',
  'dublada',
  'dublagem',
  'nacional',
  'áudio pt',
  'audio pt',
  'brasileiro',
  'brasileira',
  'brasil',
  'brazil',
  'brazilian',
]

const INDICIOS_PT_BR_AUDIO_IDIOMA = ['portugu']

/*
 * Códigos **colados** de idioma PT-BR, espelho de `TAGS_CODIGO`. Diferente do
 * "pt" solto, estas formas não se confundem com a legenda, então provam o áudio
 * por si.
 */
const INDICIOS_PT_BR_CODIGO = ['pt-br', 'pt_br', 'pt br', 'ptbr', 'br-pt']

/*
 * Marcas de legenda, espelho de `TAGS_LEGENDA`. As inglesas entram porque o
 * release que motivou a correção anunciava `PORTUGUESE.SUBtitles` — a legenda é
 * em português, o áudio é o original.
 */
const INDICIOS_LEGENDA = [
  'legendado',
  'legendada',
  'legenda',
  'legendas',
  'subtitulado',
  'subtitulada',
  'subtitle',
  'subtitles',
]

// Bandeira do Brasil: dois code points (regional indicators B e R). É prova de
// áudio — quem publica com a bandeira no nome está dizendo "isto é brasileiro".
const EMOJI_BRASIL = '\u{1F1E7}\u{1F1F7}'

/**
 * Diz se o texto anuncia legenda — a marca portuguesa ou a inglesa.
 *
 * Espelho de `IndiciosPtBr::marcaLegendado()`. "SUBS"/"SUB" só contam como
 * palavra: "subwoofer" e "subsídio" não anunciam legenda.
 */
function marcaLegenda(normalizado) {
  if (INDICIOS_LEGENDA.some((tag) => normalizado.includes(tag))) return true

  return /(?<![a-z0-9])subs?(?![a-z0-9])/.test(normalizado)
}

/**
 * Diz se o texto traz uma **marca** de áudio PT-BR.
 *
 * Espelho de `App\Support\IndiciosPtBr::temProvaFortePtBr()`: é a única prova
 * aceita. A marca explícita de áudio vence a de legenda; a legenda, por sua vez,
 * anula as pistas ambíguas (idioma nomeado e código colado). O marcador genérico
 * de multi-faixa ("dual", "multi áudio") e o "pt" solto ficam de fora.
 */
function temProvaFortePtBr(texto) {
  if (!texto) return false
  if (texto.includes(EMOJI_BRASIL)) return true

  const normalizado = texto.toLowerCase()

  if (INDICIOS_PT_BR_AUDIO_EXPLICITO.some((tag) => normalizado.includes(tag))) {
    return true
  }

  if (marcaLegenda(normalizado)) return false

  if (INDICIOS_PT_BR_AUDIO_IDIOMA.some((tag) => normalizado.includes(tag))) {
    return true
  }

  return INDICIOS_PT_BR_CODIGO.some((tag) => normalizado.includes(tag))
}

/**
 * Decide se um texto (nome do torrent, caminho de arquivo) indica áudio PT-BR.
 *
 * Espelho de [`IdiomaFonte::deduzirDoTitulo()`]: só a marca de áudio PT-BR conta.
 * Um "[Dual Audio] ... [Eng Sub]" (japonês + inglês) ou um "pt" solto não provam
 * dublagem, e o pack deixa de ser reconhecido como nacional — que é o resultado
 * desejado: melhor o pack cair para o original com legenda do que ser oferecido
 * como "Dublado" e o ffprobe reprovar na hora de tocar.
 */
export function contemIndicioPtBr(texto) {
  return temProvaFortePtBr(texto)
}
