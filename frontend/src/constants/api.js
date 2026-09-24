/**
 * Constantes de acesso à API.
 *
 * O timeout fica aqui para que todas as chamadas compartilhem o mesmo limite,
 * em vez de cada serviço definir o seu.
 */

/** Tempo máximo de espera por uma resposta do backend, em milissegundos. */
export const TIMEOUT_REQUISICAO_MS = 20000

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
