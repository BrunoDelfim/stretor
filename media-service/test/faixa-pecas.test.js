import test from 'node:test'
import assert from 'node:assert/strict'

import { faixaDePecas } from '../src/services/sessoes.js'

/*
 * A janela de leitura e a priorização do `moov` só funcionam se a faixa de
 * bytes virar faixa de *pedaços*: o WebTorrent ignora o intervalo quando o
 * pedido sai de `arquivo.select(prioridade, de, ate)` e aceita intervalo apenas
 * em `torrent.select(inicio, fim, prioridade)`. Medido com o WebTorrent 2.8.5:
 * `select(6, 100, 300)` no arquivo deixou o torrent com a seleção `0-767@8`, ou
 * seja, o filme inteiro — a janela nunca existiu.
 *
 * Estes testes fixam a conversão, inclusive o `offset` do arquivo dentro do
 * torrent (num pack o episódio não começa no pedaço zero) e os limites do fim.
 */

const PEDACO = 16 * 1024

/** Torrent de um arquivo só, com o mapa de pedaços já publicado. */
function torrentDeUmArquivo(totalPecas) {
  return { pieceLength: PEDACO, pieces: new Array(totalPecas).fill(null) }
}

test('converte uma faixa de bytes em pedaços no arquivo de um torrent só', () => {
  const torrent = torrentDeUmArquivo(768)
  const arquivo = { offset: 0, length: 768 * PEDACO }

  assert.deepEqual(faixaDePecas(torrent, arquivo, 0, PEDACO), [0, 0])
  assert.deepEqual(faixaDePecas(torrent, arquivo, 1000, 20000), [0, 1])
  assert.deepEqual(faixaDePecas(torrent, arquivo, PEDACO, 2 * PEDACO), [1, 1])
})

test('respeita o offset do arquivo dentro do torrent (pack de episódios)', () => {
  const torrent = torrentDeUmArquivo(2000)

  // O episódio começa no byte 5 MiB, que cai exatamente no pedaço 320.
  const arquivo = { offset: 5 * 1024 * 1024, length: 3 * PEDACO }

  assert.deepEqual(faixaDePecas(torrent, arquivo, 0, PEDACO), [320, 320])
  assert.deepEqual(faixaDePecas(torrent, arquivo, PEDACO, 3 * PEDACO), [321, 322])
})

test('não invade o pedaço seguinte quando a faixa termina na fronteira', () => {
  const torrent = torrentDeUmArquivo(4)

  // Arquivo que ocupa os pedaços 0 e 1 exatamente; pedir até o byte 2*PEDACO
  // não pode pedir o pedaço 2, que já é de outro arquivo do pack.
  const arquivo = { offset: 0, length: 2 * PEDACO }

  assert.deepEqual(faixaDePecas(torrent, arquivo, 0, 2 * PEDACO), [0, 1])
})

test('prende a faixa ao último pedaço existente no torrent', () => {
  const torrent = torrentDeUmArquivo(10)
  const arquivo = { offset: 9 * PEDACO, length: 4 * PEDACO }

  // O último pedaço do torrent é o 9; pedir além disso faz o WebTorrent
  // descartar a seleção inteira.
  assert.deepEqual(faixaDePecas(torrent, arquivo, 0, 4 * PEDACO), [9, 9])
})

test('nunca devolve faixa invertida quando o fim não passa do começo', () => {
  const torrent = torrentDeUmArquivo(100)

  // `de` maior que `ate` (posição de leitura parada no fim, janela zerada).
  assert.deepEqual(faixaDePecas(torrent, { offset: 0, length: 10 * PEDACO }, 5000, 10), [0, 0])
})

test('prende a faixa aos limites do arquivo', () => {
  const torrent = torrentDeUmArquivo(100)
  const arquivo = { offset: 0, length: 3 * PEDACO }

  // Pedido que passa do fim do arquivo para no último pedaço dele.
  assert.deepEqual(faixaDePecas(torrent, arquivo, 3 * PEDACO, 999 * PEDACO), [2, 2])
  assert.deepEqual(faixaDePecas(torrent, arquivo, -500, PEDACO), [0, 0])
})

test('devolve nada enquanto o torrent não publicou o mapa de pedaços', () => {
  const arquivo = { offset: 0, length: 10 * PEDACO }

  assert.equal(faixaDePecas({ pieceLength: PEDACO }, arquivo, 0, PEDACO), null)
  assert.equal(faixaDePecas({ pieces: [] }, arquivo, 0, PEDACO), null)
  assert.equal(faixaDePecas({}, arquivo, 0, PEDACO), null)
  assert.equal(faixaDePecas(null, arquivo, 0, PEDACO), null)
  assert.equal(faixaDePecas(torrentDeUmArquivo(10), null, 0, PEDACO), null)
})
