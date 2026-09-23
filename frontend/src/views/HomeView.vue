<script setup>
import { computed, onMounted, ref } from 'vue'
import HeroCarousel from '@/components/HeroCarousel.vue'
import LoadingOverlay from '@/components/LoadingOverlay.vue'
import MovieGrid from '@/components/MovieGrid.vue'
import MovieModal from '@/components/MovieModal.vue'
import { useMoviesStore } from '@/stores/movies'

const movies = useMoviesStore()

const filmeSelecionado = ref(null)

const tituloGrid = computed(() =>
  movies.emBusca ? `Resultados para "${movies.termoBusca}"` : 'Filmes mais assistidos no Brasil'
)

function abrirModal(filme) {
  filmeSelecionado.value = filme
}

function fecharModal() {
  filmeSelecionado.value = null
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
        sensação de que o conteúdo "cobre" o destaque.
      -->
      <div class="relative z-10 -mt-16 bg-gradient-to-b from-transparent to-navy-950/80">
        <MovieGrid
          :filmes="movies.filmes"
          :titulo="tituloGrid"
          @selecionar="abrirModal"
        />
      </div>
    </template>

    <MovieModal :filme="filmeSelecionado" @fechar="fecharModal" />
  </div>
</template>
