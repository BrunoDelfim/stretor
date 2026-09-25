<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import Plyr from 'plyr'
import Hls from 'hls.js'
import 'plyr/dist/plyr.css'

import { streamingService } from '@/services/streaming'
import {
  INTERVALO_STATUS_SESSAO_MS,
  TIMEOUT_FONTE_MS,
  TIMEOUT_PLYR_READY_MS,
} from '@/constants/ui'

const props = defineProps({
  filme: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['fechar'])

const aberto = computed(() => props.filme !== null)

/*
 * O fluxo tem quatro estados visíveis para o usuário:
 * - `procurando`  — buscando as fontes no backend;
 * - `tentando`    — percorrendo as fontes ("tentando fonte 2 de 7");
 * - `preparando`  — a fonte conectou e o vídeo está sendo convertido;
 * - `reproduzindo`— a playlist está pronta e o player foi liberado.
 * `erro` cobre a falha total (nenhuma fonte conectou).
 */
const estado = ref('procurando')
const mensagem = ref('Procurando fontes...')
const tentativaAtual = ref(0)
const totalFontes = ref(0)
const erro = ref(null)

/*
 * Telemetria do download, exibida no overlay enquanto a fonte prepara o vídeo.
 * Sem ela o usuário não distingue uma fonte morta (0 peers) de uma apenas
 * lenta — as duas mostram "Aguardando dados da fonte..." e percentual 0.
 */
const download = ref(null)

/*
 * Provedor e idioma da fonte em uso, exibidos no overlay.
 *
 * Sem esse rótulo um filme que abre com áudio em inglês não diz de onde veio:
 * não dá para saber se o indexador PT-BR devolveu algo e a classificação de
 * idioma falhou, ou se ele não devolveu nada e a reserva em inglês (YTS)
 * assumiu. O rótulo responde à pergunta na própria tela.
 */
const rotuloFonte = ref(null)

/*
 * Idioma real da faixa de áudio, lido pelo ffprobe no media-service.
 *
 * É a confirmação da dublagem: o nome do arquivo no torrent promete PT-BR, mas
 * quem diz o que vai tocar é a faixa. O selo fica visível sobre o vídeo
 * justamente para denunciar o caso em que a promessa não se cumpre.
 */
const idiomaConfirmado = ref(null)

const elementoVideo = ref(null)
const urlPlaylist = ref(null)

/*
 * Sinaliza que um reposicionamento de conversão está em curso no servidor.
 *
 * Durante a busca do trecho o container do player sai de cena (o estado volta a
 * `preparando`), então a barra de "fonte X de Y" não descreve mais o que está
 * acontecendo: esta bandeira isola essa etapa da contagem de tentativas de fonte.
 */
const buscandoTrecho = ref(false)

/*
 * Duração total do filme, lida pelo ffprobe no media-service.
 *
 * Enquanto a conversão corre a playlist é `EVENT`, e o hls.js a trata como
 * transmissão ao vivo: reporta `Infinity` e o Plyr não monta a barra de
 * progresso. Guardamos o valor real para informar ao player qual é o fim da
 * timeline, permitindo seek e exibição do tempo total antes do fim da conversão.
 */
const duracaoTotal = ref(null)

/*
 * Onde o zero da timeline atual está no filme, em segundos.
 *
 * Vale zero enquanto a conversão corre do começo. Depois de uma busca além do
 * trecho convertido o servidor reinicia a conversão no ponto pedido e a playlist
 * nova começa em zero — este deslocamento diz quanto do filme ficou para trás,
 * para o player calcular a duração restante e converter o alvo da barra em tempo
 * de filme ao pedir um novo reposicionamento.
 */
let tempoBase = 0

let player = null
let instanciaHls = null
let timerStatus = null
let sessaoId = null
let cancelado = false

/*
 * Contador de geração do fluxo.
 *
 * Cada abertura (ou troca de fonte) recebe um número próprio. Todo passo
 * assíncrono guarda o seu e confere antes de agir, de modo que um fluxo antigo
 * ainda esperando o status de uma sessão já descartada não consiga mexer no
 * player do filme novo. Era esse resíduo que fazia a limpeza atrasada do filme
 * anterior destruir o player do filme atual.
 */
let geracao = 0

/*
 * Marca se o playhead já foi ancorado no primeiro trecho recebido. O ajuste
 * acontece uma única vez por reprodução: repeti-lo a cada trecho desfaria uma
 * busca do usuário.
 */
let primeiroTrechoAncorado = false

/*
 * Insistência no trecho buscado.
 *
 * A conversão publica os segmentos na ordem do filme, então um salto para a
 * frente pode cair além do que já foi convertido: o arquivo ainda não existe no
 * servidor e o pedido do hls.js volta 404. Depois de esgotar as próprias
 * tentativas ele emite um erro fatal, mas a fonte está saudável — falta só o
 * trecho chegar. Nessa situação retomamos a carga do ponto buscado em
 * intervalos, até o segmento aparecer. O limite evita insistir para sempre num
 * fluxo realmente quebrado.
 */
let tentativasDeTrecho = 0
let timerTrecho = null

/*
 * Alvo de uma busca em andamento, em segundos.
 *
 * O Plyr mede a barra com a duração real do filme, mas o `<video>` só consegue
 * buscar dentro do trecho já convertido: o browser clampeia qualquer
 * `currentTime` para o fim do range `seekable` e o cursor volta para trás.
 * Guardamos aqui o alvo pretendido, capturado antes do clamp, para conduzir a
 * carga do hls.js a partir dele e devolver o playhead ao ponto certo assim que
 * o trecho correspondente chegar.
 */
let alvoDeSeek = null

const LIMITE_TENTATIVAS_DE_TRECHO = 60
const INTERVALO_TENTATIVA_DE_TRECHO_MS = 2000

/*
 * Borda "ao vivo" fixada no playhead.
 *
 * Enquanto a conversão corre a playlist é `EVENT` e ainda não tem
 * `#EXT-X-ENDLIST`, então o hls.js marca `details.live = true` — o `live` só
 * vira `false` quando o `#EXT-X-ENDLIST` aparece. Nesse modo ele deriva da
 * playlist crescente uma "borda": a posição de sincronia calculada a partir do
 * último segmento já publicado. Como a playlist cresce junto com a conversão,
 * essa borda vive andando para frente, e o hls.js puxa o playhead para perto
 * dela. Era isso que fazia o filme começar de um ponto diferente a cada
 * abertura e que também desfazia uma busca do usuário para frente.
 *
 * Não existe configuração que desligue esse comportamento, então a borda é
 * fixada onde o usuário está — veja `fixarBordaAoPlayhead()`.
 */

/** Encerra o polling e libera a sessão no servidor. */
async function limparSessao() {
  cancelado = true

  // Subir a geração invalida qualquer passo assíncrono do filme que está saindo.
  geracao += 1

  if (timerStatus) {
    clearTimeout(timerStatus)
    timerStatus = null
  }

  if (timerTrecho) {
    clearTimeout(timerTrecho)
    timerTrecho = null
  }

  /*
   * A sessão é fixada e a referência limpa ANTES do `await`. Essa espera abre uma
   * janela em que um novo filme pode ser aberto; se o identificador continuasse
   * apontando para a sessão antiga até o fim dela, a limpeza atrasada acabaria
   * encerrando a sessão do filme novo.
   */
  const sessao = sessaoId
  sessaoId = null

  if (sessao) {
    try {
      await streamingService.encerrarSessao(sessao)
    } catch {
      // Se o servidor já tiver descartado a sessão, não há o que fazer.
    }
  }
}

/** Destrói o player e o Hls, evitando vazamento entre aberturas. */
function destruirPlayer() {
  if (instanciaHls) {
    instanciaHls.destroy()
    instanciaHls = null
  }

  if (player) {
    player.destroy()
    player = null
  }
}

/**
 * Monta o player e anexa o HLS.
 *
 * Entregamos ao Plyr apenas uma fonte que ele já sabe consumir. Onde o
 * navegador não toca HLS nativamente (Chrome/Firefox), o hls.js faz a ponte;
 * no Safari o próprio Plyr usa o suporte nativo.
 *
 * Esta função NÃO decide se a fonte é boa. A fonte já foi validada quando a
 * playlist ficou pronta no servidor; montar o player é um passo separado. Antes
 * ela devolvia um booleano que o chamador usava para descartar a fonte, e uma
 * falha de montagem (evento `ready` que não dispara, `player.media` nulo)
 * jogava fora uma fonte perfeitamente válida — o fluxo queimava a lista inteira
 * de fontes por um problema de UI.
 */
async function iniciarPlayer(url, minhaGeracao) {
  if (!url) return

  urlPlaylist.value = url

  /*
   * O container do vídeo está sob `v-if="estado === 'reproduzindo'"`. O estado
   * precisa ser esse para o container existir; se ficar em `preparando`, o
   * `nextTick` roda, `elementoVideo` continua `null` e nada monta.
   */
  estado.value = 'reproduzindo'
  mensagem.value = 'Iniciando o player...'

  // Agora sim o container existe; esperamos o Vue aplicá-lo ao DOM.
  await nextTick()

  // Entre o pedido de montagem e este ponto o overlay pode ter fechado ou
  // trocado de fonte; montar agora criaria um player órfão.
  if (minhaGeracao !== geracao) return

  const video = elementoVideo.value

  if (!video) {
    estado.value = 'erro'
    erro.value = 'Não foi possível preparar o player.'
    return
  }

  /*
   * O Plyr precisa ser criado ANTES de anexarmos o HLS.
   *
   * Ao ser instanciado, o Plyr move o `<video>` para dentro do próprio wrapper
   * e assume o controle do elemento. Se o hls.js já estivesse anexado, essa
   * movimentação quebrava a associação com o MediaSource: os segmentos paravam
   * de ser requisitados e o player tentava carregar a fonte por conta própria.
   * Por isso o HLS é anexado no evento `ready`, sobre `player.media`.
   */
  player = new Plyr(video, {
    controls: ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen'],
    settings: ['quality', 'speed'],
    autoplay: true,
    /*
     * Duração "de fachada" do Plyr. Enquanto a playlist é `EVENT`, a duração
     * que o hls.js calcula é apenas a do trecho já convertido, então o Plyr
     * mostraria um tempo total que muda conforme a conversão avança. Passamos o
     * valor real lido pelo ffprobe (quando disponível) para a barra representar
     * o filme inteiro desde o primeiro instante.
     */
    duration: duracaoExibida() ?? undefined,
  })

  /*
   * O evento `ready` do Plyr nem sempre dispara (elemento já controlado, erro
   * interno). Sem o timeout, a espera travava o fluxo de fontes para sempre.
   * Se estourar, seguimos em frente: o Plyr normalmente já montou e o HLS
   * consegue anexar em `player.media` de qualquer forma.
   */
  await new Promise((resolve) => {
    let concluido = false

    const finalizar = () => {
      if (concluido) return
      concluido = true
      clearTimeout(temporizador)
      resolve()
    }

    const temporizador = setTimeout(finalizar, TIMEOUT_PLYR_READY_MS)

    player.once('ready', finalizar)
  })

  if (cancelado || minhaGeracao !== geracao) return

  const midia = player.media

  if (!midia) {
    // Sem mídia o `<video>` fica sem fonte e o navegador passa a resolver a base
    // do documento como mídia — a origem da requisição nua `/media/sessao/<hash>`.
    destruirPlayer()
    estado.value = 'erro'
    erro.value = 'Não foi possível preparar o player.'
    return
  }

  if (midia.canPlayType('application/vnd.apple.mpegurl')) {
    // Safari e iOS tocam HLS nativamente.
    midia.src = url
  } else if (Hls.isSupported()) {
    /*
     * Desligamos o carregamento automático (`autoStartLoad: false`) e damos a
     * partida explicitamente no `MANIFEST_PARSED`, com `startLoad(0)`. Numa
     * playlist `EVENT` o `startPosition` da configuração é descartado — a
     * playlist é lida como transmissão ao vivo e o hls.js escolhe a posição
     * sozinho. Começar a carga pela posição zero, aliada à borda fixada logo
     * abaixo, é o que mantém o filme no começo. O `liveDurationInfinity: false`
     * (padrão) evita que a duração vire `Infinity`, e o `backBufferLength` alto
     * preserva o que já foi assistido, permitindo voltar na barra sem rebuscar
     * tudo.
     */
    // Reprodução nova: o playhead ainda não foi ancorado no primeiro trecho.
    primeiroTrechoAncorado = false

    instanciaHls = new Hls({
      enableWorker: true,
      autoStartLoad: false,
      liveDurationInfinity: false,
      backBufferLength: 90,
    })

    /*
     * Fixamos a borda "ao vivo" no playhead já na criação: o primeiro `tick()`
     * do hls.js consulta essa posição para escolher o trecho inicial, e o
     * `loadSource()` logo abaixo dispara esse ciclo.
     */
    fixarBordaAoPlayhead()

    /*
     * O `attachMedia` vem ANTES do `loadSource`. Na ordem inversa o hls.js
     * começava a buscar a playlist antes de ter um MediaSource associado; o
     * Plyr ainda estava movendo o `<video>` para o próprio wrapper e a
     * associação se perdia — o player abria vazio e os segmentos paravam de ser
     * requisitados depois dos primeiros.
     */
    instanciaHls.attachMedia(midia)

    /*
     * O `play()` só depois do manifesto interpretado. Chamá-lo antes disso
     * esbarrava em um MediaSource ainda sem buffer, e a rejeição era engolida
     * pelo `catch` — o filme ficava parado sem nenhum aviso.
     *
     * O `startLoad(0)` é o que garante o início pelo começo: com o carregamento
     * automático desligado, é ele que define a posição zero antes do primeiro
     * `tick()` do hls.js. Sem isso, a playlist `EVENT` (live) mandaria o playhead
     * para a borda ao vivo.
     */
    instanciaHls.on(Hls.Events.MANIFEST_PARSED, () => {
      if (cancelado || minhaGeracao !== geracao) return

      /*
       * Diagnóstico do manifesto. `live`, o intervalo de sequências e o começo do
       * primeiro trecho mostram se o hls.js está lendo a playlist como ao vivo e
       * de onde pretende partir — a leitura que separa um problema de playlist de
       * um problema de player.
       */
      const detalhes = instanciaHls.levels?.[instanciaHls.currentLevel]?.details

      console.info('[player] manifesto:', {
        live: detalhes?.live,
        startSN: detalhes?.startSN,
        endSN: detalhes?.endSN,
        trechos: detalhes?.fragments?.length,
        primeiroTrecho: detalhes?.fragments?.[0]?.start,
      })

      instanciaHls.startLoad(0)

      aplicarDuracaoReal()

      Promise.resolve(player?.play()).catch(() => {
        if (player) player.muted = true
        Promise.resolve(player?.play()).catch(() => {})
      })
    })

    /*
     * A cada nível atualizado a duração é recalculada. Enquanto a playlist é
     * `EVENT`, o hls.js soma apenas os `#EXTINF` já publicados — o valor cresce
     * junto com a conversão e fica menor que o filme. Reaplicamos a duração real
     * para a barra não encolher a cada atualização.
     */
    instanciaHls.on(Hls.Events.LEVEL_UPDATED, () => {
      if (cancelado || minhaGeracao !== geracao) return

      aplicarDuracaoReal()
    })

    /*
     * Cada trecho que chega reinicia a contagem da insistência: ela existe só
     * para atravessar a janela em que um trecho buscado ainda não foi convertido,
     * e não para mascarar uma fonte que parou de responder.
     */
    instanciaHls.on(Hls.Events.FRAG_LOADED, (_evento, dados) => {
      tentativasDeTrecho = 0

      const inicioDoTrecho = dados?.frag?.start ?? 0

      /*
       * Busca em andamento. Quando o trecho que cobre o alvo é carregado passa a
       * existir buffer para escrevê-lo no playhead — antes disso o elemento
       * recusaria o `currentTime`. É este passo que mantém a bola de progresso
       * onde o usuário soltou, inclusive quando o alvo só é convertido depois.
       */
      if (alvoDeSeek !== null) {
        const fimDoTrecho = inicioDoTrecho + (dados?.frag?.duration ?? 0)

        if (alvoDeSeek >= inicioDoTrecho - 0.5 && alvoDeSeek <= fimDoTrecho + 0.5) {
          const midiaDaBusca = player?.media

          if (midiaDaBusca && Math.abs(midiaDaBusca.currentTime - alvoDeSeek) > 0.5) {
            midiaDaBusca.currentTime = alvoDeSeek
          }

          alvoDeSeek = null
        }
      }

      /*
       * Âncora do início. O primeiro trecho desta reprodução deveria começar no
       * começo do filme. Se ele veio de outro ponto — ou se o relógio já está
       * adiantado —, trazemos o playhead para zero. É o ajuste que faz "assistir"
       * abrir no início real, e não num pedaço do meio com o relógio em 00:00.
       *
       * A âncora vale uma única vez por reprodução, antes de qualquer busca do
       * usuário: daí em diante o playhead é dele.
       */
      if (primeiroTrechoAncorado) return

      primeiroTrechoAncorado = true

      const midia = player?.media

      if (!midia) return

      if (inicioDoTrecho > 0.5 && instanciaHls) {
        console.warn(
          `[player] primeiro trecho em ${inicioDoTrecho.toFixed(2)}s; recarregando do início`
        )

        midia.currentTime = 0
        instanciaHls.startLoad(0)

        return
      }

      if (midia.currentTime > 0.5) {
        console.warn(`[player] playhead em ${midia.currentTime.toFixed(2)}s; ancorando em 0`)

        midia.currentTime = 0
      }
    })

    /*
     * Uma falha fatal aqui é de reprodução, não de fonte: a playlist existe e
     * foi servida. Avisamos o usuário sem mexer no fluxo de fontes, que já
     * terminou quando a playlist ficou pronta.
     *
     * A exceção é o trecho buscado além do que já foi convertido: o pedido volta
     * 404 porque o arquivo ainda não existe, e o hls.js emite um erro fatal após
     * esgotar as próprias tentativas — embora a fonte esteja saudável. Nesse caso
     * retomamos a carga do ponto buscado em intervalos, para a reprodução seguir
     * de onde o usuário soltou o cursor assim que o trecho for publicado.
     */
    instanciaHls.on(Hls.Events.ERROR, (_evento, dados) => {
      // O detalhe é o que distingue um stall de buffer de um erro de rede; sem
      // ele o console não ajuda a diagnosticar a tela preta.
      console.error(
        '[player] erro do hls.js:',
        dados.type,
        dados.details,
        dados.reason ?? '',
        dados.frag ? `trecho=${dados.frag.start}` : ''
      )

      if (!dados.fatal) return

      const trechoIndisponivel =
        dados.type === Hls.ErrorTypes.NETWORK_ERROR &&
        dados.details === Hls.ErrorDetails.FRAG_LOAD_ERROR

      if (trechoIndisponivel && tentativasDeTrecho < LIMITE_TENTATIVAS_DE_TRECHO) {
        tentativasDeTrecho += 1

        if (timerTrecho) clearTimeout(timerTrecho)

        timerTrecho = setTimeout(() => {
          timerTrecho = null

          if (cancelado || minhaGeracao !== geracao || !instanciaHls) return

          /*
           * `startLoad` define a posição de partida antes do próximo `tick()`.
           * Como a borda "ao vivo" está presa ao playhead, a carga continua no
           * ponto buscado em vez de escapar para o fim do trecho convertido.
           */
          instanciaHls.startLoad(player?.media?.currentTime ?? 0)
        }, INTERVALO_TENTATIVA_DE_TRECHO_MS)

        return
      }

      erro.value = 'Não foi possível carregar o vídeo. Tente novamente.'
      estado.value = 'erro'
    })

    instanciaHls.loadSource(url)
  } else {
    erro.value = 'Seu navegador não suporta a reprodução deste vídeo.'
    estado.value = 'erro'
    return
  }

  // Controle explícito do seek: o `seekable` do elemento cobre só o trecho já
  // convertido, então o arrasto precisa ser conduzido por fora. Ver `executarSeek()`.
  ligarControleDeSeek(midia)

  // O Plyr já está montado e o HLS anexado: o overlay pode sair de cena.
  estado.value = 'reproduzindo'
  mensagem.value = ''

  /*
   * O `play()` fica a cargo do evento `MANIFEST_PARSED` (acima), quando o
   * MediaSource já tem buffer. Aqui só cobrimos o Safari, que toca HLS nativo e
   * não passa pelo hls.js.
   */
  if (midia.canPlayType('application/vnd.apple.mpegurl')) {
    Promise.resolve(player.play()).catch(() => {
      player.muted = true
      Promise.resolve(player.play()).catch(() => {})
    })
  }
}

/**
 * Informa ao Plyr a duração real do filme.
 *
 * Enquanto a conversão corre, a playlist é `EVENT` e o hls.js só conhece os
 * segmentos já publicados — a duração que ele calcula é a do trecho convertido,
 * não a do filme. O Plyr então monta uma barra que "cresce" a cada atualização,
 * e o usuário não vê o tempo total.
 *
 * O ffprobe já leu a duração total no media-service e ela chega pelo status da
 * sessão. O Plyr aceita exatamente esse valor como duração "de fachada" pela
 * opção `config.duration`: o getter interno dele usa esse número no lugar do
 * `media.duration` quando ele existe. É a via suportada — bem mais estável do
 * que sobrescrever uma propriedade somente-leitura do `<video>`.
 *
 * Como o Plyr só redesenha os mostradores de tempo nos eventos
 * `durationchange loadeddata loadedmetadata`, disparamos um `durationchange`
 * logo depois de trocar o valor. Sem isso, o texto do tempo total continuaria
 * mostrando a duração antiga.
 */
function duracaoExibida() {
  if (duracaoTotal.value == null) return null

  /*
   * Depois de um reposicionamento a playlist cobre só o que resta do filme, e é
   * isso que a barra deve representar: exibir a duração cheia faria o cursor
   * apontar para além do que existe na timeline local.
   */
  return Math.max(duracaoTotal.value - tempoBase, 0)
}

function aplicarDuracaoReal() {
  const midia = player?.media
  const duracao = duracaoExibida()

  if (!player || !midia || !duracao) return

  if (player.config.duration === duracao) return

  player.config.duration = duracao

  midia.dispatchEvent(new Event('durationchange'))
}

/**
 * Faz o hls.js enxergar a borda "ao vivo" onde a reprodução está.
 *
 * A playlist `EVENT` é lida como transmissão ao vivo, e o hls.js deriva dela um
 * `liveSyncPosition` — a posição para onde ele puxa o playhead em três
 * momentos: ao escolher o trecho inicial (`getInitialLiveFragment()`), ao
 * recomeçar a carga depois de um erro de trecho (`resetStartWhenNotLoaded()`) e
 * ao detectar que a reprodução se afastou da borda (`synchronizeToLiveEdge()`).
 * Como a playlist cresce junto com a conversão, essa borda vive andando para
 * frente.
 *
 * `liveSyncPosition` é um getter da classe `Hls` e não há configuração para
 * substituí-la, então definimos uma versão própria na instância, devolvendo o
 * `currentTime` atual. Com isso os três caminhos apontam para onde o usuário já
 * está: no começo o playhead é zero e o filme abre do zero; durante a
 * reprodução o ajuste de borda vira inócuo; e depois de uma busca a referência
 * passa a ser o ponto buscado, e não o fim do trecho já convertido — a carga
 * segue de onde o usuário soltou o cursor.
 */
function fixarBordaAoPlayhead() {
  if (!instanciaHls) return

  Object.defineProperty(instanciaHls, 'liveSyncPosition', {
    configurable: true,
    get: () => {
      /*
       * Com uma busca em andamento a borda é o próprio alvo: é para lá que o
       * hls.js deve carregar (`getInitialLiveFragment` e
       * `resetStartWhenNotLoaded` consultam esta posição). Terminada a busca, a
       * borda volta a ser o playhead, para não puxar o vídeo de volta ao fim do
       * trecho convertido.
       */
      if (alvoDeSeek !== null) return alvoDeSeek

      const tempo = player?.media?.currentTime

      return Number.isFinite(tempo) ? tempo : 0
    },
  })
}

/**
 * Liga o controle de seek ao player.
 *
 * A playlist `EVENT` faz o hls.js tratar o fluxo como transmissão ao vivo: o
 * `seekable` do elemento cobre só o trecho já convertido. Ao arrastar a barra
 * para além dele o browser clampeia o `currentTime` ao fim do range e o cursor
 * volta para trás — a origem do "não avança".
 *
 * Aqui capturamos a intenção do usuário no próprio input da barra, antes de o
 * Plyr escrever no elemento, e conduzimos a busca por fora do controle padrão.
 */
function ligarControleDeSeek(midia) {
  const entradaSeek = player?.elements?.inputs?.seek

  if (entradaSeek) {
    /*
     * `input` dispara a cada movimento do arrasto. Registramos o alvo e fixamos
     * a borda "ao vivo" nele (via `fixarBordaAoPlayhead`), para o hls.js não
     * puxar o playhead para o fim do trecho convertido com o usuário ainda
     * arrastando.
     */
    entradaSeek.addEventListener('input', () => {
      const alvo = Number(entradaSeek.value)

      if (Number.isFinite(alvo)) {
        alvoDeSeek = alvo
      }
    })

    // `change` marca o fim do arrasto: é o momento de efetivar a busca.
    entradaSeek.addEventListener('change', () => {
      executarSeek(Number(entradaSeek.value))
    })
  }
}

/** Fim do intervalo de tempo que o elemento consegue buscar agora. */
function fimDoSeekable(midia) {
  if (!midia?.seekable?.length) return 0

  return midia.seekable.end(midia.seekable.length - 1)
}

/**
 * Conduz uma busca para o tempo alvo.
 *
 * O alvo é comparado com o fim do `seekable`:
 *
 * - dentro do convertido — o trecho já está em buffer e basta reposicionar;
 * - além dele — o segmento ainda não existe no servidor. `startLoad` põe a carga
 *   no alvo, o hls.js pede o trecho, recebe 404 enquanto a conversão não o
 *   publica e o mecanismo de retomada (veja `Hls.Events.ERROR`) insiste até ele
 *   aparecer. É o que permite arrastar para frente de forma progressiva.
 */
function executarSeek(alvo) {
  const midia = player?.media

  if (!midia || !instanciaHls || !Number.isFinite(alvo)) return

  const fimConvertido = fimDoSeekable(midia)

  /*
   * Dentro do trecho já convertido a busca é local: basta conduzir a carga pelo
   * hls.js e reescrever o playhead. O alvo já está em buffer (ou a caminho dele),
   * então o mecanismo de `FRAG_LOADED` reaplica o cursor quando o trecho chega.
   */
  if (alvo <= fimConvertido + 0.5) {
    alvoDeSeek = alvo

    /*
     * `startLoad` define a posição de partida antes do próximo `tick()`. Como a
     * borda "ao vivo" está presa ao alvo, a carga segue para o ponto buscado em
     * vez de escapar para a borda crescente da conversão.
     */
    instanciaHls.startLoad(alvo)

    if (Math.abs(midia.currentTime - alvo) > 0.5) {
      try {
        midia.currentTime = alvo
      } catch {
        // O elemento pode recusar até o buffer cobrir o alvo.
      }
    }

    return
  }

  /*
   * Além do convertido o trecho simplesmente ainda não existe no servidor, e
   * insistir não adianta: a conversão sequencial levaria minutos para alcançar o
   * ponto. O alvo da barra está na timeline local, então somamos o deslocamento
   * para pedir ao servidor o tempo de filme correspondente.
   */
  buscarTrechoRemoto(tempoBase + alvo).catch(() => {})
}

/**
 * Reposiciona a conversão no servidor para um tempo de filme.
 *
 * Pede ao media-service que descarte os segmentos já convertidos e reinicie a
 * conversão a partir do alvo, publicando uma playlist nova que começa em zero
 * naquele ponto — assim o trecho buscado fica disponível em segundos, em vez de
 * esperar a conversão percorrer o filme inteiro. Enquanto isso o overlay mostra
 * "Buscando o trecho..." e, ao final, o player é remontado sobre a nova timeline.
 */
async function buscarTrechoRemoto(tempoFilme) {
  const minhaGeracao = geracao
  const sessao = sessaoId

  if (!sessao) return

  // A timeline inteira vai mudar: a insistência num trecho antigo perde sentido.
  if (timerTrecho) {
    clearTimeout(timerTrecho)
    timerTrecho = null
  }

  tentativasDeTrecho = 0
  alvoDeSeek = null

  try {
    player?.pause?.()
  } catch {
    // O player pode já ter sido desmontado.
  }

  /*
   * O player atual aponta para uma playlist que acabou de ser apagada no
   * servidor. Derrubamos antes de trocar o estado, para o container do vídeo sair
   * de cena sem deixar um Hls vivo recarregando segmentos inexistentes.
   */
  destruirPlayer()

  buscandoTrecho.value = true
  estado.value = 'preparando'
  mensagem.value = 'Buscando o trecho...'

  try {
    await streamingService.solicitarSeek(sessao, tempoFilme)
  } catch {
    if (!cancelado && minhaGeracao === geracao) {
      buscandoTrecho.value = false
      erro.value = 'Não foi possível buscar este trecho. Tente novamente.'
      estado.value = 'erro'
    }

    return
  }

  const status = await aguardarReposicionamento(sessao, minhaGeracao)

  buscandoTrecho.value = false

  if (cancelado || minhaGeracao !== geracao) return

  /*
   * Sem status o reposicionamento falhou ou estourou o tempo. Deixar o overlay
   * em `preparando` prenderia o usuário num spinner eterno sem saída.
   */
  if (!status) {
    erro.value = 'Não foi possível buscar este trecho. Tente novamente.'
    estado.value = 'erro'
    return
  }

  // O servidor informa onde ficou o novo zero; sem isso a duração restante sairia
  // errada e o próximo alvo da barra seria convertido para o tempo de filme errado.
  tempoBase = status.tempo_base ?? tempoFilme

  const url = streamingService.urlPlaylist(status.playlist)

  if (!url) {
    erro.value = 'Não foi possível remontar o vídeo a partir deste ponto.'
    estado.value = 'erro'
    return
  }

  duracaoTotal.value = status.duracao ?? duracaoTotal.value
  // O idioma é do mesmo arquivo, mas atualizamos aqui caso a faixa só tenha
  // ficado legível agora (o preparo pode expor o status antes do ffprobe).
  idiomaConfirmado.value = status.idioma_audio_rotulo ?? idiomaConfirmado.value

  await iniciarPlayer(url, minhaGeracao)
}

/**
 * Aguarda a playlist ser reconstruída depois de um reposicionamento.
 *
 * Devolve o status quando a sessão volta a ficar `pronto`, ou `null` em caso de
 * erro, timeout ou cancelamento. O polling é próprio desta etapa — não usa o
 * `timerStatus` do fluxo de fontes, que já terminou quando a reprodução começou.
 */
function aguardarReposicionamento(sessao, minhaGeracao) {
  const inicio = Date.now()

  return new Promise((resolve) => {
    const consultar = async () => {
      if (cancelado || minhaGeracao !== geracao) return resolve(null)

      if (Date.now() - inicio > TIMEOUT_FONTE_MS) return resolve(null)

      try {
        const status = await streamingService.statusSessao(sessao)

        if (cancelado || minhaGeracao !== geracao) return resolve(null)

        if (status.status === 'erro') return resolve(null)

        if (status.status === 'pronto' && status.playlist) return resolve(status)
      } catch {
        // Falha pontual: tentamos de novo no próximo ciclo.
      }

      setTimeout(consultar, INTERVALO_STATUS_SESSAO_MS)
    }

    consultar()
  })
}

/**
 * Percorre as fontes em ordem até uma conectar.
 *
 * Dificilmente o filme terá fonte dublada logo na primeira tentativa, então
 * tentamos uma a uma e só desistimos quando a lista inteira falhar.
 *
 * A geração que iniciou a busca é repassada a cada espera: se ela mudar (o
 * overlay fechou ou outro filme entrou), este laço se cala em vez de continuar
 * mexendo no estado do player atual.
 */
async function tentarFontes(fontes, minhaGeracao) {
  for (let indice = 0; indice < fontes.length; indice += 1) {
    if (cancelado || minhaGeracao !== geracao) return

    const fonte = fontes[indice]

    tentativaAtual.value = indice + 1
    estado.value = 'tentando'
    mensagem.value = `Tentando fonte ${indice + 1} de ${fontes.length}...`

    // Provedor de origem + idioma detectado (ex.: "Indexador (Torznab) · Dublado").
    rotuloFonte.value =
      [fonte.provedor_rotulo, fonte.idioma_rotulo].filter(Boolean).join(' · ') || null

    try {
      const sessao = await streamingService.criarSessao(fonte.magnet, props.filme.id)

      if (cancelado || minhaGeracao !== geracao) return

      sessaoId = sessao.sessao_id

      // A sessão foi criada; acompanhamos até ficar pronta ou falhar. Se
      // falhar, o loop segue para a próxima fonte.
      const resultado = await aguardarFonte(minhaGeracao)

      if (resultado === 'pronto') {
        return
      }
    } catch {
      // Fonte indisponível: seguimos para a próxima.
    }
  }

  if (!cancelado && minhaGeracao === geracao) {
    erro.value = 'Nenhuma fonte conseguiu conectar. Tente novamente mais tarde.'
    estado.value = 'erro'
  }
}

/**
 * Monta a mensagem do overlay a partir do status da sessão.
 *
 * O backend manda a mensagem base ("Aguardando dados da fonte..."), mas ela
 * sozinha não distingue uma fonte morta de uma lenta. Quando há telemetria de
 * download, acrescentamos peers e velocidade para o usuário saber se vale
 * esperar ou trocar de fonte.
 */
function mensagemDeProgresso(status) {
  const base = status.mensagem || 'Preparando o vídeo...'
  const info = status.download

  if (!info) return base

  // Sem peers não há de onde baixar: avisamos em vez de deixar o usuário
  // esperando por uma fonte que não vai responder.
  if (info.peers === 0) {
    return `${base} (sem peers)`
  }

  if (info.velocidade > 0) {
    const mbps = (info.velocidade / 1024 / 1024).toFixed(2)

    return `${base} — ${info.peers} peers, ${mbps} MB/s`
  }

  return `${base} — ${info.peers} peers`
}

/**
 * Aguarda o desfecho de uma única fonte.
 *
 * Devolve `pronto` quando a playlist ficou disponível no servidor, ou `falhou`
 * para o chamador seguir para a próxima fonte. A montagem do player não entra
 * nessa decisão: uma vez que a playlist existe, a fonte é válida.
 *
 * A sessão desta fonte é capturada de uma vez, em vez de lida da variável
 * compartilhada a cada consulta: assim uma troca de filme no meio do caminho
 * não faz este laço consultar (nem encerrar) a sessão de outro filme.
 */
function aguardarFonte(minhaGeracao) {
  const inicio = Date.now()
  const sessaoDaFonte = sessaoId

  return new Promise((resolve) => {
    const consultar = async () => {
      if (cancelado || minhaGeracao !== geracao) return resolve('cancelado')

      if (Date.now() - inicio > TIMEOUT_FONTE_MS) {
        await limparSessaoAtual()
        return resolve('falhou')
      }

      try {
        const status = await streamingService.statusSessao(sessaoDaFonte)

        if (cancelado || minhaGeracao !== geracao) return resolve('cancelado')

        if (status.status === 'erro') {
          await limparSessaoAtual()
          return resolve('falhou')
        }

        if (status.status === 'pronto' && status.playlist) {
          const url = streamingService.urlPlaylist(status.playlist)

          /*
           * A duração vem do ffprobe no media-service. Guardamos antes de montar
           * o player porque a playlist `EVENT` ainda não declara o fim — sem
           * esse valor o Plyr não teria como dimensionar a barra de progresso.
           */
          duracaoTotal.value = status.duracao ?? null
          // Numa sessão recém-criada o zero é o começo do filme; lemos ainda assim
          // para o player ficar consistente caso a sessão já venha deslocada.
          tempoBase = status.tempo_base ?? 0
          // Idioma real da faixa, confirmado pelo ffprobe sobre o arquivo da
          // fonte que conectou — não sobre o que o nome do torrent prometia.
          idiomaConfirmado.value = status.idioma_audio_rotulo ?? null

          /*
           * A fonte está boa: a playlist existe e foi servida pelo servidor.
           * Montar o player é um passo separado — se falhar, o erro é de UI e
           * não pode descartar uma fonte válida. Antes, condicionar o sucesso
           * ao retorno de `iniciarPlayer` fazia o fluxo queimar a lista inteira
           * de fontes por uma falha de montagem do Plyr.
           */
          iniciarPlayer(url, minhaGeracao).catch(() => {})

          return resolve('pronto')
        }

        estado.value = 'preparando'
        download.value = status.download ?? null
        duracaoTotal.value = status.duracao ?? duracaoTotal.value
        mensagem.value = mensagemDeProgresso(status)
      } catch {
        // Falha pontual: tentamos de novo no próximo ciclo.
      }

      timerStatus = setTimeout(consultar, INTERVALO_STATUS_SESSAO_MS)
    }

    consultar()
  })
}

/** Encerra só a sessão atual, sem marcar o fluxo inteiro como cancelado. */
async function limparSessaoAtual() {
  if (timerStatus) {
    clearTimeout(timerStatus)
    timerStatus = null
  }

  /*
   * A instância do hls.js precisa morrer junto com a sessão. Sem isso, ao
   * descartar uma fonte e seguir para a próxima, o Hls antigo continuava vivo
   * tentando recarregar a playlist de uma sessão já apagada. Como ele resolve
   * os caminhos relativos contra a base do documento, sobrava uma requisição
   * nua `/media/sessao/<hash>` — sem `/playlist.m3u8` — que o Express não tem
   * rota para atender.
   */
  destruirPlayer()

  /*
   * O container do vídeo está sob `v-if="estado === 'reproduzindo'"`. Ao voltar
   * o estado para `preparando`, o Vue desmonta o container e descarta o
   * `<video>` que o Plyr havia manipulado. A próxima fonte então monta um
   * elemento novo, em vez de reaproveitar um nó desanexado.
   */
  estado.value = 'preparando'

  /*
   * Mesma precaução de `limparSessao`: fixamos a sessão e limpamos a referência
   * antes do `await`, para que a espera não deixe a variável apontando para uma
   * sessão que já não é desta fonte.
   */
  const sessao = sessaoId
  sessaoId = null

  if (sessao) {
    try {
      await streamingService.encerrarSessao(sessao)
    } catch {
      // Sessão já descartada pelo servidor.
    }
  }
}

/** Inicia o fluxo completo: busca as fontes e percorre até uma conectar. */
async function iniciar() {
  cancelado = false
  erro.value = null
  estado.value = 'procurando'
  mensagem.value = 'Procurando fontes...'
  tentativaAtual.value = 0
  totalFontes.value = 0
  download.value = null
  // O rótulo da fonte pertence ao filme anterior.
  rotuloFonte.value = null
  // O idioma confirmado pertencia ao arquivo anterior.
  idiomaConfirmado.value = null
  // A duração pertence ao filme anterior; sem zerar, a barra do novo filme
  // herdaria o tamanho do antigo até o status trazer o valor correto.
  duracaoTotal.value = null
  tentativasDeTrecho = 0
  // Uma busca pendente pertence ao filme anterior.
  alvoDeSeek = null
  // A timeline do filme novo começa do zero.
  tempoBase = 0
  buscandoTrecho.value = false

  // Cada abertura é uma geração nova: os passos da anterior passam a ser ignorados.
  geracao += 1
  const minhaGeracao = geracao

  try {
    const { fontes, mensagem: aviso } = await streamingService.buscarFontes(props.filme)

    if (cancelado || minhaGeracao !== geracao) return

    if (!fontes.length) {
      // O backend explica o motivo quando a lista volta vazia: "nenhuma fonte
      // encontrada" é ausência de release, "nenhum provedor pôde ser consultado"
      // é configuração faltando. Sem essa distinção o usuário não sabe se o
      // problema é o filme ou o sistema.
      erro.value = aviso || 'Nenhuma fonte encontrada para este título no momento.'
      estado.value = 'erro'
      return
    }

    totalFontes.value = fontes.length

    await tentarFontes(fontes, minhaGeracao)
  } catch {
    if (!cancelado && minhaGeracao === geracao) {
      erro.value = 'Não foi possível buscar as fontes agora. Tente novamente.'
      estado.value = 'erro'
    }
  }
}

function fechar() {
  emit('fechar')
}

function aoTeclar(evento) {
  if (evento.key === 'Escape') fechar()
}

// O fluxo só começa quando o overlay abre, e é desmontado ao fechar.
watch(
  aberto,
  async (estaAberto) => {
    document.body.style.overflow = estaAberto ? 'hidden' : ''

    if (estaAberto) {
      window.addEventListener('keydown', aoTeclar)
      await iniciar()
    } else {
      window.removeEventListener('keydown', aoTeclar)
      destruirPlayer()
      await limparSessao()
    }
  },
  { immediate: true }
)

onUnmounted(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', aoTeclar)
  destruirPlayer()
  limparSessao()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="aberto"
        class="fixed inset-0 z-[70] flex flex-col items-center justify-center bg-black p-4"
      >
        <button
          type="button"
          class="absolute left-4 top-4 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
          aria-label="Fechar player"
          @click="fechar"
        >
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
          </svg>
        </button>

        <!--
          Enquanto o vídeo não está pronto, o overlay mostra o progresso da
          busca e da conversão. O player só é montado quando a playlist chega.
        -->
        <div v-if="estado !== 'reproduzindo'" class="flex flex-col items-center gap-6 text-center">
          <div
            v-if="estado !== 'erro'"
            class="h-12 w-12 animate-spin rounded-full border-4 border-white/15 border-t-brand-500"
            role="status"
            aria-label="Carregando"
          />

          <div class="space-y-2">
            <p class="text-lg font-semibold text-white">
              {{ filme?.titulo }}
            </p>
            <p v-if="estado === 'erro'" class="max-w-md text-sm text-red-300">{{ erro }}</p>
            <p v-else class="text-sm text-slate-300">{{ mensagem }}</p>
          </div>

          <!-- Barra de progresso das tentativas: deixa claro que o sistema
               está percorrendo as fontes, não travado. -->
          <div v-if="totalFontes > 0 && estado !== 'erro' && !buscandoTrecho" class="w-64 space-y-2">
            <div class="h-1 w-full overflow-hidden rounded-full bg-white/10">
              <span
                class="block h-full rounded-full bg-brand-500 transition-all duration-300"
                :style="{ width: `${(tentativaAtual / totalFontes) * 100}%` }"
              />
            </div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">
              Fonte {{ tentativaAtual }} de {{ totalFontes }}
            </p>
            <!--
              Origem e idioma da fonte em teste. É o que explica um áudio em
              inglês, mostrando se ela veio do indexador PT-BR ou da reserva.
            -->
            <p v-if="rotuloFonte" class="text-xs text-slate-400">{{ rotuloFonte }}</p>
          </div>
        </div>

        <!--
          O Plyr recebe apenas uma fonte HLS válida. Nada de manipular o player:
          ele já sabe consumir `application/x-mpegURL`.

          Usamos `v-if` (e não `v-show`) para que o elemento só exista no DOM
          quando o estado permitir. Com `v-show`, o Plyr media um container com
          `display: none` e montava os controles sem altura.
        -->
        <div
          v-if="estado === 'reproduzindo'"
          class="player-wrapper relative aspect-video w-full max-w-6xl"
        >
          <!--
            Selo com o idioma real da faixa de áudio, lido pelo ffprobe. Sem ele
            não dá para saber se a fonte entregou o áudio que o nome do arquivo
            prometia — é a diferença entre supor a dublagem e confirmá-la.
          -->
          <span
            v-if="idiomaConfirmado"
            class="absolute left-3 top-3 z-10 rounded-full bg-black/60 px-3 py-1 text-xs font-medium text-white"
          >
            Áudio: {{ idiomaConfirmado }}
          </span>

          <video ref="elementoVideo" class="h-full w-full" playsinline />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/*
 * O container define a proporção 16:9 via `aspect-video`, mas o Plyr cria o
 * próprio wrapper (`.plyr`) dentro dele. Sem forçar a altura, o wrapper não
 * herda a área do container e o vídeo colapsa para a altura mínima do
 * elemento `<video>`.
 */
.player-wrapper :deep(.plyr) {
  height: 100%;
  width: 100%;
}

.player-wrapper :deep(.plyr__video-wrapper) {
  height: 100%;
}
</style>
