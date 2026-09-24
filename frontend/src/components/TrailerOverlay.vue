<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import Plyr from 'plyr'
import 'plyr/dist/plyr.css'
import { IDIOMA_LEGENDA, IDIOMA_PADRAO } from '@/constants/filmes'

const props = defineProps({
  // Chave do vídeo no YouTube, como devolvida pelo backend.
  trailer: {
    type: String,
    default: null,
  },
  titulo: {
    type: String,
    default: '',
  },
  // Idioma do áudio do trailer escolhido pelo backend (ex.: 'pt', 'en').
  idioma: {
    type: String,
    default: null,
  },
  // Idioma da legenda a pedir ao YouTube, ou null para abrir sem legenda.
  legenda: {
    type: String,
    default: null,
  },
})

const emit = defineEmits(['fechar'])

const aberto = computed(() => Boolean(props.trailer))

const elementoVideo = ref(null)
let player = null

/*
 * O Plyr usa a IFrame Player API do YouTube — o mesmo embed gratuito, sem chave
 * nem cota. Não consultamos a Data API, então não há como listar as faixas de
 * legenda: pedimos a preferência via `cc_lang_pref` e o próprio YouTube liga a
 * legenda se o vídeo tiver aquela faixa, ignorando se não tiver.
 */
const opcoes = computed(() => ({
  provider: 'youtube',
  youtube: {
    noCookie: true,
    rel: 0,
    showinfo: 0,
    // A interface do player acompanha o idioma padrão do projeto.
    hl: IDIOMA_PADRAO,
    ...(props.legenda ? { cc_lang_pref: props.legenda } : {}),
  },
  controls: [
    'play-large',
    'play',
    'progress',
    'current-time',
    'mute',
    'volume',
    'captions',
    'settings',
    'fullscreen',
  ],
  settings: ['captions', 'quality', 'speed'],
  captions: { active: Boolean(props.legenda), language: props.legenda ?? IDIOMA_LEGENDA },
  tooltips: { controls: true, seek: true },
  i18n: {
    restart: 'Reiniciar',
    play: 'Reproduzir',
    pause: 'Pausar',
    seek: 'Buscar',
    volume: 'Volume',
    mute: 'Silenciar',
    unmute: 'Ativar som',
    enterFullscreen: 'Tela cheia',
    exitFullscreen: 'Sair da tela cheia',
    captions: 'Legendas',
    settings: 'Configurações',
    speed: 'Velocidade',
    quality: 'Qualidade',
    normal: 'Normal',
  },
}))

function destruirPlayer() {
  if (player) {
    player.destroy()
    player = null
  }
}

/*
 * O Plyr precisa do elemento já no DOM para se acoplar. Como o overlay só é
 * montado quando há trailer, esperamos o próximo tick após a abertura e então
 * inicializamos; ao fechar, destruímos para não deixar instância viva entre
 * aberturas.
 */
watch(
  aberto,
  async (estaAberto) => {
    if (estaAberto) {
      document.body.style.overflow = 'hidden'
      window.addEventListener('keydown', aoTeclar)

      await nextTick()

      if (elementoVideo.value) {
        player = new Plyr(elementoVideo.value, opcoes.value)
      }
    } else {
      window.removeEventListener('keydown', aoTeclar)
      destruirPlayer()
    }
  },
  { immediate: true }
)

function fechar() {
  emit('fechar')
}

function aoTeclar(evento) {
  if (evento.key === 'Escape') fechar()
}

onUnmounted(() => {
  window.removeEventListener('keydown', aoTeclar)
  destruirPlayer()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="aberto"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/95 p-4"
        @click.self="fechar"
      >
        <button
          type="button"
          class="absolute right-4 top-4 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
          aria-label="Fechar trailer"
          @click="fechar"
        >
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
          </svg>
        </button>

        <!--
          O Plyr assume este contêiner e monta os controles por cima do embed do
          YouTube. O iframe interno é o que o Plyr controla via IFrame Player
          API; a proporção 16:9 é mantida pelo próprio player.
        -->
        <div class="w-full max-w-6xl overflow-hidden rounded-lg bg-black shadow-2xl shadow-black">
          <div
            ref="elementoVideo"
            class="plyr__video-embed"
            data-plyr-provider="youtube"
            :data-plyr-embed-id="trailer"
          >
            <iframe
              :src="`https://www.youtube-nocookie.com/embed/${trailer}?origin=${encodeURIComponent(
                typeof window !== 'undefined' ? window.location.origin : ''
              )}`"
              :title="titulo ? `Trailer de ${titulo}` : 'Trailer'"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              allowfullscreen
            />
          </div>
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
 * O Plyr traz o próprio tema, mas ele nasce com a cor de destaque padrão. Aqui
 * alinhamos ao azul da marca para o player não destoar do restante da interface.
 */
:deep(.plyr) {
  --plyr-color-main: #112352;
  --plyr-video-background: #000;
  --plyr-font-family: inherit;
}
</style>
