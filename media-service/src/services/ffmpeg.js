import ffmpeg from 'fluent-ffmpeg'
import path from 'node:path'
import { v4 as uuid } from 'uuid'

const STORAGE = process.env.STORAGE_PATH || '/app/storage'

export function probeMedia(filePath) {
  return new Promise((resolve, reject) => {
    ffmpeg.ffprobe(filePath, (err, metadata) => {
      if (err) return reject(err)
      resolve(metadata)
    })
  })
}

export function transcodeVideo(inputPath, options = {}) {
  const output = path.join(STORAGE, `${uuid()}.mp4`)
  const { codec = 'libx264', crf = 23, preset = 'medium' } = options

  return new Promise((resolve, reject) => {
    ffmpeg(inputPath)
      .videoCodec(codec)
      .audioCodec('aac')
      .outputOptions([`-crf ${crf}`, `-preset ${preset}`, '-movflags +faststart'])
      .on('end', () => resolve(output))
      .on('error', reject)
      .save(output)
  })
}

export function extractAudio(inputPath) {
  const output = path.join(STORAGE, `${uuid()}.mp3`)

  return new Promise((resolve, reject) => {
    ffmpeg(inputPath)
      .noVideo()
      .audioCodec('libmp3lame')
      .on('end', () => resolve(output))
      .on('error', reject)
      .save(output)
  })
}
