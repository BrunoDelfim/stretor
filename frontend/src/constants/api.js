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
