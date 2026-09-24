/**
 * Constantes de interface.
 *
 * Reúne os tempos, limites e medidas que antes ficavam soltos dentro dos
 * componentes. Centralizar aqui deixa explícito o que é ajuste de UX e evita
 * que o mesmo número apareça duplicado em telas diferentes.
 */

/** Quantidade de posições (indicadores) do carrossel de destaques. */
export const TOTAL_INDICADORES_CARROSSEL = 6

/** Intervalo entre as trocas automáticas de slide, em milissegundos. */
export const INTERVALO_AUTOPLAY_MS = 7000

/** Espera antes de disparar a busca enquanto o usuário digita, em milissegundos. */
export const DEBOUNCE_BUSCA_MS = 400

/** Scroll (px) em que a navbar começa a ganhar fundo sólido. */
export const INICIO_SOLIDIFICACAO = 20

/** Scroll (px) em que a transição de fundo da navbar se completa. */
export const FIM_SOLIDIFICACAO = 220

/** Margem antecipada da sentinela de rolagem infinita. */
export const MARGEM_SENTINELA = '800px'

/** Quantidade de cards fantasma exibidos durante o carregamento. */
export const QUANTIDADE_SKELETONS = 6
