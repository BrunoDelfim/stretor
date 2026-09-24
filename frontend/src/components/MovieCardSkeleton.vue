<script setup>
import SkeletonBlock from '@/components/SkeletonBlock.vue'

/**
 * Card fantasma exibido enquanto a próxima página de filmes não chega.
 *
 * Replica a estrutura do MovieCard (capa 2:3 + três linhas de texto) para que
 * os placeholders ocupem exatamente o mesmo espaço dos cards reais — assim a
 * troca acontece sem nenhum salto de layout.
 */
</script>

<template>
  <div class="flex flex-col overflow-hidden rounded-lg border border-white/10 bg-navy-900/70">
    <SkeletonBlock largura="w-full" altura="aspect-[2/3]" arredondamento="rounded-none" />

    <div class="flex flex-1 flex-col gap-2 p-3">
      <SkeletonBlock largura="w-4/5" altura="h-4" />
      <SkeletonBlock largura="w-2/5" altura="h-3" />
      <SkeletonBlock largura="w-1/3" altura="h-3" />
    </div>
  </div>
</template>
