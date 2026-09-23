<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const emit = defineEmits(['buscar'])

const termo = ref('')
const scrollY = ref(0)
const menuAberto = ref(false)
const containerPerfil = ref(null)

// Opções do menu de perfil — placeholders até a autenticação existir.
const opcoesPerfil = [
  { rotulo: 'Meu perfil', acao: 'perfil' },
  { rotulo: 'Minha lista', acao: 'lista' },
  { rotulo: 'Configurações', acao: 'configuracoes' },
  { rotulo: 'Sair', acao: 'sair' },
]

// A navbar nasce totalmente transparente e vai ganhando cor aos poucos
// conforme a página rola, até ficar sólida. O intervalo define em quantos
// pixels de scroll a transição se completa.
const INICIO_SOLIDIFICACAO = 20
const FIM_SOLIDIFICACAO = 220

const opacidadeFundo = computed(() => {
  const progresso =
    (scrollY.value - INICIO_SOLIDIFICACAO) / (FIM_SOLIDIFICACAO - INICIO_SOLIDIFICACAO)

  return Math.min(1, Math.max(0, progresso))
})

const solidificada = computed(() => opacidadeFundo.value >= 1)

function aoRolar() {
  scrollY.value = window.scrollY
}

let debounce = null

function aoDigitar() {
  clearTimeout(debounce)
  debounce = setTimeout(() => emit('buscar', termo.value), 400)
}

function limpar() {
  termo.value = ''
  emit('buscar', '')
}

function alternarMenu() {
  menuAberto.value = !menuAberto.value
}

function selecionarOpcao(opcao) {
  // Sem autenticação ainda: apenas fecha o menu e emite a intenção.
  menuAberto.value = false
  emit('perfil', opcao.acao)
}

// Fecha o menu ao clicar fora dele.
function aoClicarFora(evento) {
  if (containerPerfil.value && !containerPerfil.value.contains(evento.target)) {
    menuAberto.value = false
  }
}

onMounted(() => {
  aoRolar()
  window.addEventListener('scroll', aoRolar, { passive: true })
  document.addEventListener('click', aoClicarFora)
})

onUnmounted(() => {
  window.removeEventListener('scroll', aoRolar)
  document.removeEventListener('click', aoClicarFora)
  clearTimeout(debounce)
})
</script>

<template>
  <header
    class="fixed inset-x-0 top-0 z-40 transition-shadow duration-300"
    :class="solidificada ? 'shadow-lg shadow-black/40' : ''"
  >
    <!--
      Camada de fundo separada: a opacidade é interpolada pelo scroll, então a
      cor "entra" suavemente em vez de trocar de uma vez. O desfoque acompanha
      a mesma progressão.
    -->
    <div
      class="absolute inset-0"
      :style="{
        backgroundColor: 'rgb(6 17 37)',
        opacity: opacidadeFundo,
        backdropFilter: `blur(${opacidadeFundo * 8}px)`,
      }"
    />

    <div class="relative mx-auto flex max-w-7xl items-center justify-between gap-6 px-6 py-4">
      <RouterLink to="/" class="text-2xl font-black tracking-[0.2em] text-white">
        STRETOR
      </RouterLink>

      <!-- Busca e perfil ficam agrupados à direita da navbar. -->
      <div class="flex items-center gap-3">
      <!--
        A caixa de busca tem fundo escuro próprio e sombra para se manter
        legível tanto sobre áreas claras quanto escuras do carrossel, já que a
        navbar fica transparente no topo.
      -->
      <div
        class="relative w-56 rounded-full bg-navy-950/80 shadow-lg shadow-black/40 ring-1 ring-white/20 backdrop-blur-md transition focus-within:ring-white/50 sm:w-64"
      >
        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-300">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" stroke-linecap="round" />
          </svg>
        </span>

        <input
          v-model="termo"
          type="search"
          placeholder="Buscar filmes..."
          class="w-full rounded-full bg-transparent py-2 pl-9 pr-9 text-sm text-white placeholder:text-slate-300 focus:outline-none"
          @input="aoDigitar"
        />

        <button
          v-if="termo"
          type="button"
          class="absolute inset-y-0 right-3 flex items-center text-slate-300 hover:text-white"
          aria-label="Limpar busca"
          @click="limpar"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M6 6l12 12M18 6L6 18" stroke-linecap="round" />
          </svg>
        </button>
      </div>

      <!--
        Avatar de perfil: por enquanto é um placeholder. No futuro receberá a
        foto do usuário autenticado. O menu suspenso traz apenas opções de
        exemplo até a autenticação ser implementada.
      -->
      <div ref="containerPerfil" class="relative shrink-0">
        <button
          type="button"
          class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-full bg-navy-950/80 text-slate-300 shadow-lg shadow-black/40 ring-1 ring-white/20 backdrop-blur-md transition hover:ring-white/50"
          aria-label="Menu do perfil"
          :aria-expanded="menuAberto"
          @click.stop="alternarMenu"
        >
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <circle cx="12" cy="8" r="4" />
            <path d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6" stroke-linecap="round" />
          </svg>
        </button>

        <Transition name="menu">
          <div
            v-if="menuAberto"
            class="absolute right-0 mt-2 w-48 overflow-hidden rounded-lg border border-white/10 bg-navy-950/95 py-1 shadow-2xl shadow-black/60 backdrop-blur-md"
          >
            <button
              v-for="opcao in opcoesPerfil"
              :key="opcao.acao"
              type="button"
              class="block w-full px-4 py-2 text-left text-sm text-slate-200 transition hover:bg-white/10 hover:text-white"
              @click="selecionarOpcao(opcao)"
            >
              {{ opcao.rotulo }}
            </button>
          </div>
        </Transition>
      </div>
      </div>
    </div>
  </header>
</template>

<style scoped>
.menu-enter-active,
.menu-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.menu-enter-from,
.menu-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
</style>
