<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'

const props = defineProps({
  filme: {
    type: Object,
    default: null,
  },
  carregandoDetalhes: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['fechar', 'assistir', 'adicionar-lista', 'curtir'])

const aberto = computed(() => props.filme !== null)

// O trailer só é montado quando o usuário pede, para não carregar o iframe
// do YouTube (e seus cookies) toda vez que o modal abre.
const trailerVisivel = ref(false)

const trailerUrl = computed(() =>
  props.filme?.trailer ? `https://www.youtube.com/embed/${props.filme.trailer}?autoplay=1` : null
)

function fechar() {
  emit('fechar')
}

function assistir() {
  emit('assistir', props.filme)
}

// Ainda sem persistência: os eventos ficam prontos para quando a lista e as
// curtidas ganharem backend, sem precisar mexer no template depois.
function adicionarLista() {
  emit('adicionar-lista', props.filme)
}

function curtir() {
  emit('curtir', props.filme)
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

// Ao trocar de filme, o trailer volta ao estado fechado.
watch(
  () => props.filme?.id,
  () => {
    trailerVisivel.value = false
  }
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
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
        @click.self="fechar"
      >
        <!--
          Altura fixa proporcional à tela: o modal mantém sempre o mesmo
          tamanho, então alternar entre filmes com sinopses curtas ou longas
          não faz a caixa "pular". O conteúdo excedente rola internamente.
        -->
        <div
          class="relative flex h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-white/10 bg-navy-900 shadow-2xl shadow-black/70"
        >
          <!--
            O botão de fechar fica fora da área rolável para permanecer fixo
            enquanto o restante do modal (banner + informações) rola junto.
          -->
          <button
            type="button"
            class="absolute right-4 top-4 z-20 flex h-9 w-9 items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/90"
            aria-label="Fechar"
            @click="fechar"
          >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
            </svg>
          </button>

          <!--
            O banner fica colado ao topo e o respiro inferior é um espaçador
            fixo no fim do fluxo rolável. Colocar o padding no bloco de conteúdo
            fazia a margem variar conforme a altura do texto; o espaçador
            garante sempre a mesma folga antes da borda inferior.
          -->
          <div class="modal-scroll min-h-0 flex-1 overflow-y-auto">
            <div class="relative h-64 w-full bg-slate-700/40 sm:h-80">
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

            <div class="space-y-5 px-6">
              <div class="flex flex-wrap items-center gap-3 text-sm">
                <span v-if="filme.nota" class="font-semibold text-amber-400">★ {{ filme.nota }}</span>
                <span v-if="filme.ano" class="text-slate-400">{{ filme.ano }}</span>
                <span v-if="filme.duracao" class="text-slate-400">{{ filme.duracao }}</span>
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

              <div v-if="filme.elenco?.length" class="space-y-1">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Elenco</h3>
                <p class="text-sm text-slate-300">{{ filme.elenco.join(', ') }}</p>
              </div>

              <!-- Trailer sob demanda: o iframe só existe após o clique. -->
              <div v-if="trailerVisivel && trailerUrl" class="aspect-video w-full overflow-hidden rounded-lg bg-black">
                <iframe
                  :src="trailerUrl"
                  title="Trailer"
                  class="h-full w-full"
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                  allowfullscreen
                />
              </div>

              <!-- Ações principais ficam ao final do conteúdo, após as informações. -->
              <div class="flex flex-wrap items-center gap-3 pt-1">
                <button
                  type="button"
                  class="inline-flex items-center gap-2 rounded-lg bg-white px-6 py-2.5 text-sm font-semibold text-navy-950 transition hover:bg-slate-200"
                  @click="assistir"
                >
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M8 5v14l11-7z" />
                  </svg>
                  Assistir
                </button>

                <button
                  v-if="trailerUrl"
                  type="button"
                  class="inline-flex items-center gap-2 rounded-lg border border-white/25 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10"
                  @click="trailerVisivel = !trailerVisivel"
                >
                  {{ trailerVisivel ? 'Fechar trailer' : 'Ver trailer' }}
                </button>

                <!--
                  Ações secundárias no formato circular da Netflix. Ainda sem
                  ação: os eventos ficam prontos para quando a lista e as
                  curtidas existirem no backend.
                -->
                <button
                  type="button"
                  class="flex h-11 w-11 items-center justify-center rounded-full border border-white/25 text-white transition hover:bg-white/10"
                  title="Adicionar à minha lista"
                  aria-label="Adicionar à minha lista"
                  @click="adicionarLista"
                >
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14" stroke-linecap="round" />
                  </svg>
                </button>

                <button
                  type="button"
                  class="flex h-11 w-11 items-center justify-center rounded-full border border-white/25 text-white transition hover:bg-white/10"
                  title="Gostei"
                  aria-label="Gostei"
                  @click="curtir"
                >
                  <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path
                      d="M7 10v11H4a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1h3zm0 0l4.5-7a2 2 0 0 1 3.6 1.6L14 9h5.2a2 2 0 0 1 2 2.4l-1.3 7A2 2 0 0 1 18 20H7"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </button>
              </div>

              <!-- Enquanto os detalhes completos não chegam, sinalizamos o carregamento. -->
              <p v-if="carregandoDetalhes" class="text-xs text-slate-500">Carregando detalhes…</p>
            </div>

            <!-- Espaçador fixo: mantém a folga inferior constante em qualquer filme. -->
            <div class="h-6" aria-hidden="true" />
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
 * Barra de rolagem ultrafina e sem adornos: 4px, sem borda nem margem, e sem
 * os botões de seta que o Chromium desenha por padrão. O seletor global não
 * alcança o conteúdo teleportado para o body de forma confiável, então
 * reforçamos aqui para manter a mesma aparência discreta.
 */
.modal-scroll {
  scrollbar-width: thin;
  scrollbar-color: #0d2743 transparent;
}

.modal-scroll::-webkit-scrollbar {
  width: 4px;
  height: 4px;
}

.modal-scroll::-webkit-scrollbar-track {
  background: transparent;
  border: none;
  margin: 0;
}

.modal-scroll::-webkit-scrollbar-thumb {
  background: #0d2743;
  border: none;
  border-radius: 9999px;
}

.modal-scroll::-webkit-scrollbar-thumb:hover {
  background: #112352;
}

/* Remove os botões de incremento/decremento (as setinhas) do Chromium. */
.modal-scroll::-webkit-scrollbar-button {
  display: none;
  width: 0;
  height: 0;
}

.modal-scroll::-webkit-scrollbar-corner {
  background: transparent;
}
</style>
