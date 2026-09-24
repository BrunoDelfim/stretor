<script setup>
import { computed, onMounted, ref } from 'vue'
import HeroCarousel from '@/components/HeroCarousel.vue'
import InfiniteScrollSentinel from '@/components/InfiniteScrollSentinel.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import MovieGrid from '@/components/MovieGrid.vue'
import MovieModal from '@/components/MovieModal.vue'
import PlayerOverlay from '@/components/PlayerOverlay.vue'
import { moviesService } from '@/services/movies'
import { useMoviesStore } from '@/stores/movies'

const movies = useMoviesStore()

const filmeSelecionado = ref(null)
const carregandoDetalhes = ref(false)

// Filme em reprodução. Enquanto preenchido, o PlayerOverlay cobre a tela e
// conduz a busca de fontes, a conversão e a reprodução.
const filmeEmReproducao = ref(null)

const tituloGrid = computed(() =>
  movies.emBusca ? `Resultados para "${movies.termoBusca}"` : 'Filmes mais assistidos no Brasil'
)

/**
 * A listagem já traz o essencial para o modal abrir instantaneamente.
 * Em paralelo buscamos os detalhes completos (duração, elenco, trailer) e
 * substituímos o objeto assim que a resposta chega, sem travar a abertura.
 */
async function abrirModal(filme) {
  filmeSelecionado.value = filme

  if (!filme?.id) return

  carregandoDetalhes.value = true

  try {
    const detalhes = await moviesService.detalhes(filme.id)

    // Só aplica se o modal ainda estiver exibindo o mesmo filme — evita que
    // uma resposta atrasada sobrescreva um filme aberto depois.
    if (detalhes && filmeSelecionado.value?.id === filme.id) {
      filmeSelecionado.value = { ...filme, ...detalhes }
    }
  } catch {
    // Falha nos detalhes não impede o uso do modal com os dados da listagem.
  } finally {
    carregandoDetalhes.value = false
  }
}

function fecharModal() {
  filmeSelecionado.value = null
  carregandoDetalhes.value = false
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

    <PlayerOverlay :filme="filmeEmReproducao" @fechar="fecharPlayer" />
  </div>
</template>
