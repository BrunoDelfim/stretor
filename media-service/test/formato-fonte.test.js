import test from 'node:test'
import assert from 'node:assert/strict'

import { extensaoPelaAmostra, extensaoPeloTipo } from '../src/utils/formato-fonte.js'

/*
 * O caso que originou estes testes: o resolvido do StreamTape entrega o arquivo
 * por `https://streamtape.com/get_video?id=...&token=...`, e o redirecionamento
 * final leva a um caminho de CDN com o nome do filme — nenhum dos dois termina
 * em `.mp4`. O media-service recusava a fonte com `formato_desconhecido` antes
 * de o FFmpeg tentar abrir um arquivo de 1,6 GB que ele lê sem dificuldade.
 *
 * A extensão passa a ser descoberta pelo `Content-Type` (e, quando ele falta,
 * pela assinatura dos primeiros bytes). O que estes testes travam é a tradução
 * — a parte que não depende de rede.
 */

test('traduz os tipos de vídeo progressivo conhecidos', () => {
  assert.equal(extensaoPeloTipo('video/mp4'), '.mp4')
  assert.equal(extensaoPeloTipo('VIDEO/MP4'), '.mp4')
  assert.equal(extensaoPeloTipo('video/webm'), '.webm')
  assert.equal(extensaoPeloTipo('video/quicktime'), '.mov')
  assert.equal(extensaoPeloTipo('video/x-matroska'), '.mkv')
})

test('traduz as playlists HLS, inclusive com parâmetros', () => {
  assert.equal(extensaoPeloTipo('application/vnd.apple.mpegurl'), '.m3u8')
  assert.equal(extensaoPeloTipo('application/x-mpegURL; charset=utf-8'), '.m3u8')
  assert.equal(extensaoPeloTipo('audio/mpegurl'), '.m3u8')
})

test('recusa o que não é arquivo de vídeo', () => {
  // Página de erro, JSON de API e tipo ausente não podem virar sessão.
  assert.equal(extensaoPeloTipo('text/html; charset=UTF-8'), '')
  assert.equal(extensaoPeloTipo('application/json'), '')
  assert.equal(extensaoPeloTipo('application/octet-stream'), '')
  assert.equal(extensaoPeloTipo(null), '')
  assert.equal(extensaoPeloTipo(undefined), '')
})

test('reconhece o MP4 pela assinatura quando o tipo não ajuda', () => {
  // Um MP4 de verdade começa com o tamanho da caixa e `ftyp` no offset 4.
  const mp4 = Buffer.concat([Buffer.from([0x00, 0x00, 0x00, 0x18]), Buffer.from('ftypisom')])

  assert.equal(extensaoPelaAmostra(mp4), '.mp4')
})

test('reconhece o WebM pela assinatura', () => {
  const webm = Buffer.from([0x1a, 0x45, 0xdf, 0xa3, 0x01, 0x02, 0x03, 0x04])

  assert.equal(extensaoPelaAmostra(webm), '.webm')
})

test('reconhece o HLS pelo cabeçalho da playlist', () => {
  assert.equal(extensaoPelaAmostra(Buffer.from('#EXTM3U\n#EXT-X-VERSION:3\n')), '.m3u8')
})

test('não inventa formato para uma página de erro', () => {
  assert.equal(extensaoPelaAmostra(Buffer.from('{"status":500,"msg":"erro"}')), '')
  assert.equal(extensaoPelaAmostra(Buffer.alloc(0)), '')
})
