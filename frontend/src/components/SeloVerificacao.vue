<script setup>
import { computed } from 'vue'

/**
 * Selo de verificação de fonte para o card.
 *
 * A Home mostra dois estados que o usuário só descobria ao clicar: título com
 * fonte provada pelo agendador (o card abre e toca) e título que ainda não
 * passou pelo portão ou cuja prova venceu (o card pode abrir sem fonte). O selo
 * antecipa isso — verde para verificado, cinza para o resto.
 */
const props = defineProps({
  verificado: {
    type: Boolean,
    default: false,
  },
  // Data ISO da última prova, usada só no tooltip. Ausente, o selo fica sem data.
  verificadoEm: {
    type: String,
    default: null,
  },
})

const rotulo = computed(() => (props.verificado ? 'Verificado' : 'Não verificado'))

// A data é formatada no fuso de quem está olhando: o backend grava em UTC e o
// navegador sabe a hora local melhor que o servidor.
const dataLegivel = computed(() => {
  if (!props.verificadoEm) {
    return null
  }

  const data = new Date(props.verificadoEm)

  if (Number.isNaN(data.getTime())) {
    return null
  }

  return data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
})

const descricao = computed(() => {
  if (!props.verificado) {
    return 'Fonte ainda não verificada ou prova vencida — pode não haver link.'
  }

  return dataLegivel.value
    ? `Fonte verificada em ${dataLegivel.value}.`
    : 'Fonte verificada.'
})
</script>

<template>
  <span
    class="pointer-events-none inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold leading-none"
    :class="
      verificado
        ? 'bg-emerald-400/95 text-navy-950'
        : 'bg-slate-900/85 text-slate-300 ring-1 ring-inset ring-white/20'
    "
    :title="descricao"
    :aria-label="descricao"
    role="status"
  >
    <svg
      v-if="verificado"
      class="h-2.5 w-2.5"
      viewBox="0 0 20 20"
      fill="currentColor"
      aria-hidden="true"
    >
      <path
        fill-rule="evenodd"
        d="M16.707 5.293a1 1 0 010 1.414l-7.25 7.25a1 1 0 01-1.414 0l-3.25-3.25a1 1 0 111.414-1.414l2.543 2.543 6.543-6.543a1 1 0 011.414 0z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else class="h-2.5 w-2.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
      <path
        fill-rule="evenodd"
        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
        clip-rule="evenodd"
      />
    </svg>
    {{ rotulo }}
  </span>
</template>
