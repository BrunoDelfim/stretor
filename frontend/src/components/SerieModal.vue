<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import ListaEpisodios from '@/components/ListaEpisodios.vue'
import SeletorTemporada from '@/components/SeletorTemporada.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import { moviesService } from '@/services/movies'

/**
 * Modal de série.
 *
 * Nasceu como um irmão do MovieModal em vez de uma variação dele: o fluxo de
 * filme já funciona e não podia ser arriscado. O layout visual é o mesmo
 * (banner, informações, sinopse, elenco, trailer e botões), mas abaixo dos
 * botões entra o que só a série tem — o seletor de temporada e a lista de
 * episódios.
 */
const props = defineProps({
  /** Ficha da série vinda da listagem (título, capa, nota, ano...). */
  serie: {
    type: Object,
    default: null,
  },
  /** Exibe os skeletons enquanto os detalhes completos chegam. */
  carregandoDetalhes: {
    type: Boolean,
    default: false,
  },
  /**
   * Temporada selecionada, controlada pelo pai.
   *
   * O estado vive no HomeView — e não aqui dentro — porque o modal sai de cena
   * durante a reprodução (`serie` vira `null`) e qualquer recriação do
   * componente apagaria a seleção. Com a temporada no pai, fechar o player
   * devolve o modal exatamente na temporada em que o usuário estava.
   */
  temporadaAtiva: {
    type: Number,
    default: null,
  },
})

const emit = defineEmits([
  'fechar',
  'assistir',
  'adicionar-lista',
  'curtir',
  'atualizar-temporada',
])

const aberto = computed(() => props.serie !== null)

const trailerVisivel = ref(false)

const trailerUrl = computed(() =>
  props.serie?.trailer ? `https://www.youtube.com/embed/${props.serie.trailer}?autoplay=1` : null
)

// Os episódios da temporada ativa. A temporada em si vem do pai (prop), para
// sobreviver ao ciclo de abrir/fechar o player.
const episodios = ref([])
const carregandoEpisodios = ref(false)

const temporadas = computed(() => props.serie?.temporadas ?? [])

/**
 * Arte da série, repassada à lista de episódios como reserva.
 *
 * O TMDB nem sempre tem o `still` do episódio; quando falta, a linha repete a
 * arte da série em vez de mostrar a moldura vazia. A capa (pôster) vem primeiro
 * porque é a imagem mais reconhecível; o backdrop entra como segunda opção.
 */
const arteDaSerie = computed(() => props.serie?.capa || props.serie?.backdrop || null)

/**
 * Troca a temporada ativa avisando o pai.
 *
 * O estado é do pai, então não escrevemos na prop: emitimos a intenção e
 * deixamos o HomeView atualizar a fonte da verdade.
 */
function definirTemporada(numero) {
  emit('atualizar-temporada', numero)
}

function fechar() {
  emit('fechar')
}

/**
 * Ação principal do modal ("Assistir").
 *
 * Emitir a ficha pura da série (sem `temporada`/`episodio`) fazia a busca cair no
 * nível da série. O Torrentio responde pela série inteira e mistura temporadas —
 * foi assim que "American Horror Story" aberto na 1ª temporada voltou com um
 * "S13E03" — e, sem numeração, o corte que descarta outra temporada nem entra em
 * ação. Pior: sem `temporada`/`episodio` o media-service escolhe o maior arquivo
 * do pack, ou seja, um episódio qualquer. Por isso o botão passa a disparar o
 * primeiro episódio da temporada ativa.
 */
function assistir() {
  // Caminho comum: a temporada ativa já está carregada.
  if (episodios.value.length) {
    assistirEpisodio(episodios.value[0])

    return
  }

  // Episódios ainda não chegaram (detalhes pendentes ou temporada carregando).
  // Ainda assim mandamos a numeração — a temporada ativa é a referência e o
  // primeiro episódio do TMDB tem número 1 — para a busca nunca acontecer no
  // nível da série.
  emit('assistir', {
    ...props.serie,
    temporada: props.temporadaAtiva ?? temporadas.value[0]?.numero ?? 1,
    episodio: 1,
  })
}

