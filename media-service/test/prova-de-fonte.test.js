import test from 'node:test'
import assert from 'node:assert/strict'

import { pareceMidia } from '../src/utils/assinatura-midia.js'

/*
 * A prova de bytes é o que sustenta o selo "fonte verificada": ela lê o começo
 * do arquivo e decide se aquilo é mídia. O caso que originou a régua: o
 * `pobreflixtv.democrat` publicava `/manifest.webm`, que respondia `200` com a
 * página HTML da home — a sonda antiga aprovava e o ffprobe recusava na hora de
 * tocar, com o usuário já esperando.
 */

/** Monta um Buffer com os primeiros bytes declarados. */
function bytes(texto) {
  return Buffer.from(texto, 'latin1')
}

test('MP4 é reconhecido pela assinatura ftyp, mesmo servido como binário', () => {
  // `ftyp` mora no offset 4, depois do tamanho da caixa.
  assert.equal(pareceMidia(bytes('\u0000\u0000\u0000\u0018ftypisom\u0000\u0000\u0002\u0000'), 'application/octet-stream'), true)
  assert.equal(pareceMidia(bytes('\u0000\u0000\u0000\u0018ftypisom'), 'text/plain'), true)
})

test('playlist HLS é reconhecida pela tag #EXTM3U', () => {
  assert.equal(pareceMidia(bytes('#EXTM3U\n#EXT-X-VERSION:3\n'), 'text/plain'), true)
  assert.equal(pareceMidia(bytes('\n#EXTM3U\n'), 'text/plain'), true)
})

test('página de erro com 200 e text/html é reprovada', () => {
  const html = bytes('<!DOCTYPE html><html><head><title>Página inicial</title></head></html>')

  assert.equal(pareceMidia(html, 'text/html; charset=utf-8'), false)
})

test('arquivo vazio não é mídia', () => {
  assert.equal(pareceMidia(Buffer.alloc(0), ''), false)
})

test('Content-Type de vídeo resolve mesmo sem assinatura conhecida', () => {
  assert.equal(pareceMidia(Buffer.alloc(8), 'video/mp4'), true)
  assert.equal(pareceMidia(Buffer.alloc(8), 'application/vnd.apple.mpegurl'), true)
})
