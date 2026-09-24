<script setup>
import { computed, onUnmounted, watch } from 'vue'

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
})

const emit = defineEmits(['fechar'])

const aberto = computed(() => Boolean(props.trailer))

/*
 * O player ocupa a tela inteira, então pedimos ao YouTube que esconda os
 * vídeos relacionados (rel=0) e reduza a própria marca (modestbranding=1).
 * Sem isso o overlay parece um embed qualquer em vez de um player de streaming.
 */
const trailerUrl = computed(() =>
  props.trailer
    ? `https://www.youtube.com/embed/${props.trailer}?autoplay=1&rel=0&modestbranding=1`
    : null
)

function fechar() {
  emit('fechar')
}

function aoTeclar(evento) {
  if (evento.key === 'Escape') fechar()
}

/*
 * O modal de filme já trava a rolagem enquanto está aberto. Aqui apenas
 * reforçamos o bloqueio e o removemos ao desmontar, para que fechar o trailer
 * não libere a rolagem por baixo do modal que continua aberto.
 */
watch(
  aberto,
  (estaAberto) => {
    if (estaAberto) {
      document.body.style.overflow = 'hidden'
      window.addEventListener('keydown', aoTeclar)
    } else {
      window.removeEventListener('keydown', aoTeclar)
    }
  },
  { immediate: true }
)

onUnmounted(() => {
  window.removeEventListener('keydown', aoTeclar)
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
          O player mantém a proporção 16:9 e cresce até o limite da tela, sem
          nunca estourar a altura disponível — é o mesmo comportamento dos
          players de streaming em tela cheia.
        -->
        <div class="aspect-video w-full max-w-6xl overflow-hidden rounded-lg bg-black shadow-2xl shadow-black">
          <iframe
            v-if="trailerUrl"
            :src="trailerUrl"
            :title="titulo ? `Trailer de ${titulo}` : 'Trailer'"
            class="h-full w-full"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
          />
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
