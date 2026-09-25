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

const elementoVideo = ref(null)
const urlPlaylist = ref(null)

/*
 * Duração total do filme, lida pelo ffprobe no media-service.
 *
 * Enquanto a conversão corre a playlist é `EVENT`, e o hls.js a trata como
 * transmissão ao vivo: reporta `Infinity` e o Plyr não monta a barra de
 * progresso. Guardamos o valor real para informar ao player qual é o fim da
 * timeline, permitindo seek e exibição do tempo total antes do fim da conversão.
 */
const duracaoTotal = ref(null)

let player = null
let instanciaHls = null
let timerStatus = null
let sessaoId = null
let cancelado = false

/** Encerra o polling e libera a sessão no servidor. */
async function limparSessao() {
  cancelado = true

  if (timerStatus) {
    clearTimeout(timerStatus)
    timerStatus = null
  }

  if (sessaoId) {
    try {
      await streamingService.encerrarSessao(sessaoId)
    } catch {
      // Se o servidor já tiver descartado a sessão, não há o que fazer.
    }

    sessaoId = null
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
async function iniciarPlayer(url) {
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
    duration: duracaoTotal.value ?? undefined,
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

  if (cancelado) return

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
     * Enquanto a conversão corre, a playlist é `EVENT` e ainda não tem
     * `#EXT-X-ENDLIST`. Por isso o hls.js marca `details.live = true` — o
     * `live` só vira `false` quando o `#EXT-X-ENDLIST` aparece. Nesse caminho
     * "ao vivo" o `startPosition` da configuração é descartado: ele é forçado a
     * `-1` e o playhead vai para a borda ao vivo (`getInitialLiveFragment()`),
     * que muda de lugar a cada carregamento conforme a quantidade de segmentos
     * já convertidos. Era isso que fazia o filme começar de um ponto diferente
     * toda vez.
     *
     * A saída não é configurar `startPosition`, e sim desligar o carregamento
     * automático (`autoStartLoad: false`) e mandar o hls.js começar do zero no
     * evento `MANIFEST_PARSED`, com `startLoad(0)`. A partir daí o
     * `setStartPosition()` mantém o início em zero, porque o ramo "ao vivo" só é
     * escolhido quando a posição vale `-1`. O `liveDurationInfinity: false`
     * (padrão) evita que a duração vire `Infinity`, e o `backBufferLength` alto
     * preserva o que já foi assistido, permitindo voltar na barra sem rebuscar
     * tudo.
     */
    instanciaHls = new Hls({
      enableWorker: true,
      autoStartLoad: false,
      liveDurationInfinity: false,
      backBufferLength: 90,
    })

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
      if (cancelado) return

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
      if (cancelado) return

      aplicarDuracaoReal()
    })

    // Uma falha fatal aqui é de reprodução, não de fonte: a playlist existe e
    // foi servida. Avisamos o usuário sem mexer no fluxo de fontes, que já
    // terminou quando a playlist ficou pronta.
    instanciaHls.on(Hls.Events.ERROR, (_evento, dados) => {
      // O detalhe é o que distingue um stall de buffer de um erro de rede; sem
      // ele o console não ajuda a diagnosticar a tela preta.
      console.error('[player] erro do hls.js:', dados.type, dados.details, dados.reason ?? '')

      if (!dados.fatal) return

      erro.value = 'Não foi possível carregar o vídeo. Tente novamente.'
      estado.value = 'erro'
    })

    instanciaHls.loadSource(url)
  } else {
    erro.value = 'Seu navegador não suporta a reprodução deste vídeo.'
    estado.value = 'erro'
    return
  }

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
function aplicarDuracaoReal() {
  const midia = player?.media

  if (!player || !midia || !duracaoTotal.value) return

  if (player.config.duration === duracaoTotal.value) return

  player.config.duration = duracaoTotal.value

  midia.dispatchEvent(new Event('durationchange'))
}

/**
 * Percorre as fontes em ordem até uma conectar.
 *
 * Dificilmente o filme terá fonte dublada logo na primeira tentativa, então
 * tentamos uma a uma e só desistimos quando a lista inteira falhar.
 */
async function tentarFontes(fontes) {
  for (let indice = 0; indice < fontes.length; indice += 1) {
    if (cancelado) return

    const fonte = fontes[indice]

    tentativaAtual.value = indice + 1
    estado.value = 'tentando'
    mensagem.value = `Tentando fonte ${indice + 1} de ${fontes.length}...`

    try {
      const sessao = await streamingService.criarSessao(fonte.magnet, props.filme.id)

      if (cancelado) return

      sessaoId = sessao.sessao_id

      // A sessão foi criada; acompanhamos até ficar pronta ou falhar. Se
      // falhar, o loop segue para a próxima fonte.
      const resultado = await aguardarFonte()

      if (resultado === 'pronto') {
        return
      }
    } catch {
      // Fonte indisponível: seguimos para a próxima.
    }
  }

  if (!cancelado) {
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
 */
function aguardarFonte() {
  const inicio = Date.now()

  return new Promise((resolve) => {
    const consultar = async () => {
      if (cancelado) return resolve('cancelado')

      if (Date.now() - inicio > TIMEOUT_FONTE_MS) {
        await limparSessaoAtual()
        return resolve('falhou')
      }

      try {
        const status = await streamingService.statusSessao(sessaoId)

        if (cancelado) return resolve('cancelado')

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

          /*
           * A fonte está boa: a playlist existe e foi servida pelo servidor.
           * Montar o player é um passo separado — se falhar, o erro é de UI e
           * não pode descartar uma fonte válida. Antes, condicionar o sucesso
           * ao retorno de `iniciarPlayer` fazia o fluxo queimar a lista inteira
           * de fontes por uma falha de montagem do Plyr.
           */
          iniciarPlayer(url).catch(() => {})

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

  if (sessaoId) {
    try {
      await streamingService.encerrarSessao(sessaoId)
    } catch {
      // Sessão já descartada pelo servidor.
    }

    sessaoId = null
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
  // A duração pertence ao filme anterior; sem zerar, a barra do novo filme
  // herdaria o tamanho do antigo até o status trazer o valor correto.
  duracaoTotal.value = null

  try {
    const fontes = await streamingService.buscarFontes(props.filme.id)

    if (cancelado) return

    if (!fontes.length) {
      erro.value = 'Nenhuma fonte encontrada para este título no momento.'
      estado.value = 'erro'
      return
    }

    totalFontes.value = fontes.length

    await tentarFontes(fontes)
  } catch {
    if (!cancelado) {
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
          <div v-if="totalFontes > 0 && estado !== 'erro'" class="w-64 space-y-2">
            <div class="h-1 w-full overflow-hidden rounded-full bg-white/10">
              <span
                class="block h-full rounded-full bg-brand-500 transition-all duration-300"
                :style="{ width: `${(tentativaAtual / totalFontes) * 100}%` }"
              />
            </div>
            <p class="text-xs uppercase tracking-[0.2em] text-slate-500">
              Fonte {{ tentativaAtual }} de {{ totalFontes }}
            </p>
          </div>
        </div>

        <!--
          O Plyr recebe apenas uma fonte HLS válida. Nada de manipular o player:
          ele já sabe consumir `application/x-mpegURL`.

          Usamos `v-if` (e não `v-show`) para que o elemento só exista no DOM
          quando o estado permitir. Com `v-show`, o Plyr media um container com
          `display: none` e montava os controles sem altura.
        -->
        <div v-if="estado === 'reproduzindo'" class="player-wrapper aspect-video w-full max-w-6xl">
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
