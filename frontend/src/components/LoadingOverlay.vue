<script setup>
defineProps({
  mensagem: {
    type: String,
    default: 'Carregando filmes...',
  },
})
</script>

<template>
  <!--
    Abertura no estilo Netflix: o nome da marca pulsa enquanto a primeira
    resposta da API não chega. O overlay cobre a tela inteira (z-50, acima da
    navbar z-40) para que nada do restante da interface apareça durante o
    carregamento, e o conteúdo fica centralizado vertical e horizontalmente.
  -->
  <div
    class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-navy-950"
    role="status"
    aria-live="polite"
  >
    <span class="logo-pulse text-4xl font-black tracking-[0.35em] text-white sm:text-6xl">
      STRETOR
    </span>

    <div class="h-1 w-48 overflow-hidden rounded-full bg-white/10 sm:w-64">
      <span class="loading-bar block h-full w-1/3 rounded-full bg-brand-500" />
    </div>

    <p class="text-xs uppercase tracking-[0.3em] text-slate-400">{{ mensagem }}</p>
  </div>
</template>

<style scoped>
/*
 * O pulso alterna escala e opacidade para dar a sensação de "respiração" da
 * marca, sem deslocar o layout — a transformação não afeta o fluxo.
 */
.logo-pulse {
  animation: logo-pulse 1.8s ease-in-out infinite;
}

@keyframes logo-pulse {
  0%,
  100% {
    transform: scale(0.92);
    opacity: 0.75;
  }

  50% {
    transform: scale(1.06);
    opacity: 1;
  }
}

/* Barra indeterminada: desliza da esquerda para a direita continuamente. */
.loading-bar {
  animation: loading-bar 1.4s ease-in-out infinite;
}

@keyframes loading-bar {
  0% {
    transform: translateX(-100%);
  }

  100% {
    transform: translateX(300%);
  }
}
</style>
