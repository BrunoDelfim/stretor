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

/** Intervalo entre as consultas de status da sessão de reprodução, em ms. */
export const INTERVALO_STATUS_SESSAO_MS = 1500

/**
 * Tempo máximo aguardando uma única fonte ficar pronta, em ms (90 segundos).
 *
 * Antes usávamos 5 minutos por fonte. Com várias fontes na fila, uma fonte
 * morta prendia o usuário por minutos antes de tentar a próxima. Noventa
 * segundos cobrem com folga a conexão do torrent e o buffer inicial do HLS.
 */
export const TIMEOUT_FONTE_MS = 90 * 1000

/**
 * Tempo de estagnação tolerado numa fonte antes de abandoná-la, em ms (20 s).
 *
 * O `TIMEOUT_FONTE_MS` sozinho não resolve o caso mais comum: uma fonte com
 * poucos peers que *conecta* mas não entrega bytes. Ela não gera erro — o
 * torrent fica vivo, só que a 0 MB/s — e prendia o usuário pelos 90 s inteiros
 * antes de trocar. Aqui olhamos a telemetria de download: se nada foi baixado e
 * a velocidade segue zerada por este tempo, a fonte está morta na prática e
 * passamos para a próxima.
 */
export const ESTAGNACAO_FONTE_MS = 20 * 1000

/**
 * Tempo máximo aguardando o Plyr emitir o evento `ready`, em ms.
 *
 * O Plyr pode montar sem disparar o evento (elemento já controlado, erro
 * interno). Sem esse limite, a espera travava o fluxo de fontes para sempre.
 */
export const TIMEOUT_PLYR_READY_MS = 8000
