import express from 'express'
import cors from 'cors'
import morgan from 'morgan'
import dotenv from 'dotenv'

import mediaRoutes from './routes/media.js'
import { limparSessoesAntigas } from './services/sessoes.js'
import { logger } from './utils/logger.js'

dotenv.config()

/*
 * Rede de segurança para falhas assíncronas de bibliotecas de terceiros.
 *
 * O WebTorrent já derrubou o serviço inteiro ao processar um magnet inválido
 * (exceção lançada fora de qualquer try/catch nosso). Como o serviço atende
 * todas as sessões, uma fonte problemática não pode levar as outras junto —
 * registramos o erro e seguimos vivos.
 */
process.on('uncaughtException', (erro) => {
  logger.error('[uncaughtException]', erro?.stack || erro?.message || erro)
})

process.on('unhandledRejection', (motivo) => {
  logger.error('[unhandledRejection]', motivo?.stack || motivo?.message || motivo)
})

const app = express()
const PORT = process.env.PORT || 3000

// Sessões de reprodução são efêmeras: se o player fechar sem avisar (aba
// fechada, rede caiu), o torrent e o FFmpeg ficariam vivos para sempre. A
// varredura periódica devolve esses recursos ao sistema.
const INTERVALO_LIMPEZA_MS = 15 * 60 * 1000
setInterval(() => limparSessoesAntigas(), INTERVALO_LIMPEZA_MS).unref()

app.use(cors())
app.use(express.json())
app.use(morgan('dev'))

app.get('/health', (_req, res) => {
  res.json({
    status: 'ok',
    service: 'stretor-media-service',
    timestamp: new Date().toISOString(),
  })
})

app.use('/api/media', mediaRoutes)

app.use((err, _req, res, _next) => {
  logger.error(err)
  res.status(err.status || 500).json({ error: err.message || 'Internal error' })
})

app.listen(PORT, '0.0.0.0', () => {
  logger.info(`Media service ouvindo na porta ${PORT}`)
})
