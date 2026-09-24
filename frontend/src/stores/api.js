import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Store de configuração de endpoints.
 *
 * Em produção/dev atrás do Nginx (porta 80), o frontend e a API ficam no
 * mesmo host, então usamos caminhos relativos por padrão. Isso evita
 * problemas de CORS e de porta. As variáveis VITE_* permitem sobrescrever
 * quando o frontend roda isolado (ex.: `npm run dev` fora do Docker).
 */

/**
 * Normaliza a base do media-service para o caminho onde o Express realmente
 * monta as rotas (`/api/media`).
 *
 * O media-service pode ser acessado por dois caminhos: pelo proxy do Nginx
 * (prefixo público `/media`, que reescreve para `/api/media`) ou direto na
 * porta do Node (`http://localhost:3000`, sem prefixo algum). Se a variável
 * apontar para a porta crua, as chamadas chegariam em `/sessao` e o Express
 * responderia "Cannot POST /sessao". Aqui garantimos que o prefixo exista nos
 * dois casos — e, principalmente, que ele não seja duplicado.
 */
function normalizarBaseMedia(url) {
  const base = (url || '/media').replace(/\/+$/, '')

  // Já veio com o prefixo interno do Express (ex.: alguém apontou direto para
  // o Node em http://localhost:3000/api/media).
  if (base.endsWith('/api/media')) return base

  // Prefixo público do Nginx, seja relativo (`/media`) ou absoluto
  // (`http://localhost/media`). O proxy cuida da reescrita para `/api/media`,
  // então não podemos acrescentar nada — fazer isso gerava o caminho
  // duplicado `/api/media/api/media/sessao`.
  if (/\/media$/.test(base)) return base

  // Porta crua do Node (ex.: http://localhost:3000): falta o prefixo.
  return `${base}/api/media`
}

export const useApiStore = defineStore('api', () => {
  const baseUrl = ref(import.meta.env.VITE_API_URL || '/api')
  const mediaServiceUrl = ref(normalizarBaseMedia(import.meta.env.VITE_MEDIA_SERVICE_URL))

  return { baseUrl, mediaServiceUrl }
})
