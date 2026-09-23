<script setup>
import { useApiStore } from '@/stores/api'

const api = useApiStore()
</script>

<template>
  <section class="rounded-lg bg-white p-8 shadow-sm">
    <h2 class="text-2xl font-semibold">Bem-vindo ao Stretor</h2>
    <p class="mt-2 text-slate-600">
      Plataforma de processamento de mídia com Laravel, Vue e FFmpeg.
    </p>
    <p class="mt-4 text-sm text-slate-500">
      API: <code class="rounded bg-slate-100 px-1">{{ api.baseUrl }}</code>
    </p>
  </section>
</template>
