import test from 'node:test'
import assert from 'node:assert/strict'

import { escolherArquivoDeVideo } from '../src/services/sessoes.js'

/*
 * A escolha do arquivo dentro de um pack é o que decide se o player abre o
 * episódio certo. O caso que originou estes testes: packs de anime numeram os
 * episódios de forma **absoluta** ("... Attack on Titan - 87.mkv" é o S04E28) e
 * não há "SxxExx" nenhum. Sem casar essa numeração, a seleção caía no maior
 * vídeo do pacote — quase sempre um filme — e tocava o conteúdo errado.
 */

const BASE = '[Anime Time] Attack On Titan (Complete Collection) (S01-S04+OVA+Movies+Junior High)'

/** Monta um arquivo de vídeo do torrent a partir do caminho relativo. */
function arquivo(caminho, length) {
  return { path: caminho, name: caminho.split('/').pop(), length }
}

const packAnime = {
  files: [
    arquivo(`${BASE}/Attack on Titan Season 1/[Anime Time] Attack on Titan - 01.mkv`, 900),
    arquivo(`${BASE}/Attack on Titan Season 1/[Anime Time] Attack on Titan - 02.mkv`, 900),
    arquivo(`${BASE}/Attack on Titan Season 4/[Anime Time] Attack on Titan - 60.mkv`, 900),
    arquivo(`${BASE}/Attack on Titan Season 4/[Anime Time] Attack on Titan - 86.mkv`, 900),
    arquivo(`${BASE}/Attack on Titan Season 4/[Anime Time] Attack on Titan - 87.mkv`, 950),
    arquivo(`${BASE}/Attack on Titan Season 4/Extras/[Anime Time] Creditless Opening 4.mkv`, 50),
    arquivo(`${BASE}/Attack On Titan Junior High/[Anime Time] Attack on Titan Junior High - 01.mkv`, 300),
    // O maior vídeo do pacote é justamente um filme: é ele que o fallback antigo
    // escolhia, e é o que a correção precisa nunca mais entregar.
    arquivo(`${BASE}/Attack On Titan Movies/[Anime Time] Attack on Titan Movie  04 - Chronicle.mkv`, 999999),
  ],
  path: '/tmp',
}

test('pack com numeração absoluta: casa S04E28 no arquivo "... - 87.mkv"', () => {
  const escolhido = escolherArquivoDeVideo(packAnime, 4, 28)

  assert.equal(escolhido.path, `${BASE}/Attack on Titan Season 4/[Anime Time] Attack on Titan - 87.mkv`)
})

test('pack com numeração absoluta: casar o primeiro episódio da temporada', () => {
  assert.equal(
    escolherArquivoDeVideo(packAnime, 4, 1).path,
    `${BASE}/Attack on Titan Season 4/[Anime Time] Attack on Titan - 60.mkv`
  )

  assert.equal(
    escolherArquivoDeVideo(packAnime, 1, 1).path,
    `${BASE}/Attack on Titan Season 1/[Anime Time] Attack on Titan - 01.mkv`
  )
})

test('nunca entrega o filme (maior vídeo) quando há pasta de temporada', () => {
  const escolhido = escolherArquivoDeVideo(packAnime, 4, 28)

  assert.ok(!escolhido.path.includes('Attack On Titan Movies/'))
  assert.ok(!escolhido.path.includes('Chronicle'))
})

test('o nome com SxxExx continua vencendo, sem depender da numeração absoluta', () => {
  const torrent = {
    files: [
      arquivo('Serie/Season 4/Serie.S04E28.mkv', 900),
      // Um "Movie" maior não pode roubar a vez do episódio nomeado.
      arquivo('Serie/Movies/Serie Movie 04.mkv', 999999),
    ],
    path: '/tmp',
  }

  assert.equal(escolherArquivoDeVideo(torrent, 4, 28).path, 'Serie/Season 4/Serie.S04E28.mkv')
})

test('sem pasta de temporada, mantém o fallback do maior vídeo', () => {
  const torrent = {
    files: [
      arquivo('Filme (1999)/filme.mp4', 500),
      arquivo('Filme (1999)/extra.mp4', 900),
    ],
    path: '/tmp',
  }

  assert.equal(escolherArquivoDeVideo(torrent, null, null).path, 'Filme (1999)/extra.mp4')
})

test('episódio fora do alcance da temporada cai no maior vídeo (não inventa)', () => {
  // S04E99 não existe: a 4ª temporada vai até o 87.
  const escolhido = escolherArquivoDeVideo(packAnime, 4, 99)

  assert.equal(escolhido.path, `${BASE}/Attack On Titan Movies/[Anime Time] Attack on Titan Movie  04 - Chronicle.mkv`)
})
