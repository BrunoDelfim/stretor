import express from 'express'
import cors from 'cors'
import morgan from 'morgan'
import dotenv from 'dotenv'

import mediaRoutes from './routes/media.js'
import { logger } from './utils/logger.js'

dotenv.config()

const app = express()
const PORT = process.env.PORT || 3000

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
