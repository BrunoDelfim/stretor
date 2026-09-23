import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useApiStore = defineStore('api', () => {
  const baseUrl = ref(import.meta.env.VITE_API_URL || 'http://localhost:8000/api')
  const mediaServiceUrl = ref(
    import.meta.env.VITE_MEDIA_SERVICE_URL || 'http://localhost:3000',
  )

  return { baseUrl, mediaServiceUrl }
})