function adicionarLista() {
  emit('adicionar-lista', props.serie)
}

function curtir() {
  emit('curtir', props.serie)
}

/**
 * Carrega os episódios da temporada escolhida.
 *
 * Guardamos o número pedido e só aplicamos a resposta se ele ainda for o ativo:
 * trocar de temporada rápido dispara duas requisições e a mais lenta não pode
 * sobrescrever a seleção atual.
 */
async function carregarEpisodios(numero) {
  if (!props.serie?.id || numero === null) return

  carregandoEpisodios.value = true

  try {
    const dados = await moviesService.temporada(props.serie.id, numero)

    if (props.temporadaAtiva === numero) {
      episodios.value = dados?.episodios ?? []
    }
  } catch {
    if (props.temporadaAtiva === numero) {
      episodios.value = []
    }
  } finally {
    if (props.temporadaAtiva === numero) {
      carregandoEpisodios.value = false
    }
  }
}

function selecionarTemporada(numero) {
  if (numero === props.temporadaAtiva) return

  episodios.value = []
  definirTemporada(numero)
  carregarEpisodios(numero)
}

function assistirEpisodio(episodio) {
  // O player recebe a série com a temporada e o episódio anexados: é daqui que
  // a busca de fontes monta o termo "Titulo S01E02". A temporada recua para a
  // ativa quando o episódio não a declara: sem ela a busca vira consulta de
  // série inteira — o Torrentio devolve qualquer temporada e o media-service
  // escolhe um arquivo aleatório do pack.
  emit('assistir', {
    ...props.serie,
    temporada: episodio.temporada ?? props.temporadaAtiva,
    episodio: episodio.numero,
    episodio_titulo: episodio.titulo,
  })
}

function aoTeclar(evento) {
  if (evento.key === 'Escape') fechar()
}

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

// Ao trocar de série, o trailer fecha e a primeira temporada é selecionada e
// carregada automaticamente.
//
// O `serie` fica `null` enquanto o player está aberto (o modal sai de cena para
// liberar a imagem pesada) e volta ao objeto quando ele fecha. Essa ida e volta
// não pode ser lida como "trocou de série", senão a temporada volta para a
// primeira e o usuário perde a S12E01 que estava vendo.
//
// A guarda é pelo último `id` **válido**: quando o player abre, `serie` vira
// `null` e o `id` some — esse estado é ignorado, sem sobrescrever a referência.
// Só resetamos quando chega um `id` diferente do último que já vimos.
let ultimoId = null

watch(
  () => props.serie?.id,
  (id) => {
    if (id === undefined || id === null) return
    if (id === ultimoId) return

    ultimoId = id

    trailerVisivel.value = false
    episodios.value = []

    // Apenas sinalizamos a primeira temporada; o watch de `temporadaAtiva`
    // abaixo é quem dispara o carregamento, evitando duas requisições iguais.
    const primeira = temporadas.value[0]?.numero ?? null
    definirTemporada(primeira)
  },
  { immediate: true }
)

// A temporada ativa é do pai. Sempre que ela mudar — inclusive quando o modal
// volta a aparecer depois do player — recarregamos os episódios correspondentes.
watch(
  () => props.temporadaAtiva,
  (numero) => {
    if (numero === null || numero === undefined) return

    episodios.value = []
    carregarEpisodios(numero)
  }
)

