import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'

import { caminhoDoArquivo, tamanhoDoArquivoEmDisco } from '../src/services/sessoes.js'
import { analisarArquivo } from '../src/services/hls.js'

/*
 * O bug que originou estes testes: o caminho era montado como
 * `path.join(torrent.path, arquivo.path)`, sem a pasta do torrent. O ffprobe
 * recebia um caminho inexistente e devolvia "No such file or directory" mesmo
 * com o arquivo presente alguns níveis abaixo.
 */

test('monta o caminho com a pasta do torrent quando o arquivo é relativo', () => {
  const torrent = {
    path: '/tmp',
    name: 'American Horror Story 1ª Temporada [2011 DUAL ÁUDIO] 720p PT BR',
  }
  const arquivo = { path: '01 - Pilot.mp4', name: '01 - Pilot.mp4' }

  const caminho = caminhoDoArquivo(torrent, arquivo)

  assert.equal(
    caminho,
    path.join(
      '/tmp',
      'American Horror Story 1ª Temporada [2011 DUAL ÁUDIO] 720p PT BR',
      '01 - Pilot.mp4'
    )
  )
})

test('não duplica a pasta do torrent quando o arquivo já vem prefixado', () => {
  const torrent = { path: '/tmp', name: 'Filme (1999)' }
  const arquivo = { path: 'Filme (1999)/filme.mp4', name: 'filme.mp4' }

  const caminho = caminhoDoArquivo(torrent, arquivo)

  assert.equal(caminho, path.join('/tmp', 'Filme (1999)', 'filme.mp4'))
})

test('preserva acentos, colchetes e espaços no caminho', () => {
  const torrent = { path: '/tmp', name: 'Série Ação [Dublado] 1080p' }
  const arquivo = { path: 'Episódio 01 - Ação.mp4', name: 'Episódio 01 - Ação.mp4' }

  const caminho = caminhoDoArquivo(torrent, arquivo)

  assert.ok(caminho.includes('Série Ação [Dublado] 1080p'))
  assert.ok(caminho.includes('Episódio 01 - Ação.mp4'))
})

test('tamanhoDoArquivoEmDisco devolve null quando o arquivo não existe', () => {
  const inexistente = path.join(os.tmpdir(), `stretor-inexistente-${Date.now()}.mp4`)

  assert.equal(tamanhoDoArquivoEmDisco(inexistente), null)
})

test('tamanhoDoArquivoEmDisco devolve o tamanho de um arquivo real', () => {
  const arquivo = path.join(os.tmpdir(), `stretor-teste-${Date.now()}.bin`)

  fs.writeFileSync(arquivo, Buffer.alloc(2048))

  try {
    assert.equal(tamanhoDoArquivoEmDisco(arquivo), 2048)
  } finally {
    fs.rmSync(arquivo, { force: true })
  }
})

test('tamanhoDoArquivoEmDisco devolve null para um diretório', () => {
  assert.equal(tamanhoDoArquivoEmDisco(os.tmpdir()), null)
})

/*
 * A guarda de existência em `analisarArquivo` é o que impede o ffprobe de ser
 * disparado sobre um arquivo que ainda não existe — o cenário dos primeiros
 * porcentos do download, em que o erro "No such file or directory" parecia
 * corrupção. O erro precisa vir rotulado com `code = 'ENOENT'` para o laço de
 * `analisarComEspera` saber que deve esperar em vez de desistir da fonte.
 */
test('analisarArquivo lança ENOENT rotulado quando o arquivo não existe', async () => {
  const inexistente = path.join(os.tmpdir(), `stretor-ausente-${Date.now()}.mp4`)

  await assert.rejects(
    () => analisarArquivo(inexistente),
    (erro) => {
      assert.equal(erro.code, 'ENOENT')
      assert.match(erro.message, /não existe em disco/)

      return true
    }
  )
})
