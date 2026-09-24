/**
 * Constantes de domínio dos filmes.
 *
 * Espelha os enums do backend (`app/Enums/`) para que filtros, rótulos e
 * limites de exibição usem os mesmos valores dos dois lados da API. Os objetos
 * são congelados para impedir mutação acidental em tempo de execução.
 */

/** Gêneros do TMDB — id numérico para o rótulo em português. */
export const GENEROS = Object.freeze({
  28: 'Ação',
  12: 'Aventura',
  16: 'Animação',
  35: 'Comédia',
  80: 'Crime',
  99: 'Documentário',
  18: 'Drama',
  10751: 'Família',
  14: 'Fantasia',
  36: 'História',
  27: 'Terror',
  10402: 'Música',
  9648: 'Mistério',
  10749: 'Romance',
  878: 'Ficção Científica',
  10770: 'Cinema TV',
  53: 'Suspense',
  10752: 'Guerra',
  37: 'Faroeste',
})

/** Classificações indicativas válidas no Brasil. */
export const CLASSIFICACOES = Object.freeze(['L', '10', '12', '14', '16', '18'])

/** Tamanhos de imagem da CDN do TMDB. */
export const TAMANHOS_IMAGEM = Object.freeze({
  POSTER: 'w500',
  BACKDROP: 'w1280',
  ORIGINAL: 'original',
})

/** Quantidade de títulos exibidos no carrossel de destaques. */
export const LIMITE_DESTAQUES = 6

/** Quantidade de nomes do elenco exibidos no modal. */
export const LIMITE_ELENCO = 5

/** Idioma padrão da interface e das legendas do trailer. */
export const IDIOMA_PADRAO = 'pt-BR'

/** Código curto do idioma usado ao pedir legendas ao YouTube. */
export const IDIOMA_LEGENDA = 'pt'
