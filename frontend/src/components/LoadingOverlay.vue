<script setup>
defineProps({
  mensagem: {
    type: String,
    default: 'Carregando filmes...',
  },
})
</script>

<template>
  <div class="flex min-h-[70vh] flex-col items-center justify-center gap-4">
    <span
      class="h-12 w-12 animate-spin rounded-full border-4 border-white/15 border-t-white"
      role="status"
      aria-label="Carregando"
    />
    <p class="text-sm tracking-wide text-slate-300">{{ mensagem }}</p>
  </div>
</template>
