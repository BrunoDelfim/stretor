<script setup>
/**
 * Seletor de temporadas em formato de balões clicáveis.
 *
 * É o padrão que os streamings consagraram: uma faixa horizontal de botões, um
 * por temporada, com a ativa destacada. Preferimos isso a um `<select>` porque
 * o usuário vê todas as temporadas de uma vez e troca com um clique só — sem
 * abrir menu, sem rolar lista.
 */
defineProps({
  /** Lista de temporadas normalizadas pelo backend. */
  temporadas: {
    type: Array,
    default: () => [],
  },
  /** Número da temporada atualmente selecionada. */
  ativa: {
    type: Number,
    default: null,
  },
})

const emit = defineEmits(['selecionar'])

function selecionar(numero) {
  emit('selecionar', numero)
}
</script>

<template>
  <div v-if="temporadas.length" class="space-y-2">
    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Temporadas</h3>

    <!--
      A faixa rola na horizontal quando há muitas temporadas (séries longas
      passam de 10). O `-mx-1 px-1` evita que o anel de foco do primeiro e do
      último botão sejam cortados pelo `overflow`.
    -->
    <div class="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1">
      <button
        v-for="temporada in temporadas"
        :key="temporada.numero"
        type="button"
        class="shrink-0 rounded-full border px-4 py-1.5 text-sm font-semibold transition"
        :class="
          temporada.numero === ativa
            ? 'border-white bg-white text-navy-950'
            : 'border-white/25 text-slate-200 hover:bg-white/10'
        "
        :aria-pressed="temporada.numero === ativa"
        @click="selecionar(temporada.numero)"
      >
        T{{ temporada.numero }}
      </button>
    </div>
  </div>
</template>
