/**
 * Constantes de acesso à API.
 *
 * O timeout fica aqui para que todas as chamadas compartilhem o mesmo limite,
 * em vez de cada serviço definir o seu.
 */

/**
 * Tempo máximo de espera por uma resposta do backend, em milissegundos.
 *
 * A busca de fontes é a chamada mais lenta do sistema: no primeiro pedido de um
 * episódio ela percorre quatro variações de termo por três degraus (nativo,
 * indexador e YTS) e, com o cache frio, passa dos 20 s que antes a cortavam — o
 * navegador abortava e a tela ficava em "cancelado". Este limite cobre a pior
 * execução a frio; do segundo pedido em diante o cache responde na hora.
 */
export const TIMEOUT_REQUISICAO_MS = 60000

/** Prefixo comum dos endpoints de filmes. */
export const BASE_MOVIES = '/v1/movies'

/**
 * Prefixo dos endpoints de sessão de reprodução do media-service.
 *
 * É relativo à base do media-service (`/media`, definida no store de API), e
 * não ao prefixo `/api` do Laravel — misturar os dois fazia a chamada cair no
 * backend errado e responder 404.
 */
export const BASE_SESSAO = '/sessao'
