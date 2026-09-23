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
export const useApiStore = defineStore('api', () => {
  const baseUrl = ref(import.meta.env.VITE_API_URL || '/api')
  const mediaServiceUrl = ref(import.meta.env.VITE_MEDIA_SERVICE_URL || '/media')

  return { baseUrl, mediaServiceUrl }
})
