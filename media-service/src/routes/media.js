import { Router } from 'express'
import multer from 'multer'
import path from 'node:path'
import fs from 'node:fs'
import { v4 as uuid } from 'uuid'

import { probeMedia, transcodeVideo, extractAudio } from '../services/ffmpeg.js'
import {
  criarSessao,
  obterSessao,
  encerrarSessao,
  caminhoPlaylist,
  diretorioSessao,
  verificarFonte,
  reposicionarSessao,
} from '../services/sessoes.js'
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

/**
 * Cria uma sessão de reprodução a partir de um magnet.
 *
 * Responde na hora com o id: a conexão do torrent e a conversão seguem em
 * segundo plano, e o frontend acompanha pelo endpoint de status.
 */
router.post('/sessao', (req, res, next) => {
  try {
    const { magnet, filme_id: filmeId } = req.body ?? {}

    if (!magnet) {
      return res.status(400).json({ error: 'O campo "magnet" é obrigatório.' })
    }

    const sessao = criarSessao({ magnet, filmeId })

    res.status(202).json(sessao)
  } catch (err) {
    next(err)
  }
})

/**
 * Testa uma fonte antes de oferecê-la ao usuário.
 *
 * O backend chama este endpoint para descartar fontes sem peers. O `seeds` do
 * provedor é estático e pode estar desatualizado; aqui medimos a malha real por
 * alguns segundos. Não cria sessão nem deixa torrent vivo.
 */
router.post('/verificar', async (req, res, next) => {
  try {
    const { magnet } = req.body ?? {}

    if (!magnet) {
      return res.status(400).json({ error: 'O campo "magnet" é obrigatório.' })
    }

    const resultado = await verificarFonte(magnet)

    res.json(resultado)
  } catch (err) {
    next(err)
  }
})

/**
 * Estado atual da sessão — alimenta as mensagens de progresso do overlay.
 *
 * O `no-store` é obrigatório aqui. O Express habilita ETag por padrão, e como
 * o status fica idêntico entre dois polls seguidos (mesmo `status`, mesma
 * `mensagem`), o navegador passava a responder `304 Not Modified` com corpo
 * vazio. O axios então entregava `data` vazio, `status.status` virava
 * `undefined` e o frontend nunca enxergava o `pronto` — o overlay ficava
 * preso no polling para sempre.
 */
router.get('/sessao/:id/status', (req, res) => {
  const sessao = obterSessao(req.params.id)

  if (!sessao) {
    return res.status(404).json({ error: 'Sessão não encontrada.' })
  }

  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
  res.setHeader('Pragma', 'no-cache')
  res.setHeader('Expires', '0')

  res.json(sessao)
})

/**
 * Playlist HLS consumida pelo player.
 *
 * A playlist é reescrita pelo FFmpeg a cada segmento novo, então nunca deve ser
 * cacheada — o player precisa enxergar os segmentos assim que aparecem.
 */
router.get('/sessao/:id/playlist.m3u8', (req, res) => {
  const playlist = caminhoPlaylist(req.params.id)

  if (!playlist || !fs.existsSync(playlist)) {
    return res.status(404).json({ error: 'Playlist ainda não disponível.' })
  }

  res.setHeader('Content-Type', 'application/vnd.apple.mpegurl')
  res.setHeader('Cache-Control', 'no-cache, no-store, must-revalidate')

  fs.createReadStream(playlist).pipe(res)
})

/**
 * Segmentos de vídeo da playlist.
 *
 * O FFmpeg escreve os segmentos como `segmento-<carimbo>-N.ts` e a playlist os
 * referencia de forma relativa. O player resolve esse nome sobre a URL da
 * playlist, o que resulta em `/sessao/<id>/segmento-<carimbo>-N.ts` — por isso a
 * rota recebe o arquivo direto, sem o nível intermediário `/segmento/`. O carimbo
 * é único por execução de conversão, o que permite cachear o segmento para sempre
 * sem risco de servir conteúdo de um reposicionamento anterior.
 */
router.get('/sessao/:id/:arquivo', (req, res) => {
  const diretorio = diretorioSessao(req.params.id)

  if (!diretorio) {
    return res.status(404).json({ error: 'Sessão não encontrada.' })
  }

  // Só aceitamos o nome do arquivo, nunca um caminho: sem isso, um `../` no
  // parâmetro permitiria ler qualquer arquivo do container.
  const arquivo = path.basename(req.params.arquivo)
  const caminho = path.join(diretorio, arquivo)

  if (!caminho.startsWith(diretorio) || !fs.existsSync(caminho)) {
    return res.status(404).json({ error: 'Segmento não encontrado.' })
  }

  res.setHeader('Content-Type', 'video/mp2t')
  // `immutable` é seguro porque o nome do arquivo muda a cada execução de
  // conversão: um segmento nunca é reescrito com conteúdo diferente no mesmo
  // caminho, então o navegador pode reaproveitá-lo em seeks dentro do trecho.
  res.setHeader('Cache-Control', 'public, max-age=31536000, immutable')

  fs.createReadStream(caminho).pipe(res)
})

/**
 * Reposiciona a sessão para um tempo alvo.
 *
 * O player só chega aqui quando o alvo está além do trecho já convertido — um
 * salto para o meio de um filme longo exigiria esperar horas de conversão
 * sequencial. O media-service então descarta os segmentos antigos, reposiciona a
 * janela de leitura do torrent e reinicia a conversão a partir do ponto pedido.
 *
 * A resposta é assíncrona (202): a playlist só volta a existir depois que os
 * primeiros segmentos do novo trecho ficam prontos, e o frontend acompanha isso
 * pelo endpoint de status. A timeline resultante é local — a playlist nova começa
 * em zero a partir do alvo — por isso devolvemos o `tempo_base` para o player
 * ajustar a duração exibida.
 */
router.post('/sessao/:id/seek', (req, res, next) => {
  try {
    const tempo = Number(req.body?.tempo)

    const resultado = reposicionarSessao(req.params.id, tempo)

    if (!resultado) {
      return res.status(404).json({ error: 'Sessão não encontrada.' })
    }

    if (resultado.erro) {
      return res.status(400).json({ error: resultado.erro, ...resultado })
    }

    res.status(202).json(resultado)
  } catch (err) {
    next(err)
  }
})

/** Encerra a sessão e libera o torrent e o processo de conversão. */
router.delete('/sessao/:id', (req, res) => {
  const encerrada = encerrarSessao(req.params.id)

  if (!encerrada) {
    return res.status(404).json({ error: 'Sessão não encontrada.' })
  }

  logger.info(`[sessao ${req.params.id}] encerrada pelo cliente`)

  res.status(204).end()
})

export default router
