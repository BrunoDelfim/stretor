<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const props = defineProps({
  filmes: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['selecionar'])

const TOTAL_INDICADORES = 6
const INTERVALO_AUTOPLAY = 7000

const indiceAtual = ref(0)

let autoplay = null

// Garante sempre 6 posições no carrossel, mesmo com poucos filmes carregados.
const slides = computed(() => {
  const base = props.filmes.slice(0, TOTAL_INDICADORES)

  return Array.from({ length: TOTAL_INDICADORES }, (_, indice) => base[indice] ?? null)
})

const slideAtual = computed(() => slides.value[indiceAtual.value])

function irPara(indice) {
  indiceAtual.value = (indice + TOTAL_INDICADORES) % TOTAL_INDICADORES
  reiniciarAutoplay()
}

function proximo() {
  irPara(indiceAtual.value + 1)
}

function reiniciarAutoplay() {
  clearInterval(autoplay)
  autoplay = setInterval(proximo, INTERVALO_AUTOPLAY)
}

function selecionar() {
  if (!slideAtual.value) return

  emit('selecionar', slideAtual.value)
}

onMounted(reiniciarAutoplay)
onUnmounted(() => clearInterval(autoplay))
</script>

<template>
  <!--
    O carrossel ocupa a tela de ponta a ponta e encosta no topo, ficando
    atrás da navbar transparente. O conteúdo seguinte sobe por cima dele
    conforme a página rola.
  -->
  <section
    class="relative -mt-20 h-[100vh] min-h-[34rem] w-full select-none overflow-hidden"
  >
    <div class="relative h-full w-full" @click="selecionar">
      <img
        v-if="slideAtual?.backdrop"
        :src="slideAtual.backdrop"
        :alt="slideAtual.titulo"
        class="h-full w-full object-cover"
        draggable="false"
      />

      <div
        v-else
        class="flex h-full w-full items-center justify-center bg-slate-700/40 backdrop-blur-sm"
      >
        <span class="text-lg font-medium text-slate-200">
          {{ slideAtual ? `${slideAtual.titulo} com link` : 'NOME FILME com link' }}
        </span>
      </div>

      <!-- Gradiente para dar contraste ao texto e fundir o carrossel com o fundo. -->
      <div
        class="pointer-events-none absolute inset-0 bg-gradient-to-t from-navy-950 via-navy-900/50 to-navy-800/40"
      />

      <!--
        Esfumação inferior: uma faixa alta e suave que dissolve a base do
        carrossel no fundo da página. O meio da faixa é bem escuro para que
        capas claras do grid não transpareçam na zona de transição, e as
        paradas intermediárias evitam que o degradê vire uma linha reta.
      -->
      <div
        class="pointer-events-none absolute inset-x-0 bottom-0 h-64 bg-gradient-to-b from-transparent via-navy-950/80 to-navy-950"
      />

      <div
        v-if="slideAtual"
        class="pointer-events-none absolute inset-x-0 bottom-28 mx-auto max-w-7xl px-6"
      >
        <h2 class="max-w-3xl text-4xl font-bold text-white drop-shadow-lg sm:text-5xl">
          {{ slideAtual.titulo }}
        </h2>
        <p class="mt-3 max-w-2xl text-sm text-slate-200 line-clamp-3 sm:text-base">
          {{ slideAtual.sinopse }}
        </p>
      </div>
    </div>

    <!--
      Os indicadores ficam acima das camadas de degradê (z-20), senão a
      esfumação inferior os encobre por serem renderizados depois no DOM.
    -->
    <div class="absolute inset-x-0 bottom-20 z-20 flex items-center justify-center gap-2">
      <button
        v-for="(_, indice) in slides"
        :key="indice"
        type="button"
        class="h-2 rounded-full transition-all duration-300"
        :class="indice === indiceAtual ? 'w-8 bg-white' : 'w-2 bg-white/40 hover:bg-white/70'"
        :aria-label="`Ir para o slide ${indice + 1}`"
        @click.stop="irPara(indice)"
      />
    </div>
  </section>
</template>
