import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    allowedHosts: true,
    // O Vite roda atrás do Nginx (porta 80). O cliente HMR deve conectar
    // na mesma porta pública do Nginx, não na 5173 interna.
    hmr: {
      clientPort: 80,
    },
    watch: {
      usePolling: true,
    },
  },
})
