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
const arrastando = ref(false)
const deslocamento = ref(0)

let inicioX = 0
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

function aoPressionar(evento) {
  arrastando.value = true
  inicioX = evento.touches ? evento.touches[0].clientX : evento.clientX
  deslocamento.value = 0
}

function aoMover(evento) {
  if (!arrastando.value) return

  const x = evento.touches ? evento.touches[0].clientX : evento.clientX
  deslocamento.value = x - inicioX
}

function aoSoltar() {
  if (!arrastando.value) return

  const LIMITE = 60

  if (deslocamento.value <= -LIMITE) {
    proximo()
  } else if (deslocamento.value >= LIMITE) {
    irPara(indiceAtual.value - 1)
  }

  arrastando.value = false
  deslocamento.value = 0
}

function selecionar() {
  // Um arraste não deve ser interpretado como clique.
  if (Math.abs(deslocamento.value) > 10 || !slideAtual.value) return

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
    class="relative -mt-20 h-[85vh] min-h-[32rem] w-full select-none overflow-hidden"
  >
    <div
      class="relative h-full w-full cursor-grab active:cursor-grabbing"
      @mousedown="aoPressionar"
      @mousemove="aoMover"
      @mouseup="aoSoltar"
      @mouseleave="aoSoltar"
      @touchstart.passive="aoPressionar"
      @touchmove.passive="aoMover"
      @touchend="aoSoltar"
      @click="selecionar"
    >
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
        class="pointer-events-none absolute inset-0 bg-gradient-to-t from-navy-950 via-navy-950/40 to-navy-950/70"
      />

      <div
        v-if="slideAtual"
        class="pointer-events-none absolute inset-x-0 bottom-24 mx-auto max-w-7xl px-6"
      >
        <h2 class="max-w-3xl text-4xl font-bold text-white drop-shadow-lg sm:text-5xl">
          {{ slideAtual.titulo }}
        </h2>
        <p class="mt-3 max-w-2xl text-sm text-slate-200 line-clamp-3 sm:text-base">
          {{ slideAtual.sinopse }}
        </p>
      </div>
    </div>

    <div class="absolute inset-x-0 bottom-10 flex items-center justify-center gap-2">
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
