<script setup>
import MovieCard from '@/components/MovieCard.vue'

defineProps({
  filmes: {
    type: Array,
    default: () => [],
  },
  titulo: {
    type: String,
    default: 'Filmes populares',
  },
})

defineEmits(['selecionar'])
</script>

<template>
  <section class="mx-auto mt-12 w-full max-w-7xl px-6">
    <h2 class="mb-5 text-xl font-bold text-white sm:text-2xl">{{ titulo }}</h2>

    <p v-if="!filmes.length" class="text-sm text-slate-400">
      Nenhum filme encontrado.
    </p>

    <!-- 6 colunas por linha: 3 linhas exibem 18 itens por seção. -->
    <div
      v-else
      class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6"
    >
      <MovieCard
        v-for="filme in filmes"
        :key="filme.id"
        :filme="filme"
        @selecionar="$emit('selecionar', $event)"
      />
    </div>

    <!--
      Slot de rodapé: a Home injeta aqui a sentinela de rolagem infinita. O grid
      continua puramente apresentacional e não precisa conhecer paginação.
    -->
    <slot name="rodape" />
  </section>
</template>
