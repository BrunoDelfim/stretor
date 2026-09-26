<script setup>
import { computed, onMounted, ref } from 'vue'
import HeroCarousel from '@/components/HeroCarousel.vue'
import InfiniteScrollSentinel from '@/components/InfiniteScrollSentinel.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import MovieGrid from '@/components/MovieGrid.vue'
import MovieModal from '@/components/MovieModal.vue'
import PlayerOverlay from '@/components/PlayerOverlay.vue'
import SerieModal from '@/components/SerieModal.vue'
import { moviesService } from '@/services/movies'
import { useMoviesStore } from '@/stores/movies'

const movies = useMoviesStore()

const filmeSelecionado = ref(null)
const carregandoDetalhes = ref(false)

// Série aberta no modal próprio. Fica separada de `filmeSelecionado` para que
// cada modal receba apenas o tipo de conteúdo que sabe exibir.
const serieSelecionada = ref(null)
const carregandoDetalhesSerie = ref(false)

// Temporada selecionada no modal de série. Mora aqui, e não dentro do modal,
// porque o modal sai de cena durante a reprodução (`serie` vira `null`): se o
// estado ficasse lá, fechar o player devolveria o usuário à primeira temporada.
const temporadaAtivaSerie = ref(null)

// Filme em reprodução. Enquanto preenchido, o PlayerOverlay cobre a tela e
// conduz a busca de fontes, a conversão e a reprodução.
const filmeEmReproducao = ref(null)

const tituloGrid = computed(() =>
  movies.emBusca ? `Resultados para "${movies.termoBusca}"` : 'Em alta hoje'
)

/**
 * A listagem já traz o essencial para o modal abrir instantaneamente.
 * Em paralelo buscamos os detalhes completos (duração, elenco, trailer) e
 * substituímos o objeto assim que a resposta chega, sem travar a abertura.
 *
 * O tipo decide qual modal abre: série vai para o SerieModal (que carrega as
 * temporadas) e filme segue no MovieModal, sem nenhuma mudança de fluxo.
 */
async function abrirModal(item) {
  if (item?.tipo === 'tv') {
    abrirModalSerie(item)

    return
  }

  filmeSelecionado.value = item

  if (!item?.id) return

  carregandoDetalhes.value = true

  try {
    const detalhes = await moviesService.detalhes(item.id)

    // Só aplica se o modal ainda estiver exibindo o mesmo filme — evita que
    // uma resposta atrasada sobrescreva um filme aberto depois.
    if (detalhes && filmeSelecionado.value?.id === item.id) {
      filmeSelecionado.value = { ...item, ...detalhes }
    }
  } catch {
    // Falha nos detalhes não impede o uso do modal com os dados da listagem.
  } finally {
    carregandoDetalhes.value = false
  }
}

/**
 * Abre o modal de série e busca a ficha completa (temporadas, elenco, trailer).
 *
 * A série aparece na hora com os dados da listagem; as temporadas chegam em
 * seguida e disparam o carregamento da primeira dentro do próprio modal.
 */
async function abrirModalSerie(serie) {
  // Só zera a temporada quando é outra série: reabrir a mesma (ou voltar do
  // player) precisa preservar a seleção.
  if (serieSelecionada.value?.id !== serie?.id) {
    temporadaAtivaSerie.value = null
  }

  serieSelecionada.value = serie

  if (!serie?.id) return

  carregandoDetalhesSerie.value = true

  try {
    const detalhes = await moviesService.detalhesSerie(serie.id)

    if (detalhes && serieSelecionada.value?.id === serie.id) {
      serieSelecionada.value = { ...serie, ...detalhes }
    }
  } catch {
    // Sem os detalhes a série ainda abre, mas sem o seletor de temporadas.
  } finally {
    carregandoDetalhesSerie.value = false
  }
}

function fecharModal() {
  filmeSelecionado.value = null
  carregandoDetalhes.value = false
}

