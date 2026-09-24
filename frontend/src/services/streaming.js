import axios from 'axios'
import { useApiStore } from '@/stores/api'
import { BASE_MOVIES, BASE_SESSAO, TIMEOUT_REQUISICAO_MS } from '@/constants/api'

/**
 * Camada isolada de acesso ao fluxo de reprodução.
 *
 * São dois backends diferentes: a busca de fontes fala com a API Laravel, e a
 * sessão de streaming fala com o media-service. As views nunca chamam axios
 * diretamente — sempre passam por aqui.
 */
function clienteApi() {
  const api = useApiStore()

  return axios.create({
    baseURL: `${api.baseUrl}${BASE_MOVIES}`,
    timeout: TIMEOUT_REQUISICAO_MS,
  })
}

function clienteMedia() {
  const api = useApiStore()

  return axios.create({
    baseURL: api.mediaServiceUrl,
    timeout: TIMEOUT_REQUISICAO_MS,
  })
}

export const streamingService = {
  /**
   * Fontes de torrent disponíveis para o filme, já ordenadas por prioridade
   * (dublado em PT-BR primeiro).
   */
  async buscarFontes(filmeId) {
    const { data } = await clienteApi().get(`/${filmeId}/fontes`)

    return data.data?.fontes ?? []
  },

  /**
   * Cria uma sessão de reprodução para a fonte escolhida.
   *
   * O media-service responde na hora com o id; a conexão e a conversão seguem
   * em segundo plano e são acompanhadas por `statusSessao`.
   */
  async criarSessao(magnet, filmeId) {
    const { data } = await clienteMedia().post(`${BASE_SESSAO}`, {
      magnet,
      filme_id: filmeId,
    })

    return data
  },

  /** Estado atual da sessão (conectando, convertendo, pronto ou erro). */
  async statusSessao(sessaoId) {
    const { data } = await clienteMedia().get(`${BASE_SESSAO}/${sessaoId}/status`)

    return data
  },

  /** Encerra a sessão e libera o torrent e a conversão no servidor. */
  async encerrarSessao(sessaoId) {
    await clienteMedia().delete(`${BASE_SESSAO}/${sessaoId}`)
  },

  /**
   * Monta a URL absoluta da playlist HLS.
   *
   * O status devolve o caminho no formato interno do Express
   * (`/api/media/sessao/<id>/playlist.m3u8`), mas a base do media-service já
   * aponta para o prefixo público (`/media` ou `http://localhost/media`).
   * Precisamos remover o prefixo interno para não duplicá-lo na URL final.
   *
   * A URL é validada antes de sair daqui: se o prefixo público não estiver
   * presente, o hls.js resolveria os caminhos relativos contra a origem do
   * frontend e o Nginx entregaria o `index.html` — o navegador passava a baixar
   * imagens em vez dos segmentos. Melhor falhar cedo e deixar o fluxo tentar a
   * próxima fonte.
   */
  urlPlaylist(caminho) {
    if (!caminho) return null

    const api = useApiStore()
    const url = `${api.mediaServiceUrl}${caminho.replace(/^\/api\/media/, '')}`

    if (!/\/media\/sessao\/[^/]+\/playlist\.m3u8$/.test(url)) {
      console.error('[streaming] URL de playlist inválida:', url)
      return null
    }

    return url
  },
}
