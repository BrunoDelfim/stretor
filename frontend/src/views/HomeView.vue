<script setup>
import { computed, onMounted, ref } from 'vue'
import HeroCarousel from '@/components/HeroCarousel.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import MovieGrid from '@/components/MovieGrid.vue'
import MovieModal from '@/components/MovieModal.vue'
import { moviesService } from '@/services/movies'
import { useMoviesStore } from '@/stores/movies'

const movies = useMoviesStore()

const filmeSelecionado = ref(null)
const carregandoDetalhes = ref(false)

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
        @selecionar="abrirModal"
      />

      <!--
        O grid sobe por cima do carrossel conforme a rolagem, criando a
        sensação de que o conteúdo "cobre" o destaque. O degradê começa na
        mesma cor com que o carrossel termina (navy-950) e só então se abre
        para o fundo da página, o que elimina a divisão reta entre os dois.
        O meio permanece escuro para segurar capas claras na transição.
      -->
      <div class="relative z-10 -mt-24 bg-gradient-to-b from-navy-950 via-navy-950/80 to-transparent">
        <MovieGrid
          :filmes="movies.filmes"
          :titulo="tituloGrid"
          @selecionar="abrirModal"
        />
      </div>
    </template>

    <MovieModal
      :filme="filmeSelecionado"
      :carregando-detalhes="carregandoDetalhes"
      @fechar="fecharModal"
    />
  </div>
</template>
