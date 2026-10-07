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
 * Tempo, em ms (90 segundos), que uma fonte torrent pode ficar **sem baixar um
 * byte novo** antes de ser abandonada.
 *
 * A régua é o progresso, não o relógio: cada byte que chega reinicia este prazo
 * (ver `aguardarFonte`). Uma fonte viva com poucos seeds baixa devagar, mas
 * baixa — e antes ela esgotava os 90 s fixos antes do buffer inicial e era
 * trocada no meio de um download que estava andando, o mesmo erro que o
 * media-service já havia corrigido na espera por peças. Agora só a estagnação
 * real (90 s sem byte novo) derruba a fonte; uma fonte morta, que nunca baixou,
 * ainda cai bem antes pelo `ESTAGNACAO_FONTE_MS`.
 *
 * Ajustável por `VITE_TIMEOUT_FONTE_MS`.
 */
export const TIMEOUT_FONTE_MS = Number(import.meta.env.VITE_TIMEOUT_FONTE_MS) || 90 * 1000

/**
 * Tempo de estagnação tolerado numa fonte antes de abandoná-la, em ms (20 s).
 *
 * O `TIMEOUT_FONTE_MS` sozinho não resolve o caso mais comum: uma fonte com
 * poucos peers que *conecta* mas não entrega bytes. Ela não gera erro — o
 * torrent fica vivo, só que a 0 MB/s — e prendia o usuário pelos 90 s inteiros
 * antes de trocar. Aqui olhamos a telemetria de download: se nada foi baixado e
 * a velocidade segue zerada por este tempo, a fonte está morta na prática e
 * passamos para a próxima.
 *
 * Só vale para fonte que **nunca entregou um byte**. Uma vez que a fonte baixou
 * algo, ela provou estar viva e não é mais abandonada por esta estagnação curta
 * — passa a valer o `TIMEOUT_FONTE_MS`, que também se renova a cada byte novo.
 * Sem essa distinção, uma conexão lenta derrubava fontes boas por causa de uma
 * pausa momentânea.
 *
 * Ajustável por `VITE_ESTAGNACAO_FONTE_MS`.
 */
export const ESTAGNACAO_FONTE_MS = Number(import.meta.env.VITE_ESTAGNACAO_FONTE_MS) || 20 * 1000

/**
 * Tempo máximo aguardando uma fonte **direta** ficar pronta, em ms (5 minutos).
 *
 * A fonte direta não vem de uma malha P2P: o media-service baixa a URL de um
 * servidor HTTP e converte para HLS. Esse caminho é mais lento que o torrent e
 * não tem telemetria de peers para medir vida — o que prova que ela está
 * trabalhando é o progresso da conversão. O `TIMEOUT_FONTE_MS` (90 s), pensado
 * para torrent, cortava a conversão no meio e o overlay mostrava "sem peers"
 * num vídeo que estava apenas sendo preparado. Aqui o teto é bem mais largo e,
 * na prática, quem encerra antes é a estagnação do progresso (abaixo).
 *
 * Ajustável por `VITE_TIMEOUT_DIRETO_MS`.
 */
export const TIMEOUT_DIRETO_MS = Number(import.meta.env.VITE_TIMEOUT_DIRETO_MS) || 5 * 60 * 1000

/**
 * Tempo de estagnação tolerado numa fonte direta, em ms (60 s).
 *
 * O equivalente direto do `ESTAGNACAO_FONTE_MS`: enquanto o `progresso` da
 * conversão muda, a fonte está viva e não é abandonada. Se o percentual parar
 * de avançar por este tempo, a conversão travou de verdade (servidor parou de
 * entregar bytes, FFmpeg preso) e passamos para a próxima fonte. É mais largo
 * que o do torrent porque uma conversão pode ter pausas longas entre trechos
 * sem estar morta.
 *
 * Ajustável por `VITE_ESTAGNACAO_DIRETA_MS`.
 */
export const ESTAGNACAO_DIRETA_MS = Number(import.meta.env.VITE_ESTAGNACAO_DIRETA_MS) || 60 * 1000

/**
 * Quantas vezes a **mesma** fonte é tentada de novo quando a falha foi do caminho.
 *
 * A falha de rede na fonte direta é curta e passa sozinha: o nome não resolveu
 * naquele instante, a conversão caiu na abertura, o CDN recusou por um momento.
 * Insistir na mesma fonte custa poucos segundos — o media-service desiste rápido
 * quando o erro é dele — e evita o que o usuário tinha de fazer antes: fechar e
 * reabrir o player, que era justamente o que fazia o episódio tocar na segunda
 * vez. Cada tentativa é uma sessão nova, com conexão e resolução de nome novas.
 */
export const TENTATIVAS_FONTE_REDE = 3

/**
 * Espera entre duas tentativas da mesma fonte, em ms.
 *
 * Curta de propósito: o blip de rede que motiva a repetição dura segundos, e uma
 * espera longa só adiaria a reprodução quando a fonte já está saudável de novo.
 */
export const ESPERA_ENTRE_TENTATIVAS_MS = 1500

/**
 * Tempo máximo aguardando o Plyr emitir o evento `ready`, em ms.
 *
 * O Plyr pode montar sem disparar o evento (elemento já controlado, erro
 * interno). Sem esse limite, a espera travava o fluxo de fontes para sempre.
 */
export const TIMEOUT_PLYR_READY_MS = 8000
