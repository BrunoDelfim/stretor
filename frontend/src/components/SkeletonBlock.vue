<script setup>
/**
 * Bloco base de skeleton.
 *
 * É a peça mínima reaproveitada por todos os placeholders de carregamento
 * (cards do grid, campos do modal). Centralizar aqui garante que o brilho e o
 * arredondamento sejam idênticos em qualquer tela, bastando variar tamanho e
 * formato via props.
 */
defineProps({
  /** Classe de largura (ex.: 'w-full', 'w-24'). */
  largura: {
    type: String,
    default: 'w-full',
  },
  /** Classe de altura (ex.: 'h-4', 'h-64'). */
  altura: {
    type: String,
    default: 'h-4',
  },
  /** Classe de arredondamento (ex.: 'rounded', 'rounded-lg', 'rounded-full'). */
  arredondamento: {
    type: String,
    default: 'rounded',
  },
})
</script>

<template>
  <!-- Puramente decorativo: não deve ser anunciado por leitores de tela. -->
  <div
    class="skeleton"
    :class="[largura, altura, arredondamento]"
    aria-hidden="true"
  />
</template>
