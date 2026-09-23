<script setup>
import { computed, onUnmounted, watch } from 'vue'

const props = defineProps({
  filme: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['fechar'])

const aberto = computed(() => props.filme !== null)

function fechar() {
  emit('fechar')
}

function aoTeclar(evento) {
  if (evento.key === 'Escape') fechar()
}

// Enquanto o modal está aberto, o body não rola e o Esc fecha.
watch(
  aberto,
  (estaAberto) => {
    document.body.style.overflow = estaAberto ? 'hidden' : ''

    if (estaAberto) {
      window.addEventListener('keydown', aoTeclar)
    } else {
      window.removeEventListener('keydown', aoTeclar)
    }
  },
  { immediate: true }
)

onUnmounted(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', aoTeclar)
})
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="aberto"
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/80 p-4 py-10 backdrop-blur-sm"
        @click.self="fechar"
      >
        <div
          class="relative w-full max-w-3xl overflow-hidden rounded-xl border border-white/10 bg-navy-900 shadow-2xl shadow-black/70"
        >
          <button
            type="button"
            class="absolute right-4 top-4 z-10 flex h-9 w-9 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/90"
            aria-label="Fechar"
            @click="fechar"
          >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
            </svg>
          </button>

          <div class="relative aspect-video w-full bg-slate-700/40">
            <img
              v-if="filme.backdrop_alta || filme.backdrop"
              :src="filme.backdrop_alta || filme.backdrop"
              :alt="filme.titulo"
              class="h-full w-full object-cover"
            />
            <div class="absolute inset-0 bg-gradient-to-t from-navy-900 via-navy-900/40 to-transparent" />

            <div class="absolute inset-x-0 bottom-0 p-6">
              <h2 class="text-2xl font-bold text-white drop-shadow sm:text-3xl">
                {{ filme.titulo }}
              </h2>
            </div>
          </div>

          <div class="space-y-4 p-6">
            <div class="flex flex-wrap items-center gap-3 text-sm">
              <span v-if="filme.nota" class="font-semibold text-amber-400">★ {{ filme.nota }}</span>
              <span v-if="filme.ano" class="text-slate-400">{{ filme.ano }}</span>
              <span class="rounded border border-white/20 px-2 py-0.5 text-slate-200">
                {{ filme.classificacao }}
              </span>
              <span
                v-for="genero in filme.generos"
                :key="genero"
                class="rounded-full bg-white/10 px-3 py-0.5 text-slate-200"
              >
                {{ genero }}
              </span>
            </div>

            <p class="text-sm leading-relaxed text-slate-300">{{ filme.sinopse }}</p>
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
</style>
