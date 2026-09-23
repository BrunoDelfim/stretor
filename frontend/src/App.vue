<script setup>
import { RouterView } from 'vue-router'
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
        <h1 class="text-xl font-bold text-brand-600">Stretor</h1>
        <nav class="flex gap-4 text-sm">
          <RouterLink to="/" class="hover:text-brand-600">Início</RouterLink>
        </nav>
      </div>
    </header>

    <main class="mx-auto max-w-6xl px-6 py-8">
      <RouterView />
    </main>
  </div>
</template>
