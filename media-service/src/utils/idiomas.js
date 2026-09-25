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
