import axios from 'axios'
import { useApiStore } from '@/stores/api'

/**
 * Camada isolada de acesso aos endpoints de filmes.
 * As views nunca chamam axios diretamente — sempre passam por aqui.
 */
function cliente() {
  const api = useApiStore()

  return axios.create({
    baseURL: `${api.baseUrl}/v1/movies`,
    timeout: 20000,
  })
}

export const moviesService = {
  /** Filmes mais assistidos do Brasil. */
  async populares(page = 1) {
    const { data } = await cliente().get('/popular', { params: { page } })

    return data.data ?? []
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
