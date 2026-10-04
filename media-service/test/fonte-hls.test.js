import test from 'node:test'
import assert from 'node:assert/strict'

import { opcoesEntradaHlsRemota } from '../src/services/hls.js'

/*
 * O problema que originou estes testes: as fontes de embed (FirePlayer e
 * companhia) anunciam o mesmo MPEG-TS com extensões falsas que giram a cada
 * segmento — `.html`, `.js`, `.css`, `.woff` — espalhadas por dezenas de
 * subdomínios espelhados. O conteúdo é vídeo legítimo, mas o demuxer HLS do
 * FFmpeg recusa o playlist inteiro no primeiro segmento fora da lista de
 * extensões permitidas, e a sessão morria com
 * "Invalid data found when processing input".
 */

test('libera as extensões falsas num playlist HLS remoto', () => {
  const opcoes = opcoesEntradaHlsRemota('https://vaiquecol.com/cdn/hls/abc/master.m3u8?md5=x&expires=1')

  assert.equal(opcoes.length, 4)
  assert.equal(opcoes[0], '-extension_picky')
  assert.equal(opcoes[1], '0')
  assert.equal(opcoes[2], '-allowed_segment_extensions')
})

test('a lista de extensões cobre as falsas dos embeds e as legítimas', () => {
  const lista = opcoesEntradaHlsRemota('https://exemplo.tld/master.m3u8')[3].split(',')

  for (const falsa of ['html', 'js', 'css', 'woff']) {
    assert.ok(lista.includes(falsa), `esperava a extensão falsa ${falsa}`)
  }

  // As do padrão do FFmpeg, que a opção substitui quando é declarada.
  for (const legitima of ['ts', 'm4s', 'aac', 'vtt', 'fmp4']) {
    assert.ok(lista.includes(legitima), `esperava a extensão legítima ${legitima}`)
  }
})

test('reconhece o HLS pela extensão declarada, mesmo sem sufixo na URL', () => {
  const opcoes = opcoesEntradaHlsRemota('https://exemplo.tld/playlist?token=abc', '.m3u8')

  assert.equal(opcoes.length, 4)
})

test('não mexe num MP4 remoto', () => {
  assert.deepEqual(opcoesEntradaHlsRemota('https://exemplo.tld/filme.mp4?token=abc'), [])
})

test('não mexe em arquivo local de torrent', () => {
  assert.deepEqual(opcoesEntradaHlsRemota('/tmp/stretor-abc/filme.mkv', '.mkv'), [])
})

/*
 * As opções são do demuxer HLS: num MP4 o FFmpeg aborta a abertura com
 * "Option not found" em vez de ignorá-las, então passá-las por engano quebraria
 * o caminho do `videoSources` (`hls:false`), que é justamente um MP4.
 */
test('não trata um caminho relativo como playlist remota', () => {
  assert.deepEqual(opcoesEntradaHlsRemota('master.m3u8', '.m3u8'), [])
})

test('aceita a playlist mesmo com a extensão em caixa alta', () => {
  assert.equal(opcoesEntradaHlsRemota('https://exemplo.tld/MASTER.M3U8').length, 4)
})
