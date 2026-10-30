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

  /**
   * Tendências do dia misturando filmes, animação e séries — alimenta a Home
   * unificada.
   *
   * O backend já descarta lançamentos futuros, então o que chega aqui está
   * disponível para assistir hoje. O envelope é o mesmo de `populares()`.
   */
  async trending(page = 1) {
    const { data } = await cliente().get('/trending', { params: { page } })

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

  /**
   * Detalhes completos de uma série (modal de série).
   *
   * O backend devolve a mesma ficha do filme acrescida da lista de temporadas,
   * que é o que alimenta o seletor do modal.
   */
  async detalhesSerie(id) {
    const { data } = await cliente().get(`/${id}/serie`)

    return data.data ?? null
  },

  /**
   * Episódios de uma temporada específica.
   *
   * Cada episódio já vem normalizado (capa, sinopse, título, nota e duração) e
   * com o próprio selo (`verificado`). O `pendentes` diz quantos ainda não têm
   * prova — é o que o modal usa para continuar a verificação em segundo plano.
   */
  async temporada(id, numero) {
    const { data } = await cliente().get(`/${id}/temporada/${numero}`)

    return {
      ...(data.data ?? {}),
      pendentes: data.meta?.pendentes ?? 0,
    }
  },

  /**
   * Pede a verificação da temporada e devolve os selos que já existem.
   *
   * O caminho normal não espera a conta: o backend enfileira o trabalho
   * (`VerificarTemporada`) e responde com `na_fila: true`. O modal acompanha o
   * progresso pelo `temporada()`, que já devolve o selo de cada linha.
   *
   * `sincrono` é a rede de segurança para quando o worker da fila não está no
   * ar: aí o backend verifica uma fatia na hora e devolve o resultado, em vez de
   * deixar o selo parado.
   */
  async verificarTemporada(id, numero, { sincrono = false } = {}) {
    const { data } = await cliente().post(
      `/${id}/temporada/${numero}/verificar${sincrono ? '?sincrono=1' : ''}`
    )

    return {
      episodios: data.data?.episodios ?? [],
      testados: data.data?.testados ?? [],
      naFila: data.data?.na_fila ?? false,
      pendentes: data.meta?.pendentes ?? 0,
    }
  },
}
