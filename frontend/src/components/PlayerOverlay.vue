<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import Plyr from 'plyr'
import Hls from 'hls.js'
import 'plyr/dist/plyr.css'

import { streamingService } from '@/services/streaming'
import {
  ESTAGNACAO_DIRETA_MS,
  ESTAGNACAO_FONTE_MS,
  INTERVALO_STATUS_SESSAO_MS,
  TIMEOUT_DIRETO_MS,
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
 * Título exibido durante o carregamento. Para filme é só o nome; para episódio
 * de série acrescentamos a numeração e o nome do episódio, senão o usuário não
 * teria como saber qual episódio está sendo preparado.
 */
const tituloExibicao = computed(() => {
  const titulo = props.filme?.titulo ?? ''

  if (!props.filme?.temporada || !props.filme?.episodio) {
    return titulo
  }

  const numeracao = `S${String(props.filme.temporada).padStart(2, '0')}E${String(props.filme.episodio).padStart(2, '0')}`
  const nomeEpisodio = props.filme.episodio_titulo ? ` · ${props.filme.episodio_titulo}` : ''

  return `${titulo} — ${numeracao}${nomeEpisodio}`
})

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
 * Diagnóstico visível na tela.
 *
 * O console do navegador nem sempre está à mão (ou o usuário não o abre), e
 * "o filme não começou" chega sem nenhuma pista. Este painel mostra, sobre o
 * vídeo, o que o player está fazendo: se o manifesto foi lido, quantos trechos
 * existem, se há buffer, se o `play()` foi recusado. É temporário e deve sair
 * quando o fluxo estiver estável.
 */
const diagnostico = ref([])

function anotarDiagnostico(texto) {
  const carimbo = new Date().toLocaleTimeString('pt-BR')

  diagnostico.value = [...diagnostico.value.slice(-7), `${carimbo} ${texto}`]

  console.info('[player]', texto)
}

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
 * Contador de aberturas.
 *
 * Separado de `geracao` de propósito: `limparSessao()` também avança a geração,
 * então ela não serve para identificar "qual abertura é a mais nova". Este
 * contador só cresce no início de `iniciar()`, uma vez por abertura, e é o que
 * permite a uma abertura antiga perceber que outra já começou e se calar.
 */
let abertura = 0

/*
 * Marca se o playhead já foi ancorado no primeiro trecho recebido. O ajuste
 * acontece uma única vez por reprodução: repeti-lo a cada trecho desfaria uma
 * busca do usuário.
 */
let primeiroTrechoAncorado = false

/*
 * Marca se o usuário já conduziu uma busca nesta reprodução.
 *
 * A âncora do início existe para corrigir a escolha automática do primeiro
 * trecho, não para sobrepor a vontade do usuário. Sem esta flag, uma busca feita
 * antes de o primeiro trecho chegar seria desfeita pelo ajuste de âncora — o
 * filme voltaria ao começo logo depois de o usuário arrastar a barra.
 */
let usuarioBuscou = false

/*
 * Marca se a pausa atual foi pedida pelo usuário.
 *
 * O vigia de reprodução existe para vencer a política de autoplay no arranque,
 * não para reimpor a reprodução depois. Sem esta distinção, o `FRAG_BUFFERED`
 * — que dispara a cada trecho novo, e a conversão publica um a cada poucos
 * segundos — encontrava o elemento pausado e dava `play()` de volta: toda pausa
 * do usuário era desfeita no trecho seguinte. Também é o que impede o vigia de
 * atropelar uma busca, quando o elemento fica pausado enquanto o alvo carrega.
 */
let usuarioPausou = false

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

/** Diz se há uma busca em andamento (alvo capturado e ainda não alcançado). */
function buscaEmAndamento() {
  return alvoDeSeek !== null
}

const LIMITE_TENTATIVAS_DE_TRECHO = 60
const INTERVALO_TENTATIVA_DE_TRECHO_MS = 2000

/*
 * Recuperação de erro de mídia (MSE).
 *
 * Um `MEDIA_ERROR` fatal não é rede: o segmento chegou, mas o `SourceBuffer`
 * recusou o conteúdo — codec declarado que não bate com o stream, ou o decoder
 * tropeçou num trecho. O hls.js expõe `recoverMediaError()`, que descarta o
 * `SourceBuffer` atual e cria outro com o mesmo codec, retomando a carga do
 * ponto onde parou. Vale tentar antes de desistir: muitas vezes o primeiro
 * trecho passa e um seguinte é rejeitado, e a recuperação salva a reprodução
 * sem que o usuário perceba. O teto evita insistir num stream realmente
 * incompatível — aí a mensagem de erro é a resposta honesta.
 */
const LIMITE_RECUPERACOES_DE_MIDIA = 3

let recuperacoesDeMidia = 0

/*
 * Vigia do início da reprodução.
 *
 * O primeiro `play()` raramente basta. A política de autoplay do navegador
 * recusa a chamada enquanto não houve gesto do usuário, e o MediaSource só
 * entrega imagem depois que o primeiro trecho foi anexado ao buffer. Em vez de
 * disparar um `play()` único e torcer, um vigia insiste em intervalos até o
 * vídeo sair da pausa — com um teto, para não insistir para sempre num fluxo
 * que realmente parou.
 */
const LIMITE_TENTATIVAS_DE_PLAY = 30
const INTERVALO_VIGIA_DE_PLAY_MS = 1000

let tentativasDePlay = 0
let timerPlay = null

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

/**
 * Encerra o polling e libera a sessão no servidor.
 *
 * Esta função NÃO mexe na geração. Quem avança a geração é `iniciar()`, uma vez
 * por abertura, e é essa marca que os passos assíncronos comparam para saber se
 * ainda são os correntes. Se a limpeza também avançasse o contador, o fluxo novo
 * — que captura a geração logo depois de chamá-la — nasceria já desatualizado e
 * se abortaria sozinho. Aqui só derrubamos o que pertence ao fluxo que está
 * saindo: o cancelamento, os timers e a sessão no servidor.
 */
async function limparSessao() {
  cancelado = true

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
  pararVigia()

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

  anotarDiagnostico(`montando player para ${url}`)

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
    anotarDiagnostico('elemento <video> não existe no DOM')

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
    /*
     * O autoplay fica desligado de propósito. Ligado, o Plyr tentava tocar no
     * instante em que era montado — antes de o HLS existir — e a rejeição era
     * descartada. Quem conduz a reprodução é `vigiarReproducao()`, que só age
     * quando existe buffer para tocar.
     */
    autoplay: false,
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
    anotarDiagnostico('player.media nulo após o ready do Plyr')

    destruirPlayer()
    estado.value = 'erro'
    erro.value = 'Não foi possível preparar o player.'
    return
  }

  /*
   * A ordem importa: o hls.js vem PRIMEIRO.
   *
   * `canPlayType('application/vnd.apple.mpegurl')` devolve `"maybe"` no Chrome,
   * no Edge e no Firefox — não só no Safari. Como `"maybe"` é uma string
   * verdadeira, testar o suporte nativo antes mandava esses navegadores pelo
   * caminho do Safari: o `<video>` recebia a playlist como `src` e ficava
   * parado, porque nenhum deles decodifica HLS por conta própria. O hls.js é
   * quem faz a ponte via MediaSource, então ele tem prioridade sempre que
   * existir. O caminho nativo fica só para o Safari de verdade, onde o hls.js
   * não é suportado.
   */
  if (Hls.isSupported()) {
    anotarDiagnostico('usando hls.js')
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
    // Reprodução nova: o playhead ainda não foi ancorado no primeiro trecho, o
    // usuário ainda não buscou nada nesta timeline e não há pausa pendente.
    primeiroTrechoAncorado = false
    usuarioBuscou = false
    usuarioPausou = false

    instanciaHls = new Hls({
      enableWorker: true,
      autoStartLoad: false,

      /*
       * Enquanto a conversão corre, a playlist sai como `EVENT` (live) e só
       * declara os trechos já publicados — no começo do Lanterns, 40 s de 3388.
       * Com esta flag em `false`, o `getDurationAndRange()` do hls.js gravava
       * essa duração parcial no `mediaSource.duration`; o buffer atingia o fim
       * declarado, o `bufferEOS` chamava `mediaSource.endOfStream()` e o
       * MediaSource entrava em `ended`. A partir daí todo `appendBuffer`
       * falhava (`bufferAppendingError`, no áudio primeiro), o hls.js reescrevia
       * para `mediaSourceRequiresReset` e a reprodução morria como fatal.
       *
       * Em `true`, o hls.js mantém a duração em `Infinity` para níveis live e
       * não há "fim" para o buffer alcançar. A barra de progresso não perde
       * nada: a duração real exibida continua vindo de `aplicarDuracaoReal()`.
       */
      liveDurationInfinity: true,

      backBufferLength: 90,

      /*
       * Folga de buffer para redes lentas.
       *
       * Os padrões do hls.js (30 s de buffer, 60 MB) pressupõem banda de
       * streaming. Numa conexão de ~1 Mbps o trecho de 10 s leva mais tempo
       * para baixar do que para tocar, o buffer esvazia e o hls.js emite
       * `bufferStalledError` a cada poucos segundos — o vídeo engasga mesmo com
       * a fonte saudável. Guardar mais minutos à frente absorve a variação da
       * rede e transforma a parada em espera.
       *
       * `maxMaxBufferLength` é o teto que o próprio hls.js pode reduzir quando
       * a banda cai; mantê-lo alto evita que ele encolha o buffer justamente
       * quando a rede piora. `maxBufferSize` acompanha, para o teto de bytes
       * não cortar o de tempo.
       */
      maxBufferLength: 120,
      maxMaxBufferLength: 600,
      maxBufferSize: 200 * 1000 * 1000,

      /*
       * Estimativa inicial de banda conservadora. O padrão (500 kbps) faz o
       * hls.js acreditar que há mais banda do que existe e encher o buffer
       * rápido demais; com um valor menor ele carrega em ritmo sustentável
       * desde o primeiro trecho.
       */
      abrEwmaDefaultEstimate: 300 * 1000,

      /*
       * Tolerância a buracos no buffer e insistência do vigia interno. Numa
       * rede instável o trecho pode chegar com uma pequena descontinuidade; sem
       * folga o hls.js trata como stall e para a reprodução.
       */
      maxBufferHole: 0.5,
      highBufferWatchdogPeriod: 1,
      nudgeMaxRetry: 10,
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
      const nivel = instanciaHls.levels?.[instanciaHls.currentLevel ?? 0]
      const detalhes = nivel?.details

      /*
       * Aqui o hls.js ainda não baixou a playlist — o `startLoad` é a linha
       * seguinte, e é ele que dispara a busca. Por isso `details` é nulo neste
       * ponto e a linha anterior saía inteira em `undefined`, sem servir para
       * nada. Registramos o que já se sabe do nível (o `CODECS` declarado, quando
       * o manifesto traz um, e o que o hls.js farejou do TS); o retrato completo
       * da playlist fica no `LEVEL_LOADED`, logo abaixo.
       */
      anotarDiagnostico(
        `manifesto: niveis=${instanciaHls.levels?.length ?? '?'} ` +
          `codecs=${nivel?.attrs?.CODECS ?? nivel?.codecSet ?? 'sem CODECS'} ` +
          `video=${nivel?.videoCodec ?? '?'} audio=${nivel?.audioCodec ?? '?'} ` +
          `trechos=${detalhes?.fragments?.length ?? '?'}`
      )

      instanciaHls.startLoad(0)

      aplicarDuracaoReal()

      /*
       * Sem `play()` aqui. O `MANIFEST_PARSED` só diz que a playlist foi
       * interpretada — ainda não há um byte do filme no MediaSource, e um
       * `play()` neste ponto esbarra num buffer vazio. O arranque fica a cargo
       * de `FRAG_BUFFERED`, que só dispara com o primeiro trecho já anexado.
       *
       * O manifesto é também onde conferimos se a carga realmente andou: uma
       * playlist ao vivo descartada pelo `startLoad` deixaria a tela parada sem
       * nenhum erro visível, e este alarme denuncia o caso.
       */
      const hlsDaCarga = instanciaHls

      setTimeout(() => {
        if (cancelado || minhaGeracao !== geracao || instanciaHls !== hlsDaCarga) return

        const elemento = player?.media

        if (elemento?.buffered && elemento.buffered.length === 0) {
          anotarDiagnostico('nenhum trecho em buffer após 5s; retomando a carga')

          instanciaHls.startLoad(0)
        }
      }, 5000)
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
     * Retrato fiel da playlist, uma única vez por reprodução.
     *
     * É este evento que traz os `details` de verdade: quantos trechos, a faixa de
     * sequência (`startSN`/`endSN`), o `EXT-X-TARGETDURATION` e — o mais
     * importante — os codecs que o hls.js farejou do TS e vai declarar ao
     * `SourceBuffer`. Um `bufferAppendingError` já na primeira anexação é quase
     * sempre desencontro entre esses codecs e o que veio dentro do segmento, e
     * sem estes números não há como provar qual dos dois lados está errado.
     *
     * Só o primeiro disparo é registrado: o evento se repete a cada recarga da
     * playlist e encheria o painel, empurrando para fora justamente a linha do erro.
     */
    let retratoDaPlaylist = false

    instanciaHls.on(Hls.Events.LEVEL_LOADED, (_evento, dados) => {
      if (cancelado || minhaGeracao !== geracao || retratoDaPlaylist) return

      retratoDaPlaylist = true

      const detalhes = dados?.details
      const nivel = instanciaHls.levels?.[dados?.level ?? 0]

      anotarDiagnostico(
        `playlist: live=${detalhes?.live} trechos=${detalhes?.fragments?.length} ` +
          `seq=${detalhes?.startSN}..${detalhes?.endSN} alvo=${detalhes?.targetduration}s ` +
          `duracao=${detalhes?.totalduration ? detalhes.totalduration.toFixed(1) : '?'} ` +
          `codecs=${nivel?.attrs?.CODECS ?? nivel?.codecSet ?? 'sem CODECS'} ` +
          `video=${nivel?.videoCodec ?? '?'} audio=${nivel?.audioCodec ?? '?'}`
      )
    })

    /*
     * Este é o sinal de que já dá para tocar: o trecho está carregado e anexado,
     * e o MediaSource tem dados no buffer. Só a partir daqui o `play()` tem
     * chance de ser aceito pelo elemento — antes disso ele é recusado ou fica
     * pendente sem nunca virar imagem.
     */
    instanciaHls.on(Hls.Events.FRAG_BUFFERED, () => {
      if (cancelado || minhaGeracao !== geracao) return

      const midiaDoBuffer = player?.media

      if (!midiaDoBuffer) return

      anotarDiagnostico(
        `trecho em buffer: tempo=${midiaDoBuffer.currentTime.toFixed(2)} ` +
          `pausado=${midiaDoBuffer.paused} pronto=${midiaDoBuffer.readyState}`
      )

      /*
       * O vigia só entra em cena no arranque. Se o usuário pausou, a pausa é
       * dele e nenhum trecho novo deve desfazê-la; se há uma busca em andamento,
       * o elemento está pausado de propósito enquanto o alvo carrega, e um
       * `play()` aqui tocaria a partir do buffer antigo, desfazendo a busca.
       */
      if (midiaDoBuffer.paused && !usuarioPausou && !buscaEmAndamento()) {
        vigiarReproducao()
      }
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
       *
       * O alvo só é liberado quando o playhead realmente chegou nele. Zerá-lo
       * apenas porque o trecho chegou deixava a posição sem guarda: qualquer
       * reescrita do `currentTime` logo depois (o clamp do Plyr, por exemplo)
       * ficava sem quem a corrigisse, e o vídeo voltava para trás.
       */
      if (alvoDeSeek !== null) {
        const fimDoTrecho = inicioDoTrecho + (dados?.frag?.duration ?? 0)
        const cobreAlvo = alvoDeSeek >= inicioDoTrecho - 0.5 && alvoDeSeek <= fimDoTrecho + 0.5

        if (cobreAlvo) {
          const midiaDaBusca = player?.media

          if (midiaDaBusca) {
            if (Math.abs(midiaDaBusca.currentTime - alvoDeSeek) > 0.5) {
              try {
                midiaDaBusca.currentTime = alvoDeSeek
              } catch {
                // O elemento pode recusar por um instante; o próximo trecho reaplica.
              }
            }

            if (Math.abs(midiaDaBusca.currentTime - alvoDeSeek) <= 0.5) {
              alvoDeSeek = null
            }
          }
        }
      }

      /*
       * Âncora do início. O primeiro trecho desta reprodução deveria começar no
       * começo do filme. Se ele veio de outro ponto — ou se o relógio já está
       * adiantado —, trazemos o playhead para zero. É o ajuste que faz "assistir"
       * abrir no início real, e não num pedaço do meio com o relógio em 00:00.
       *
       * A âncora vale uma única vez por reprodução e NUNCA depois de uma busca
       * do usuário. A checagem de `usuarioBuscou` cobre o caso em que o arrasto
       * acontece antes de o primeiro trecho chegar: sem ela, o `FRAG_LOADED`
       * seguinte encontraria a flag ainda limpa e devolveria o filme ao início,
       * desfazendo a busca. É o mesmo comportamento em qualquer ordem de
       * eventos — a intenção do usuário sempre vence a âncora.
       */
      if (primeiroTrechoAncorado || usuarioBuscou) return

      primeiroTrechoAncorado = true

      const midia = player?.media

      if (!midia) return

      /*
       * Aqui NÃO se chama `startLoad()`. A versão anterior recarregava a partir
       * do zero quando o primeiro trecho começava longe da cabeça — e o efeito
       * era o oposto do pretendido: `startLoad` descarta o buffer já anexado e
       * reinicia o ciclo de carga, de modo que o `FRAG_BUFFERED` seguinte
       * encontrava o buffer vazio de novo e o `play()` nunca chegava a valer.
       * Com trechos de ~10 s, a carga reiniciava a cada trecho e o filme ficava
       * eternamente no primeiro quadro.
       *
       * Como a borda "ao vivo" está presa ao playhead (`fixarBordaAoPlayhead`),
       * basta mover o cursor: a carga seguinte parte de zero sozinha, sem
       * descartar o que já está em buffer.
       */
      if (inicioDoTrecho > 0.5) {
        console.warn(
          `[player] primeiro trecho em ${inicioDoTrecho.toFixed(2)}s; ancorando o playhead em 0`
        )

        midia.currentTime = 0

        return
      }

      if (midia.currentTime > 0.5) {
        console.warn(`[player] playhead em ${midia.currentTime.toFixed(2)}s; ancorando em 0`)

        midia.currentTime = 0
      }
    })

    /*
     * Sonda do ciclo de vida do MediaSource.
     *
     * O `readyState` do MediaSource apareceu como `ended` no primeiro erro, com
     * o buffer ainda vazio — ou seja, o encerramento aconteceu antes de qualquer
     * `appendBuffer`, e nenhuma duração explica isso. O hls.js tem exatamente
     * dois caminhos para chamar `mediaSource.endOfStream()`: o desanexo da mídia
     * (`destroy`/`detachMedia`) e o `bufferEOS`. Os eventos abaixo dizem qual
     * deles rodou, na ordem: `BUFFERED_TO_END` só sai **depois** de um
     * `endOfStream()` do próprio hls.js, e `MEDIA_DETACHED` só sai de um
     * desanexo. Se nenhum dos dois aparecer antes do primeiro `erro hls.js`, o
     * `ended` veio do motor do navegador na largada — o caso que o hls.js cita
     * como "ended readyState on cold start", e que não se conserta configurando
     * o player.
     */
    instanciaHls.on(Hls.Events.MEDIA_ATTACHED, () => anotarDiagnostico('ciclo: mídia anexada'))
    instanciaHls.on(Hls.Events.MEDIA_DETACHED, () =>
      anotarDiagnostico('ciclo: mídia desanexada')
    )
    instanciaHls.on(Hls.Events.BUFFER_CREATED, () =>
      anotarDiagnostico('ciclo: sourcebuffers criadas')
    )
    instanciaHls.on(Hls.Events.BUFFER_EOS, () => anotarDiagnostico('ciclo: hls.js pediu EOS'))
    instanciaHls.on(Hls.Events.BUFFERED_TO_END, () =>
      anotarDiagnostico('ciclo: mediaSource.endOfStream() chamado')
    )

    /*
     * Do lado do elemento, `emptied` é o sinal de que algo recarregou o
     * `<video>`: recarregar desanexa o MediaSource e todo `appendBuffer`
     * seguinte falha. Não custa registrar também o erro do próprio elemento —
     * um `MediaError` ali é o decoder recusando o stream, causa bem diferente.
     */
    const elementoDoPlayer = player?.media ?? midia
    const eventosDoElemento = ['emptied', 'loadstart', 'loadedmetadata', 'ended']

    for (const nome of eventosDoElemento) {
      elementoDoPlayer?.addEventListener(nome, () => anotarDiagnostico(`elemento: ${nome}`))
    }

    elementoDoPlayer?.addEventListener('error', () =>
      anotarDiagnostico(
        `elemento: mediaError codigo=${elementoDoPlayer.error?.code ?? '?'} ` +
          `msg=${elementoDoPlayer.error?.message ?? '-'}`
      )
    )

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
      /*
       * O `details` diz a categoria, mas o motivo real só aparece na mensagem que
       * o navegador deu ao recusar a anexação — `NotSupportedError` para codec
       * fora do `SourceBuffer`, `QuotaExceededError` para buffer cheio,
       * `InvalidStateError` para MediaSource já fechado — e no tamanho do que se
       * tentou anexar. Sem eles, "erro de mídia" não separa um codec incompatível
       * de um segmento truncado, que são problemas opostos.
       */
      const trecho = dados?.parent ?? dados?.frag

      /*
       * A duração do elemento espelha o `mediaSource.duration`, e o fim do buffer
       * é até onde já se anexou. Registrar os dois aqui é o que separa "o hls.js
       * encerrou o programa cedo" — quando a duração é a da playlist parcial e o
       * buffer encostou nela — de "o decoder recusou o trecho", em que a duração
       * é a esperada e o buffer tem folga à frente.
       */
      const midiaDaFalha = player?.media
      const fimDoBuffer = midiaDaFalha?.buffered?.length
        ? midiaDaFalha.buffered.end(midiaDaFalha.buffered.length - 1)
        : null

      anotarDiagnostico(
        `erro hls.js: ${dados.type}/${dados.details} fatal=${dados.fatal} ` +
          `motivo=${dados.reason ?? '-'} ` +
          `msg=${dados.err?.message ?? dados.error?.message ?? '-'} ` +
          `url=${dados.url ?? trecho?.url ?? '-'} ` +
          `bytes=${dados.chunkMeta?.byteLength ?? '-'} http=${dados.response?.code ?? '-'} ` +
          `duracao=${midiaDaFalha?.duration ?? '-'} ` +
          `buffer=${fimDoBuffer !== null ? fimDoBuffer.toFixed(1) : '-'}`
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

      /*
       * Erro de mídia: o segmento chegou, mas o `SourceBuffer` o recusou. Não é
       * rede nem fonte — é o decoder. `recoverMediaError()` descarta o buffer
       * atual e cria outro com o mesmo codec, retomando do ponto onde parou; na
       * prática resolve a maioria dos tropeços de decodificação sem que o
       * usuário veja nada. Só desistimos depois de esgotar as tentativas, quando
       * aí sim o stream é realmente incompatível com o navegador.
       */
      const erroDeMidia = dados.type === Hls.ErrorTypes.MEDIA_ERROR

      if (erroDeMidia && recuperacoesDeMidia < LIMITE_RECUPERACOES_DE_MIDIA) {
        recuperacoesDeMidia += 1

        anotarDiagnostico(
          `recuperando erro de mídia (${recuperacoesDeMidia}/${LIMITE_RECUPERACOES_DE_MIDIA})`
        )

        try {
          instanciaHls.recoverMediaError()
        } catch (falha) {
          anotarDiagnostico(`recoverMediaError falhou: ${falha?.message ?? falha}`)
        }

        return
      }

      pararVigia()

      erro.value = 'Não foi possível carregar o vídeo. Tente novamente.'
      estado.value = 'erro'
    })

    instanciaHls.loadSource(url)
  } else if (midia.canPlayType('application/vnd.apple.mpegurl')) {
    // Safari e iOS tocam HLS nativamente, sem MediaSource.
    anotarDiagnostico('HLS nativo (Safari)')

    midia.src = url
  } else {
    anotarDiagnostico('navegador sem suporte a HLS nem a MSE')

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
   * O `play()` fica a cargo do vigia. No caminho do hls.js ele é disparado pelo
   * `FRAG_BUFFERED` (acima), quando o primeiro trecho já está no buffer. No
   * caminho nativo não existe evento do hls.js para esperar, então o vigia
   * começa aqui e cobre o intervalo até o `<video>` aceitar o `play()`.
   *
   * A decisão é por `instanciaHls`, e não por `canPlayType`: o Chrome também
   * responde `"maybe"` para HLS, e usá-lo como critério fazia o vigia ser
   * iniciado duas vezes no caminho do hls.js — ou nenhuma, quando o ramo nativo
   * era escolhido por engano.
   */
  if (!instanciaHls) {
    vigiarReproducao()
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
 * Registra no console por que a reprodução não andou.
 *
 * Sem isso, "o filme não começou" chega ao desenvolvedor sem pista alguma:
 * `NotAllowedError` (autoplay bloqueado), `NotSupportedError` (faixa que o MSE
 * não decodifica) e um simples buffer vazio produzem a mesma tela parada. O
 * `MediaError` do elemento e o estado do buffer separam os casos.
 */
function registrarErroDeMidia(falha) {
  const midia = player?.media
  const erroDeMidia = midia?.error

  if (!falha && !erroDeMidia) return

  anotarDiagnostico(
    `falha: ${falha?.name ?? '-'} ${falha?.message ?? ''} ` +
      `midia=${erroDeMidia?.code ?? '-'} pronto=${midia?.readyState} ` +
      `pausado=${midia?.paused} tempo=${midia?.currentTime?.toFixed(2)} ` +
      `buffer=${midia?.buffered?.length ?? 0}`
  )
}

/** Cancela o vigia de reprodução. */
function pararVigia() {
  if (timerPlay) {
    clearTimeout(timerPlay)
    timerPlay = null
  }
}

/** Tenta dar play uma vez, tratando a recusa por autoplay. */
function tentarReproduzir() {
  if (!player) return

  Promise.resolve(player.play()).catch((falha) => {
    // Sem gesto do usuário o navegador recusa som; silenciar costuma liberar.
    if (falha?.name === 'NotAllowedError' && !player.muted) {
      player.muted = true

      Promise.resolve(player.play()).catch((outraFalha) => registrarErroDeMidia(outraFalha))

      return
    }

    registrarErroDeMidia(falha)
  })
}

/**
 * Insiste em iniciar a reprodução até o vídeo sair da pausa.
 *
 * Idempotente de propósito: enquanto o vigia está ativo, novas chamadas são
 * ignoradas. O `FRAG_BUFFERED` dispara a cada trecho e reiniciar a contagem em
 * cada um não faria sentido. A verificação passa pelo estado do elemento, então
 * assim que o vídeo toca — por aqui ou pelo clique do usuário — o vigia se
 * encerra sozinho.
 */
function vigiarReproducao() {
  if (timerPlay) return

  tentativasDePlay = 0

  const passo = () => {
    timerPlay = null

    const midia = player?.media

    /*
     * `instanciaHls` é nulo no caminho nativo (Safari), onde não há hls.js
     * nenhum. Exigir a instância aqui fazia o vigia sair na primeira linha e o
     * `play()` nunca ser tentado — a tela ficava preta com o player liberado.
     */
    if (cancelado || !midia) return

    // Já toca: o vigia cumpriu o papel.
    if (!midia.paused) {
      anotarDiagnostico('reprodução iniciada')

      return
    }

    /*
     * Pausa do usuário ou busca em andamento: o vigia não tem o que fazer. Nos
     * dois casos o elemento está pausado de propósito, e insistir no `play()`
     * desfaria a pausa ou tocaria a partir do buffer antigo.
     */
    if (usuarioPausou || buscaEmAndamento()) {
      anotarDiagnostico(
        usuarioPausou ? 'vigia suspenso: pausa do usuário' : 'vigia suspenso: busca em andamento'
      )

      return
    }

    anotarDiagnostico(
      `tentativa de play ${tentativasDePlay + 1}: pronto=${midia.readyState} ` +
        `buffer=${midia.buffered?.length ?? 0}`
    )

    tentarReproduzir()

    tentativasDePlay += 1

    if (tentativasDePlay >= LIMITE_TENTATIVAS_DE_PLAY) {
      registrarErroDeMidia(null)

      return
    }

    timerPlay = setTimeout(passo, INTERVALO_VIGIA_DE_PLAY_MS)
  }

  timerPlay = setTimeout(passo, INTERVALO_VIGIA_DE_PLAY_MS)
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
 * O Plyr tem o próprio handler de `seeking`, que escreve `media.currentTime`
 * com o valor da barra. Como o elemento clampeia esse valor ao fim do
 * `seekable`, o Plyr acaba sendo o autor do "volta para onde estava". Por isso
 * a busca é conduzida por fora: capturamos a intenção no `input`, e no `change`
 * interrompemos a propagação para o handler do Plyr não rodar — quem escreve no
 * elemento é só o `executarSeek()`.
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

    /*
     * `change` marca o fim do arrasto. `stopImmediatePropagation` impede o
     * handler do Plyr de escrever no elemento — sem isso ele clampeia o
     * `currentTime` ao fim do `seekable` e desfaz a busca antes de ela começar.
     * O `preventDefault` evita o comportamento nativo do input range.
     */
    entradaSeek.addEventListener('change', (evento) => {
      evento.preventDefault()
      evento.stopImmediatePropagation()

      executarSeek(Number(entradaSeek.value))
    })
  }

  /*
   * Pausa e retomada do usuário. O `pause` só conta como intenção quando não há
   * busca em andamento — durante uma busca o elemento pausa sozinho enquanto o
   * alvo carrega, e isso não é uma pausa do usuário.
   */
  midia.addEventListener('pause', () => {
    if (!buscaEmAndamento()) {
      usuarioPausou = true
    }
  })

  midia.addEventListener('play', () => {
    usuarioPausou = false
  })

  /*
   * Rede de segurança contra o clamp do browser. Se o `currentTime` foi
   * reescrito para longe do alvo (pelo Plyr ou pelo próprio elemento), o alvo é
   * reaplicado assim que houver buffer para ele. Sem isso, uma busca que o
   * Plyr clampeou ficaria perdida.
   */
  midia.addEventListener('seeked', () => {
    if (alvoDeSeek === null) return

    if (Math.abs(midia.currentTime - alvoDeSeek) <= 0.5) return

    if (!alvoEstaEmBuffer(midia, alvoDeSeek)) return

    try {
      midia.currentTime = alvoDeSeek
    } catch {
      // O elemento pode recusar por um instante; o próximo trecho reaplica.
    }
  })
}

/** Fim do intervalo de tempo que o elemento consegue buscar agora. */
function fimDoSeekable(midia) {
  if (!midia?.seekable?.length) return 0

  return midia.seekable.end(midia.seekable.length - 1)
}

/**
 * Diz se o tempo alvo já está coberto pelo buffer do elemento.
 *
 * É a diferença que separa uma busca instantânea de uma que precisa recarregar:
 * dentro do buffer basta mover o playhead; fora dele o trecho precisa ser
 * buscado de novo.
 */
function alvoEstaEmBuffer(midia, alvo) {
  if (!midia?.buffered?.length) return false

  for (let indice = 0; indice < midia.buffered.length; indice += 1) {
    if (alvo >= midia.buffered.start(indice) && alvo <= midia.buffered.end(indice)) {
      return true
    }
  }

  return false
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

  /*
   * A partir daqui o playhead é do usuário: a âncora do início não age mais.
   * Sem isso, o `FRAG_LOADED` do trecho buscado reancorava o filme em zero.
   */
  usuarioBuscou = true

  /*
   * Alvo já em buffer: a busca é só mover o playhead.
   *
   * Aqui NÃO se chama `startLoad`. A versão anterior chamava em qualquer busca
   * dentro do convertido, e `startLoad` descarta o buffer e reinicia o ciclo de
   * carga — o `FRAG_LOADED` seguinte encontrava o playhead em zero e reancorava
   * o filme no começo. Era a origem do "arrasto a barra e volto ao início".
   * Com o alvo já em buffer, escrever `currentTime` é suficiente e instantâneo.
   *
   * O alvo fica registrado até o playhead chegar nele: é o que permite ao
   * handler de `seeked` corrigir uma reescrita do `currentTime` feita logo
   * depois (o clamp do Plyr, por exemplo).
   */
  if (alvoEstaEmBuffer(midia, alvo)) {
    alvoDeSeek = alvo

    try {
      midia.currentTime = alvo
    } catch {
      // O elemento pode recusar por um instante; o próximo trecho reaplica.
    }

    if (Math.abs(midia.currentTime - alvo) <= 0.5) {
      alvoDeSeek = null
    }

    return
  }

  const fimConvertido = fimDoSeekable(midia)

  /*
   * Fora do buffer mas dentro do que já foi convertido: o trecho existe no
   * servidor, só não está carregado. Aqui o `startLoad` é legítimo — não há
   * buffer daquele ponto para preservar — e a borda presa ao alvo faz a carga
   * seguir para o ponto buscado em vez de escapar para a borda da conversão.
   */
  if (alvo <= fimConvertido + 0.5) {
    alvoDeSeek = alvo

    instanciaHls.startLoad(alvo)

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
  // O mesmo vale para as recuperações de mídia: a playlist nova é outro stream.
  recuperacoesDeMidia = 0

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

        // Sessão morta no servidor: não há reposicionamento a esperar.
        if (status.status === 'inexistente') return resolve(null)

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
async function tentarFontes(fontes, minhaGeracao, minhaAbertura, filme) {
  /*
   * Quantas fontes caíram por não trazerem áudio em português e o placar dos
   * demais motivos. Sem esse placar a mensagem final era um "não conseguiu
   * conectar" genérico, que escondia tanto o problema de idioma quanto a fonte
   * sem peers — justamente as duas informações que dizem ao usuário o que fazer
   * depois.
   */
  let recusadasPorIdioma = 0
  const desistencias = {
    sem_peers: 0,
    sem_dados: 0,
    sem_metadados: 0,
    sem_video: 0,
    lento: 0,
    inexistente: 0,
    // A fonte não pôde ser alcançada (nome que não resolve, conversão que cai na
    // abertura). Não é defeito do release: a resposta certa é tentar de novo.
    rede: 0,
    erro: 0,
  }

  for (let indice = 0; indice < fontes.length; indice += 1) {
    if (cancelado || minhaAbertura !== abertura) return

    const fonte = fontes[indice]

    tentativaAtual.value = indice + 1
    estado.value = 'tentando'
    mensagem.value = `Tentando fonte ${indice + 1} de ${fontes.length}...`

    /*
     * Provedor de origem + idioma detectado (ex.: "Indexador (Torznab) · Dublado").
     * Numa fonte direta acrescentamos o rótulo "Link direto" para o usuário
     * entender que aquele caminho não é um torrent — é o socorro que só entra
     * quando a malha falhou.
     */
    const rotuloOrigem = fonte.tipo === 'direto' ? 'Link direto' : null

    rotuloFonte.value =
      [fonte.provedor_rotulo, rotuloOrigem, fonte.idioma_rotulo].filter(Boolean).join(' · ') || null

    try {
      /*
       * A numeração vem do snapshot `filme`, capturado quando este fluxo
       * começou — nunca de `props.filme`. Ler a prop aqui dentro era o que
       * misturava os episódios: quando a busca do episódio 2 demorava e o
       * usuário fechava e reabria, o loop antigo (ainda vivo) passava a ler a
       * prop já trocada e criava sessões com a numeração do episódio errado.
       *
       * A fonte direta não tem magnet: ela carrega uma URL de vídeo e o
       * media-service a converte para HLS pelo endpoint próprio. O resto do
       * acompanhamento é idêntico — o overlay nem precisa saber a diferença.
       */
      const sessao = fonte.tipo === 'direto'
        ? await streamingService.criarSessaoDireta(
          fonte.stream,
          filme.id,
          filme.temporada,
          filme.episodio
        )
        : await streamingService.criarSessao(
          fonte.magnet,
          filme.id,
          filme.temporada,
          filme.episodio
        )

      if (cancelado || minhaAbertura !== abertura) return

      sessaoId = sessao.sessao_id

      // A sessão foi criada; acompanhamos até ficar pronta ou falhar. Se
      // falhar, o loop segue para a próxima fonte.
      const resultado = await aguardarFonte(minhaGeracao, fonte)

      if (resultado.desfecho === 'pronto') {
        return
      }

      if (resultado.desfecho === 'sem_audio_pt') {
        recusadasPorIdioma += 1
      } else {
        registrarDesistencia(desistencias, resultado.motivo)
      }
    } catch {
      // Fonte indisponível: seguimos para a próxima.
      registrarDesistencia(desistencias, 'erro')
    }
  }

  if (!cancelado && minhaAbertura === abertura) {
    erro.value = mensagemDeFalha(desistencias, fontes.length, recusadasPorIdioma)
    estado.value = 'erro'
  }
}

/** Soma um motivo ao placar, tratando rótulo desconhecido como falha genérica. */
function registrarDesistencia(desistencias, motivo) {
  const chave = motivo in desistencias ? motivo : 'erro'

  desistencias[chave] += 1
}

/**
 * Explica por que nenhuma fonte sobrou.
 *
 * A ordem reflete o que é mais provável e mais acionável: idioma unânime é uma
 * conclusão firme (o dublado não existe naquele lançamento), a fonte sem peers
 * manda procurar outro release e o resto é, em geral, transitório.
 */
function mensagemDeFalha(desistencias, total, recusadasPorIdioma) {
  if (recusadasPorIdioma > 0 && recusadasPorIdioma === total) {
    return 'Nenhuma fonte traz áudio em português.'
  }

  if (desistencias.sem_peers > 0) {
    return 'Nenhuma fonte tem peers disponíveis. Tente outro lançamento.'
  }

  if (desistencias.sem_dados > 0) {
    return 'As fontes conectaram, mas não enviaram dados. Tente novamente mais tarde.'
  }

  if (desistencias.sem_metadados > 0) {
    return 'Não foi possível ler os dados das fontes. Tente novamente mais tarde.'
  }

  if (desistencias.sem_video > 0) {
    return 'As fontes não têm um arquivo de vídeo reconhecido.'
  }

  if (desistencias.inexistente > 0) {
    return 'A sessão de reprodução expirou. Tente novamente.'
  }

  /*
   * Falha de rede fica à frente de "lentas demais" porque a conclusão é outra: a
   * fonte não entregou nada por um motivo do caminho (o nome não resolveu, a
   * conexão caiu na abertura), e um minuto depois ela costuma funcionar. É o
   * único caso em que o botão de tentar novamente quase sempre resolve.
   */
  if (desistencias.rede > 0) {
    return 'A fonte não respondeu por um problema de rede. Tente novamente.'
  }

  if (desistencias.lento > 0) {
    return 'As fontes estão lentas demais. Tente novamente mais tarde.'
  }

  return 'Nenhuma fonte conseguiu conectar. Tente novamente mais tarde.'
}

/**
 * Monta a mensagem do overlay a partir do status da sessão.
 *
 * O backend manda a mensagem base ("Aguardando dados da fonte..."), mas ela
 * sozinha não distingue uma fonte morta de uma lenta. Quando há telemetria de
 * download, acrescentamos peers e velocidade para o usuário saber se vale
 * esperar ou trocar de fonte.
 *
 * A fonte direta não tem malha: não há peers nem velocidade a mostrar. O que
 * ela tem é o percentual da conversão, e é isso que exibimos — dizer "sem
 * peers" num link HTTP que está a ser convertido era o que fazia o usuário
 * achar que a fonte tinha morrido.
 */
function mensagemDeProgresso(status, eDireta = false) {
  const base = status.mensagem || 'Preparando o vídeo...'

  if (eDireta) {
    const percentual = status.progresso?.percentual

    if (typeof percentual === 'number') {
      return `${base} ${Math.round(percentual)}%`
    }

    return base
  }

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
 * Diz se a fonte prometia áudio em português.
 *
 * O contrato do backend só rotula como `pt-BR` (dublado) ou `dual` (dual áudio)
 * as fontes que declaram português. É essa promessa que o porteiro abaixo
 * confere contra o áudio real do arquivo.
 */
function prometePortugues(fonte) {
  return fonte?.idioma === 'pt-BR' || fonte?.idioma === 'dual'
}

/**
 * Aguarda o desfecho de uma única fonte.
 *
 * Devolve `{ desfecho }` com `pronto` quando a playlist ficou disponível no
 * servidor, `falhou` para o chamador seguir para a próxima fonte, `sem_audio_pt`
 * quando a fonte conectou mas o arquivo não tem áudio em português — caso em que
 * ela é descartada em vez de tocar em inglês sob um rótulo de dublagem — ou
 * `cancelado` quando o overlay já pertence a outra geração.
 *
 * Em `falhou` vem também o `motivo` rotulado pelo media-service (`sem_peers`,
 * `sem_metadados`, `sem_dados`, `sem_video`) ou um rótulo local quando a
 * desistência foi do próprio frontend (`lento`, `inexistente`). É esse motivo
 * que permite ao fim da fila dizer por que nada tocou.
 *
 * A montagem do player não entra nessa decisão: uma vez que a playlist existe e
 * o áudio confere, a fonte é válida.
 *
 * A sessão desta fonte é capturada de uma vez, em vez de lida da variável
 * compartilhada a cada consulta: assim uma troca de filme no meio do caminho
 * não faz este laço consultar (nem encerrar) a sessão de outro filme.
 */
function aguardarFonte(minhaGeracao, fonte) {
  const inicio = Date.now()
  const sessaoDaFonte = sessaoId

  /*
   * A fonte direta não é um torrent: não há malha, não há peers e não há
   * download a medir. O media-service baixa a URL e converte para HLS, e o
   * `download` da sessão fica `null` de propósito. Aplicar aqui a lógica de
   * estagnação do torrent (`peers === 0` → `sem_peers`) rotulava um link HTTP
   * saudável como "fonte morta", e o overlay desistia de um vídeo que estava
   * apenas convertendo. Por isso o fluxo direto tem tratamento próprio.
   */
  const eDireta = fonte?.tipo === 'direto'

  /*
   * Instante em que a fonte começou a parecer estagnada, ou `null` enquanto ela
   * dá sinal de vida. Uma fonte com poucos peers costuma conectar e ficar a
   * 0 MB/s sem nunca gerar erro: sem esta marca, ela só seria abandonada no
   * `TIMEOUT_FONTE_MS` (90 s). Guardamos quando a estagnação começou para
   * desistir assim que ela passar de `ESTAGNACAO_FONTE_MS`.
   */
  let estagnadaDesde = null

  /*
   * Prova de vida da fonte. Uma vez que qualquer byte chegou, a fonte não é mais
   * abandonada por estagnação: ela só segue sob o `TIMEOUT_FONTE_MS`. Sem esta
   * trava, uma conexão lenta (1 Mbps) derrubava fontes boas — a velocidade
   * oscilava até zero por alguns ciclos e o frontend trocava de fonte no meio de
   * um download que estava andando.
   */
  let jaEntregouBytes = false

  /*
   * Último percentual de conversão visto numa fonte direta. É a prova de vida
   * dela: enquanto o número muda, o FFmpeg está avançando e não há motivo para
   * abandonar. Só quando ele para de mudar por `ESTAGNACAO_DIRETA_MS` é que a
   * conversão travou de verdade.
   */
  let progressoAnterior = null

  return new Promise((resolve) => {
    const consultar = async () => {
      if (cancelado || minhaGeracao !== geracao) return resolve({ desfecho: 'cancelado' })

      /*
       * O prazo do frontend é o último recurso: a fonte não chegou a `pronto`
       * nem a erro dentro da janela. Rotulamos como lentidão porque é o que
       * sobra — as desistências rápidas (magnet sem metadados, estagnação) já
       * teriam acontecido antes deste ponto.
       */
      /*
       * O prazo da fonte direta é maior: ela não baixa de uma malha P2P, e sim
       * de um servidor HTTP que pode ser lento. Enquanto a conversão avança
       * (o progresso muda), não faz sentido abandonar — o `TIMEOUT_FONTE_MS`
       * padrão, pensado para torrent, cortava a conversão no meio. Só o
       * `TIMEOUT_DIRETO_MS` encerra, e mesmo assim só quando o progresso
       * estagnou de verdade (ver o bloco de estagnação abaixo).
       */
      const teto = eDireta ? TIMEOUT_DIRETO_MS : TIMEOUT_FONTE_MS

      if (Date.now() - inicio > teto) {
        await limparSessaoAtual()
        return resolve({ desfecho: 'falhou', motivo: 'lento' })
      }

      try {
        const status = await streamingService.statusSessao(sessaoDaFonte)

        if (cancelado || minhaGeracao !== geracao) return resolve({ desfecho: 'cancelado' })

        /*
         * Sessão inexistente é terminal, não transitória. O media-service
         * reiniciou (o mapa de sessões é em memória) ou a sessão foi encerrada
         * por outro caminho; insistir manteria o overlay girando para sempre
         * contra um id morto. Abandonamos a fonte na hora.
         */
        if (status.status === 'inexistente') {
          await limparSessaoAtual()
          return resolve({ desfecho: 'falhou', motivo: 'inexistente' })
        }

        if (status.status === 'erro') {
          /*
           * O motivo vem do media-service: ele sabe se faltaram metadados, se a
           * fonte não tem peer ou se nenhum dado chegou. Sem esse rótulo a única
           * saída era tentar a próxima fonte em silêncio.
           */
          await limparSessaoAtual()
          return resolve({ desfecho: 'falhou', motivo: status.motivo ?? 'erro' })
        }

        if (status.status === 'pronto' && status.playlist) {
          /*
           * Porteiro de idioma. A fonte prometeu dublagem, mas quem diz o que há
           * no arquivo é o ffprobe: `tem_audio_pt === false` prova que só existe
           * áudio original. A fonte é descartada em vez de tocar em inglês sob o
           * rótulo "Dublado", e o loop segue para a próxima.
           *
           * O `null` (sem faixas para julgar) não reprova: só o `false` é prova.
           */
          if (prometePortugues(fonte) && status.tem_audio_pt === false) {
            await limparSessaoAtual()
            return resolve({ desfecho: 'sem_audio_pt' })
          }

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

          return resolve({ desfecho: 'pronto' })
        }

        estado.value = 'preparando'
        download.value = status.download ?? null
        duracaoTotal.value = status.duracao ?? duracaoTotal.value
        mensagem.value = mensagemDeProgresso(status, eDireta)

        /*
         * A fonte direta não tem telemetria de download: o que prova que ela
         * está viva é o **progresso da conversão**. O media-service publica
         * `progresso` conforme o FFmpeg avança, e enquanto esse número muda a
         * fonte está trabalhando — mesmo que ainda não tenha produzido os
         * segmentos suficientes para o buffer inicial. Só abandonamos quando o
         * progresso para de mudar por `ESTAGNACAO_DIRETA_MS`, o que separa
         * "convertendo devagar" de "travado de verdade".
         */
        if (eDireta) {
          const progresso = status.progresso?.percentual ?? null

          if (progresso !== null && progresso !== progressoAnterior) {
            progressoAnterior = progresso
            estagnadaDesde = null
          } else if (progresso !== null) {
            estagnadaDesde ??= Date.now()

            if (Date.now() - estagnadaDesde > ESTAGNACAO_DIRETA_MS) {
              await limparSessaoAtual()
              return resolve({ desfecho: 'falhou', motivo: 'sem_dados' })
            }
          }

          timerStatus = setTimeout(consultar, INTERVALO_STATUS_SESSAO_MS)
          return
        }

        /*
         * Desistência por estagnação: a fonte conectou (a sessão existe e está
         * em `preparando`), mas nunca entregou um byte. É o caso da fonte com
         * poucos peers — ela não gera erro, só fica parada a 0 MB/s, e prendia
         * o usuário até o `TIMEOUT_FONTE_MS` (90 s).
         *
         * Exigimos as duas condições juntas: nada baixado **e** velocidade
         * zerada. Só a velocidade não serve — durante a análise do cabeçalho o
         * WebTorrent pode passar alguns segundos sem tráfego enquanto negocia
         * com o peer, e uma fonte saudável seria descartada no meio da leitura.
         * Já ter baixado algo prova que a fonte está viva; a partir daí ela
         * segue sob o `TIMEOUT_FONTE_MS`, que é o limite para a lentidão.
         */
        const baixado = status.download?.baixado ?? 0
        const velocidade = status.download?.velocidade ?? 0

        /*
         * Qualquer byte já baixado é prova de vida permanente. A partir daqui a
         * fonte não é mais abandonada por estagnação — só pelo `TIMEOUT_FONTE_MS`.
         * É o que protege a conexão lenta: a velocidade cai a zero entre ciclos
         * enquanto o WebTorrent negocia, e sem esta trava a fonte era trocada no
         * meio de um download que estava andando.
         */
        if (baixado > 0) {
          jaEntregouBytes = true
        }

        if (!jaEntregouBytes && baixado === 0 && velocidade === 0) {
          estagnadaDesde ??= Date.now()

          if (Date.now() - estagnadaDesde > ESTAGNACAO_FONTE_MS) {
            /*
             * Parada sem um byte: o motivo depende de haver com quem falar. Sem
             * peers é fonte morta; com peers é uma fonte que não entrega, e o
             * overlay diferencia os dois conselhos.
             */
            const motivo = (status.download?.peers ?? 0) === 0 ? 'sem_peers' : 'sem_dados'

            await limparSessaoAtual()
            return resolve({ desfecho: 'falhou', motivo })
          }
        } else {
          estagnadaDesde = null
        }
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
  /*
   * Cada abertura recebe um número próprio, que só avança aqui. É esta marca —
   * e não a geração — que identifica "a abertura mais nova": a geração também
   * muda em outros pontos do fluxo, então usá-la como identidade confundiria
   * "sou o fluxo corrente" com "algo mexeu no contador".
   */
  abertura += 1
  const minhaAbertura = abertura

  /*
   * O player e a sessão do filme anterior são derrubados AQUI, no começo do
   * fluxo, e não no watcher. O watcher só enxerga a troca quando o valor
   * anterior não é `null`; mas o caminho mais comum — fechar o player (a prop
   * vira `null`) e reabrir outro episódio — chega aqui com o valor anterior
   * `null`, e nesse caso a limpeza feita no watcher era pulada. O `<video>` do
   * episódio anterior continuava montado, com o estado ainda em `reproduzindo`,
   * e o player novo reaproveitava o mesmo nó: era isso que fazia o episódio 2
   * tocar o episódio 1. Limpar incondicionalmente fecha essa brecha.
   */
  destruirPlayer()
  await limparSessao()

  // Se outra abertura começou enquanto a sessão antiga era encerrada, esta aqui
  // já não é mais a corrente e não deve mexer no estado.
  if (minhaAbertura !== abertura) return

  /*
   * A geração é reservada agora, depois da limpeza, e é o número que os passos
   * assíncronos deste fluxo vão comparar. Como `limparSessao()` não mexe mais no
   * contador, este valor permanece estável até a próxima abertura.
   */
  geracao += 1
  const minhaGeracao = geracao

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
  recuperacoesDeMidia = 0
  // Uma busca pendente pertence ao filme anterior.
  alvoDeSeek = null
  // A timeline do filme novo começa do zero.
  tempoBase = 0
  buscandoTrecho.value = false

  /*
   * O filme é congelado aqui, no começo do fluxo. Todo o resto — a busca e a
   * criação de sessão — usa este snapshot, nunca `props.filme`. A prop muda
   * quando o usuário troca de episódio, e um fluxo antigo ainda em andamento
   * (a busca do episódio anterior que demorou) passaria a ler a numeração nova,
   * criando sessões com o episódio errado. Era esse resíduo que fazia o episódio
   * 2 tocar o episódio 1.
   */
  const filme = props.filme

  try {
    const { fontes, mensagem: aviso } = await streamingService.buscarFontes(filme)

    if (cancelado || minhaAbertura !== abertura) return

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

    await tentarFontes(fontes, minhaGeracao, minhaAbertura, filme)
  } catch (falha) {
    /*
     * O `catch` antigo era mudo: qualquer exceção — timeout do axios, falha de
     * rede, um `TypeError` inesperado — virava a mesma frase genérica, e a causa
     * real morria aqui. O sintoma que isso escondia: a busca de fontes estoura o
     * `TIMEOUT_REQUISICAO_MS` (60 s) numa execução a frio, o axios aborta com
     * `ECONNABORTED` e descarta a resposta que ainda estava a caminho. O DevTools
     * mostra a resposta chegando (com as fontes), mas o axios já desistiu — e o
     * usuário lê "não foi possível buscar as fontes" mesmo com o backend tendo
     * respondido. Registrar a falha no diagnóstico é o que permite distinguir
     * esse caso de um erro real de rede.
     */
    anotarDiagnostico(`falha ao buscar fontes: ${falha?.code || falha?.message || falha}`)

    if (!cancelado && minhaAbertura === abertura) {
      /*
       * Timeout merece uma mensagem própria: não é "não consegui buscar", é
       * "busquei, mas demorou mais do que o frontend espera". A diferença muda o
       * que o usuário faz — tentar de novo (o cache já estará quente) em vez de
       * concluir que o sistema está quebrado.
       */
      erro.value =
        falha?.code === 'ECONNABORTED'
          ? 'A busca de fontes demorou demais. Tente novamente — da segunda vez o cache responde na hora.'
          : 'Não foi possível buscar as fontes agora. Tente novamente.'
      estado.value = 'erro'
    }
  }
}

function fechar() {
  emit('fechar')
}

/**
 * Refaz a busca a partir do estado de erro.
 *
 * O erro não tinha saída: a única forma de tentar de novo era fechar e reabrir o
 * episódio, repetindo a busca inteira sem nenhuma vantagem para quem só perdeu a
 * vez por um soluço de rede. E esse é justamente o caso comum da fonte direta,
 * que muitas vezes é a única que existe para o episódio — ali a falha é do
 * caminho até o CDN, não do lançamento.
 */
async function tentarDeNovo() {
  if (estado.value !== 'erro') return

  await iniciar()
}

function aoTeclar(evento) {
  if (evento.key === 'Escape') fechar()
}

/*
 * O fluxo começa quando o overlay abre e é desmontado ao fechar.
 *
 * O gatilho é o CONTEÚDO do filme, não o booleano `aberto`. Observar o booleano
 * parecia bastar, mas ele só distingue "tem filme" de "não tem": quando o pai
 * troca o episódio de uma vez — sem passar por `null` entre um e outro — o valor
 * continua `true` e o watcher nunca dispara. Era assim que abrir o episódio 2
 * tocava o episódio 1: a sessão antiga seguia viva e o player nunca era
 * reconstruído. Comparar o objeto inteiro faz cada episódio ser um caso novo.
 */
watch(
  () => props.filme,
  async (filme) => {
    const estaAberto = filme !== null

    document.body.style.overflow = estaAberto ? 'hidden' : ''

    if (!estaAberto) {
      window.removeEventListener('keydown', aoTeclar)
      destruirPlayer()
      await limparSessao()

      return
    }

    /*
     * A limpeza do título anterior não é feita aqui: `iniciar()` já derruba o
     * player e a sessão incondicionalmente, logo no primeiro passo. Concentrar
     * isso num único lugar evita que a troca direta (episódio 1 → episódio 2) e
     * a reabertura após fechar (que chega com o valor anterior `null`) sigam
     * caminhos diferentes — era justamente essa assimetria que deixava o vídeo
     * antigo vivo.
     */
    window.addEventListener('keydown', aoTeclar)
    await iniciar()
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
              {{ tituloExibicao }}
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

          <!--
            Saída para o estado de erro. Sem ele, tentar outra vez exigia fechar e
            reabrir o episódio — caro demais quando a fonte única do episódio caiu
            por um problema de rede que já passou.
          -->
          <button
            v-if="estado === 'erro'"
            type="button"
            class="rounded-full bg-brand-600 px-6 py-2 text-sm font-semibold text-white transition hover:bg-brand-700"
            @click="tentarDeNovo"
          >
            Tentar novamente
          </button>
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

          <!--
            Painel de diagnóstico temporário. Fica sobre o vídeo para que o
            usuário consiga relatar o que o player está fazendo sem abrir o
            console do navegador. Remover quando o fluxo estiver estável.
          -->
          <div
            v-if="diagnostico.length"
            class="absolute bottom-3 left-3 z-20 max-w-[80%] rounded bg-black/75 px-3 py-2 font-mono text-[10px] leading-tight text-lime-300"
          >
            <p v-for="(linha, indice) in diagnostico" :key="indice">{{ linha }}</p>
          </div>

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
