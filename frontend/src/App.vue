<script setup>
import { RouterView } from 'vue-router'
import AppNavbar from '@/components/AppNavbar.vue'
import { useMoviesStore } from '@/stores/movies'

const movies = useMoviesStore()
</script>

<template>
  <div class="min-h-screen">
    <!-- A navbar é global: aparece em todas as telas e a busca alimenta a store. -->
    <AppNavbar @buscar="movies.buscar" />

    <main class="pb-16">
      <RouterView />
    </main>
  </div>
</template>
