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
   *
   * Os metadados do filme vão junto como query string de propósito: o backend
   * tenta o catálogo primeiro, mas quando o TMDB está fora do ar ou sem chave
   * ele reaproveita o que veio daqui e a busca de fontes continua funcionando.
   *
   * Devolve também o `mensagem` da resposta: é o aviso que o overlay mostra
   * quando a lista volta vazia, e sem ele o usuário não teria como distinguir
   * "não existe release para este filme" de "falta configurar o sistema".
   */
  async buscarFontes(filme) {
    const { data } = await clienteApi().get(`/${filme.id}/fontes`, {
      params: {
        titulo: filme.titulo,
        titulo_original: filme.titulo_original,
        ano: filme.ano,
        imdb_id: filme.imdb_id,
      },
    })

    return {
      fontes: data.data?.fontes ?? [],
      mensagem: data.data?.mensagem ?? null,
    }
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

  /**
   * Estado atual da sessão (conectando, convertendo, pronto ou erro).
   *
   * O 404 é traduzido para um estado próprio em vez de subir como exceção. Ele
   * significa que a sessão não existe mais no media-service — o processo
   * reiniciou (o mapa de sessões é em memória) ou a sessão foi encerrada por
   * outro caminho. Sem essa distinção o chamador tratava o 404 como falha
   * pontual de rede e continuava consultando um id morto para sempre, deixando
   * o overlay preso no spinner.
   */
  async statusSessao(sessaoId) {
    try {
      const { data } = await clienteMedia().get(`${BASE_SESSAO}/${sessaoId}/status`)

      return data
    } catch (falha) {
      if (falha?.response?.status === 404) {
        return { status: 'inexistente' }
      }

      throw falha
    }
  },

  /**
   * Pede ao media-service que reposicione a conversão para um tempo alvo.
   *
   * Só faz sentido quando o alvo cai além do trecho já convertido: o serviço
   * descarta os segmentos antigos e recomeça a conversão no ponto pedido. A
   * resposta traz o `tempo_base`, que indica onde o novo zero da playlist está no
   * filme — o player usa isso para acertar a duração exibida.
   */
  async solicitarSeek(sessaoId, tempo) {
    const { data } = await clienteMedia().post(`${BASE_SESSAO}/${sessaoId}/seek`, {
      tempo,
    })

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
