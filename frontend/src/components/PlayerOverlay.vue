<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import Plyr from 'plyr'
import Hls from 'hls.js'
import 'plyr/dist/plyr.css'

import { streamingService } from '@/services/streaming'
import { INTERVALO_STATUS_SESSAO_MS, TIMEOUT_SESSAO_MS } from '@/constants/ui'

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

const elementoVideo = ref(null)
const urlPlaylist = ref(null)

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
 * Inicia a reprodução do HLS.
 *
 * Entregamos ao Plyr apenas uma fonte que ele já sabe consumir. Onde o
 * navegador não toca HLS nativamente (Chrome/Firefox), o hls.js faz a ponte;
 * no Safari o próprio Plyr usa o suporte nativo.
 */
async function iniciarPlayer(url) {
  urlPlaylist.value = url
  estado.value = 'reproduzindo'
  mensagem.value = ''

  await nextTick()

  const video = elementoVideo.value

  if (!video) return

  if (video.canPlayType('application/vnd.apple.mpegurl')) {
    // Safari e iOS tocam HLS nativamente.
    video.src = url
  } else if (Hls.isSupported()) {
    instanciaHls = new Hls({ enableWorker: true })
    instanciaHls.loadSource(url)
    instanciaHls.attachMedia(video)
  } else {
    erro.value = 'Seu navegador não suporta a reprodução deste vídeo.'
    estado.value = 'erro'
    return
  }

  player = new Plyr(video, {
    controls: ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen'],
    settings: ['quality', 'speed'],
    autoplay: true,
  })
}

/**
 * Consulta o status da sessão até ela ficar pronta ou falhar.
 *
 * O media-service devolve a mensagem do momento (conectando, convertendo), que
 * é repassada ao overlay para o usuário acompanhar o andamento.
 */
function acompanharSessao(inicio) {
  const consultar = async () => {
    if (cancelado || !sessaoId) return

    if (Date.now() - inicio > TIMEOUT_SESSAO_MS) {
      erro.value = 'A fonte demorou demais para responder. Tente novamente.'
      estado.value = 'erro'
      return
    }

    try {
      const status = await streamingService.statusSessao(sessaoId)

      if (cancelado) return

      if (status.status === 'erro') {
        erro.value = status.erro || 'Não foi possível preparar esta fonte.'
        estado.value = 'erro'
        return
      }

      if (status.status === 'pronto' && status.playlist) {
        await iniciarPlayer(streamingService.urlPlaylist(status.playlist))
        return
      }

      estado.value = 'preparando'
      mensagem.value = status.mensagem || 'Preparando o vídeo...'
    } catch {
      // Uma falha pontual de rede não encerra a sessão: tentamos de novo.
    }

    timerStatus = setTimeout(consultar, INTERVALO_STATUS_SESSAO_MS)
  }

  consultar()
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
 * Aguarda o desfecho de uma única fonte.
 *
 * Devolve `pronto` quando a reprodução começou, ou `falhou` para o chamador
 * seguir para a próxima fonte.
 */
function aguardarFonte() {
  const inicio = Date.now()

  return new Promise((resolve) => {
    const consultar = async () => {
      if (cancelado) return resolve('cancelado')

      if (Date.now() - inicio > TIMEOUT_SESSAO_MS) {
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
          await iniciarPlayer(streamingService.urlPlaylist(status.playlist))
          return resolve('pronto')
        }

        estado.value = 'preparando'
        mensagem.value = status.mensagem || 'Preparando o vídeo...'
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
        class="fixed inset-0 z-[70] flex flex-col items-center justify-center bg-black/95 p-4"
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
        -->
        <div v-show="estado === 'reproduzindo'" class="aspect-video w-full max-w-6xl">
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
</style>
