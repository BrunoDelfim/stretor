import axios from 'axios'
import { useApiStore } from '@/stores/api'
import { BASE_MOVIES, TIMEOUT_REQUISICAO_MS } from '@/constants/api'

/**
 * Camada isolada de acesso aos endpoints de filmes.
 * As views nunca chamam axios diretamente — sempre passam por aqui.
 */
function cliente() {
  const api = useApiStore()

  return axios.create({
    baseURL: `${api.baseUrl}${BASE_MOVIES}`,
    timeout: TIMEOUT_REQUISICAO_MS,
  })
}

export const moviesService = {
  /**
   * Filmes mais assistidos do Brasil.
   *
   * Devolve o envelope completo (`data` + `meta`) porque a rolagem infinita
   * precisa saber se ainda existem páginas seguintes antes de pedir a próxima.
   */
  async populares(page = 1) {
    const { data } = await cliente().get('/popular', { params: { page } })

    return {
      filmes: data.data ?? [],
      meta: data.meta ?? { page, total_pages: page, has_more: false },
    }
  },

  /** Busca por título. */
  async buscar(query, page = 1) {
    const { data } = await cliente().get('/search', { params: { query, page } })

    return data.data ?? []
  },

  /** Detalhes completos de um filme (modal). */
  async detalhes(id) {
    const { data } = await cliente().get(`/${id}`)

    return data.data ?? null
  },
}