// As temporadas só existem depois que os detalhes chegam. Quando isso acontece,
// garantimos que a primeira esteja selecionada — o carregamento fica por conta
// do watch de `temporadaAtiva`.
watch(temporadas, (lista) => {
  if (props.temporadaAtiva === null && lista.length) {
    definirTemporada(lista[0].numero)
  }
})

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
        <div
          class="relative flex h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-white/10 bg-navy-900 shadow-2xl shadow-black/70"
        >
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

          <div class="modal-scroll min-h-0 flex-1 overflow-y-auto">
            <div class="relative h-64 w-full bg-slate-700/40 sm:h-80">
              <img
                v-if="serie.backdrop_alta || serie.backdrop"
                :src="serie.backdrop_alta || serie.backdrop"
                :alt="serie.titulo"
                class="h-full w-full object-cover"
              />
              <div class="absolute inset-0 bg-gradient-to-t from-navy-900 via-navy-900/40 to-transparent" />

              <div class="absolute inset-x-0 bottom-0 p-6">
                <h2 class="text-2xl font-bold text-white drop-shadow sm:text-3xl">
                  {{ serie.titulo }}
                </h2>
              </div>
            </div>

            <div class="space-y-5 px-6">
              <div class="flex flex-wrap items-center gap-3 text-sm">
                <span v-if="serie.nota" class="font-semibold text-amber-400">★ {{ serie.nota }}</span>
                <span v-if="serie.ano" class="text-slate-400">{{ serie.ano }}</span>

                <template v-if="carregandoDetalhes">
                  <SkeletonBlock largura="w-16" altura="h-4" />
                  <SkeletonBlock largura="w-20" altura="h-5" arredondamento="rounded-full" />
                  <SkeletonBlock largura="w-24" altura="h-5" arredondamento="rounded-full" />
                </template>

                <template v-else>
                  <span v-if="serie.numero_temporadas" class="text-slate-400">
                    {{ serie.numero_temporadas }}
                    {{ serie.numero_temporadas === 1 ? 'temporada' : 'temporadas' }}
                  </span>
                  <span class="rounded border border-white/20 px-2 py-0.5 text-slate-200">
                    {{ serie.classificacao }}
                  </span>
                  <span
                    v-for="genero in serie.generos"
                    :key="genero"
                    class="rounded-full bg-white/10 px-3 py-0.5 text-slate-200"
                  >
                    {{ genero }}
                  </span>
                </template>
              </div>

              <div v-if="carregandoDetalhes" class="space-y-2">
                <SkeletonBlock largura="w-full" altura="h-4" />
                <SkeletonBlock largura="w-11/12" altura="h-4" />
                <SkeletonBlock largura="w-3/4" altura="h-4" />
              </div>
              <p v-else class="text-sm leading-relaxed text-slate-300">{{ serie.sinopse }}</p>

              <div v-if="carregandoDetalhes" class="space-y-2">
                <SkeletonBlock largura="w-16" altura="h-3" />
                <SkeletonBlock largura="w-2/3" altura="h-4" />
              </div>
              <div v-else-if="serie.elenco?.length" class="space-y-1">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Elenco</h3>
                <p class="text-sm text-slate-300">{{ serie.elenco.join(', ') }}</p>
              </div>

              <div v-if="trailerVisivel && trailerUrl" class="aspect-video w-full overflow-hidden rounded-lg bg-black">
                <iframe
                  :src="trailerUrl"
                  title="Trailer"
                  class="h-full w-full"
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                  allowfullscreen
                />
              </div>

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

              <!--
                A partir daqui começa o que é exclusivo da série: o seletor de
                temporada e a lista de episódios. Fica abaixo dos botões para
                não competir com as ações principais.
              -->
              <div v-if="!carregandoDetalhes && temporadas.length" class="space-y-4 border-t border-white/10 pt-5">
                <SeletorTemporada
                  :temporadas="temporadas"
                  :ativa="temporadaAtiva"
                  @selecionar="selecionarTemporada"
                />

                <ListaEpisodios
                  :episodios="episodios"
                  :carregando="carregandoEpisodios"
                  :capa-serie="arteDaSerie"
                  @assistir="assistirEpisodio"
                />
              </div>
            </div>

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

.modal-scroll::-webkit-scrollbar-button {
  display: none;
  width: 0;
  height: 0;
}

.modal-scroll::-webkit-scrollbar-corner {
  background: transparent;
}
</style>
