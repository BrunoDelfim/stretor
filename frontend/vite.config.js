import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// O Vite roda atrás do Nginx (porta 80) tanto no PC local quanto no GitHub
// Codespaces. O que muda entre os dois é a porta pública do proxy: no PC é a
// 80 em HTTP; no Codespaces é a 443 em HTTPS, porque o Space publica cada
// porta encaminhada numa URL HTTPS própria. O websocket de recarga (HMR)
// precisa apontar para essa porta, senão o navegador tenta conectar na 80 e a
// recarga automática nunca acontece.
//
// A porta vem de VITE_HMR_CLIENT_PORT, definida no .env pelo post-create do
// Codespace. Sem ela, o padrão é 80, que é o comportamento local.
const portaHmr = Number(process.env.VITE_HMR_CLIENT_PORT || 80)

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
    // O cliente HMR conecta na porta pública do proxy, não na 5173 interna.
    // No Codespaces o proxy é HTTPS, então o protocolo também precisa ser wss.
    hmr: {
      clientPort: portaHmr,
      protocol: portaHmr === 443 ? 'wss' : 'ws',
    },
    watch: {
      usePolling: true,
    },
  },
})