function fecharModalSerie() {
  serieSelecionada.value = null
  carregandoDetalhesSerie.value = false
  temporadaAtivaSerie.value = null
}

/**
 * Recebe a temporada escolhida no modal e guarda aqui, para que a seleção
 * sobreviva ao ciclo de abrir/fechar o player.
 */
function atualizarTemporadaSerie(numero) {
  temporadaAtivaSerie.value = numero
}

/**
 * Abre o player para o filme escolhido.
 *
 * O modal permanece montado por baixo: ao fechar o player, o usuário volta
 * exatamente ao ponto em que estava.
 */
function assistir(filme) {
  filmeEmReproducao.value = filme
}

function fecharPlayer() {
  filmeEmReproducao.value = null
}

onMounted(() => {
  // Só busca de novo se ainda não houver dados (ex.: voltando de outra rota).
  if (!movies.filmes.length) {
    movies.carregarPopulares()
  }
})
</script>

<template>
  <div>
    <!-- A página só é liberada depois que a primeira resposta chega. -->
    <LoadingOverlay v-if="movies.carregando && !movies.filmes.length" />

    <template v-else>
      <div
        v-if="movies.erro"
        class="mx-auto mt-28 max-w-3xl rounded-lg border border-red-500/30 bg-red-500/10 px-6 py-4 text-sm text-red-200"
      >
        {{ movies.erro }}
      </div>

      <HeroCarousel
        v-if="!movies.emBusca"
        :filmes="movies.destaques"
        :pausado="filmeEmReproducao !== null"
        @selecionar="abrirModal"
      />

      <!--
        O grid sobe por cima do carrossel conforme a rolagem, criando a
        sensação de que o conteúdo "cobre" o destaque. O degradê começa na
        mesma cor com que o carrossel termina (navy-950) e só então se abre
        para o fundo da página, o que elimina a divisão reta entre os dois.
        O meio permanece escuro para segurar capas claras na transição.
      -->
      <div
        class="relative z-10 -mt-24 bg-gradient-to-b from-navy-950 via-navy-950/80 to-transparent"
        :class="{ invisible: filmeEmReproducao !== null }"
      >
        <MovieGrid
          :filmes="movies.filmes"
          :titulo="tituloGrid"
          @selecionar="abrirModal"
        >
          <!--
            A sentinela só é ativada quando há próxima página e não estamos em
            modo de busca — assim a rolagem infinita não interfere nos resultados
            de pesquisa, que usam a mesma lista.
          -->
          <template #rodape>
            <InfiniteScrollSentinel
              v-if="!movies.emBusca"
              :ativo="movies.temMais"
              :carregando="movies.carregandoMais"
              @carregar-mais="movies.carregarMais"
            />
          </template>
        </MovieGrid>
      </div>
    </template>

    <!--
      Durante a reprodução o modal sai de cena: ele segura o `backdrop_alta` em
      resolução original, a imagem mais pesada da tela. `filmeSelecionado` é
      preservado, então ao fechar o player o modal volta como estava.
    -->
    <MovieModal
      :filme="filmeEmReproducao ? null : filmeSelecionado"
      :carregando-detalhes="carregandoDetalhes"
      @fechar="fecharModal"
      @assistir="assistir"
    />

    <!--
      O modal de série segue a mesma regra do de filme: sai de cena durante a
      reprodução para liberar a imagem pesada, mas mantém a seleção para voltar
      exatamente ao ponto em que estava.
    -->
    <SerieModal
      :serie="filmeEmReproducao ? null : serieSelecionada"
      :carregando-detalhes="carregandoDetalhesSerie"
      :temporada-ativa="temporadaAtivaSerie"
      @fechar="fecharModalSerie"
      @assistir="assistir"
      @atualizar-temporada="atualizarTemporadaSerie"
    />

    <PlayerOverlay :filme="filmeEmReproducao" @fechar="fecharPlayer" />
  </div>
</template>
