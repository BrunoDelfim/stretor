import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { moviesService } from '@/services/movies'

/**
 * Estado central dos filmes exibidos na Home.
 *
 * `carregando` controla a tela de loading que bloqueia a página até a primeira
 * resposta chegar. A busca reaproveita a mesma lista, então a navbar e o grid
 * permanecem sincronizados sem duplicar requisições.
 */
export const useMoviesStore = defineStore('movies', () => {
  const filmes = ref([])
  const carregando = ref(false)
  const erro = ref(null)
  const termoBusca = ref('')

  const emBusca = computed(() => termoBusca.value.trim().length > 0)

  /** Destaques do carrossel: os 6 primeiros títulos populares. */
  const destaques = computed(() => filmes.value.slice(0, 6))

  async function carregarPopulares() {
    carregando.value = true
    erro.value = null

    try {
      filmes.value = await moviesService.populares()
      termoBusca.value = ''
    } catch (excecao) {
      erro.value = extrairMensagem(excecao)
      filmes.value = []
    } finally {
      carregando.value = false
    }
  }

  async function buscar(termo) {
    termoBusca.value = termo

    if (!emBusca.value) {
      return carregarPopulares()
    }

    carregando.value = true
    erro.value = null

    try {
      filmes.value = await moviesService.buscar(termo)
    } catch (excecao) {
      erro.value = extrairMensagem(excecao)
      filmes.value = []
    } finally {
      carregando.value = false
    }
  }

  function limparBusca() {
    termoBusca.value = ''
    return carregarPopulares()
  }

  function extrairMensagem(excecao) {
    return (
      excecao?.response?.data?.message ||
      'Não foi possível carregar os filmes agora. Tente novamente em instantes.'
    )
  }

  return {
    filmes,
    carregando,
    erro,
    termoBusca,
    emBusca,
    destaques,
    carregarPopulares,
    buscar,
    limparBusca,
  }
})
