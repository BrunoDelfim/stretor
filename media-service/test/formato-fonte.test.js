import test from 'node:test'
import assert from 'node:assert/strict'

import { extensaoPeloFormato } from '../src/utils/formato-fonte.js'

/*
 * O caso que originou estes testes: o resolvedor do StreamTape entrega o arquivo
 * por `https://streamtape.com/get_video?id=...&token=...`, e o redirecionamento
 * final leva a um caminho de CDN com o nome do filme — nenhum dos dois termina
 * em `.mp4`. O media-service recusava a fonte com `formato_desconhecido` antes
 * de o FFmpeg tentar abrir um arquivo de 1,6 GB que ele lê sem dificuldade.
 *
 * A extensão passou a vir do **ffprobe** (`format.format_name`), que é a mesma
 * leitura que a conversão faz em seguida. A primeira versão usava um `fetch` de
 * dois bytes; funcionava na máquina de desenvolvimento, mas no container o
 * cliente HTTP do Node não alcança os CDNs de embed (`ETIMEDOUT`) enquanto o
 * FFmpeg os lê — e a fonte era recusada mesmo com o backend já tendo confirmado
 * o vídeo.
 *
 * O que estes testes travam é a tradução — a parte que não depende de rede.
 */

test('traduz os contêineres progressivos conhecidos', () => {
  assert.equal(extensaoPeloFormato('mov,mp4,m4a,3gp,3g2,mj2'), '.mp4')
  assert.equal(extensaoPeloFormato('mp4'), '.mp4')
  assert.equal(extensaoPeloFormato('MOV'), '.mp4')
})

test('traduz a playlist HLS', () => {
  assert.equal(extensaoPeloFormato('hls'), '.m3u8')
  assert.equal(extensaoPeloFormato('applehttp'), '')
})

test('Matroska e WebM caem no mesmo leitor', () => {
  // O ffprobe devolve `matroska,webm`; o FFmpeg lê os dois como Matroska.
  assert.equal(extensaoPeloFormato('matroska,webm'), '.mkv')
  assert.equal(extensaoPeloFormato('webm'), '.mkv')
})

test('MPEG-TS é lido como arquivo progressivo', () => {
  assert.equal(extensaoPeloFormato('mpegts'), '.mp4')
})

test('não inventa formato para o que não conhece', () => {
  assert.equal(extensaoPeloFormato(''), '')
  assert.equal(extensaoPeloFormato(null), '')
  assert.equal(extensaoPeloFormato(undefined), '')
  assert.equal(extensaoPeloFormato('image2'), '')
})
