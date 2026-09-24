import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { moviesService } from '@/services/movies'
import { LIMITE_DESTAQUES } from '@/constants/filmes'

/**
 * Estado central dos filmes exibidos na Home.
 *
 * `carregando` controla a tela de loading que bloqueia a página até a primeira
 * resposta chegar. A busca reaproveita a mesma lista, então a navbar e o grid
 * permanecem sincronizados sem duplicar requisições.
 *
 * A paginação alimenta a rolagem infinita: `paginaAtual` guarda a última página
 * carregada e `temMais` indica se ainda há páginas seguintes no TMDB. As guardas
 * de `carregarMais()` evitam requisições duplicadas quando o sentinela dispara
 * várias vezes durante a rolagem.
 */
export const useMoviesStore = defineStore('movies', () => {
  const filmes = ref([])
  const carregando = ref(false)
  const carregandoMais = ref(false)
  const erro = ref(null)
  const termoBusca = ref('')

  const paginaAtual = ref(1)
  const totalPaginas = ref(1)
  const temMais = ref(false)

  const emBusca = computed(() => termoBusca.value.trim().length > 0)

  /** Destaques do carrossel: os primeiros títulos populares. */
  const destaques = computed(() => filmes.value.slice(0, LIMITE_DESTAQUES))

  /** Aplica os metadados de paginação devolvidos pelo backend. */
  function aplicarMeta(meta) {
    paginaAtual.value = meta?.page ?? 1
    totalPaginas.value = meta?.total_pages ?? 1
    temMais.value = Boolean(meta?.has_more)
  }

  /** Zera o estado de paginação — usado ao trocar de contexto (busca/limpeza). */
  function resetarPaginacao() {
    paginaAtual.value = 1
    totalPaginas.value = 1
    temMais.value = false
  }

  async function carregarPopulares() {
    carregando.value = true
    erro.value = null

    try {
      const { filmes: lista, meta } = await moviesService.populares(1)

      filmes.value = lista
      termoBusca.value = ''
      aplicarMeta(meta)
    } catch (excecao) {
      erro.value = extrairMensagem(excecao)
      filmes.value = []
      resetarPaginacao()
    } finally {
      carregando.value = false
    }
  }

  /**
   * Busca a próxima página de populares e anexa ao fim da lista.
   *
   * As guardas cobrem os três cenários que causariam requisições inúteis:
   * já existe uma carga em andamento, a lista acabou, ou o usuário está em
   * modo de busca (onde a rolagem infinita não se aplica).
   */
  async function carregarMais() {
    if (carregando.value || carregandoMais.value || !temMais.value || emBusca.value) {
      return
    }

    carregandoMais.value = true
    erro.value = null

    try {
      const { filmes: lista, meta } = await moviesService.populares(paginaAtual.value + 1)

      filmes.value = [...filmes.value, ...lista]
      aplicarMeta(meta)
    } catch (excecao) {
      // Falha ao paginar não descarta o que já está na tela; apenas interrompe
      // a rolagem infinita para não insistir em uma API indisponível.
      erro.value = extrairMensagem(excecao)
      temMais.value = false
    } finally {
      carregandoMais.value = false
    }
  }

  async function buscar(termo) {
    termoBusca.value = termo

    if (!emBusca.value) {
      return carregarPopulares()
    }

    carregando.value = true
    erro.value = null
    resetarPaginacao()

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
    carregandoMais,
    erro,
    termoBusca,
    paginaAtual,
    totalPaginas,
    temMais,
    emBusca,
    destaques,
    carregarPopulares,
    carregarMais,
    buscar,
    limparBusca,
  }
})
