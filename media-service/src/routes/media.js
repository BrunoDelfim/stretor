import { Router } from 'express'
import multer from 'multer'
import path from 'node:path'
import { v4 as uuid } from 'uuid'

import { probeMedia, transcodeVideo, extractAudio } from '../services/ffmpeg.js'
import { logger } from '../utils/logger.js'

const router = Router()

const storage = multer.diskStorage({
  destination: (_req, _file, cb) => cb(null, process.env.STORAGE_PATH || '/app/storage'),
  filename: (_req, file, cb) => cb(null, `${uuid()}${path.extname(file.originalname)}`),
})

const upload = multer({ storage })

router.post('/probe', upload.single('file'), async (req, res, next) => {
  try {
    const metadata = await probeMedia(req.file.path)
    res.json({ file: req.file.filename, metadata })
  } catch (err) {
    next(err)
  }
})

router.post('/transcode', upload.single('file'), async (req, res, next) => {
  try {
    const output = await transcodeVideo(req.file.path, req.body)
    res.json({ output })
  } catch (err) {
    next(err)
  }
})

router.post('/extract-audio', upload.single('file'), async (req, res, next) => {
  try {
    const output = await extractAudio(req.file.path)
    res.json({ output })
  } catch (err) {
    next(err)
  }
})

export default router
