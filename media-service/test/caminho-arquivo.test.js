import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'

import { caminhoDoArquivo, limiteDeRetomada, tamanhoDoArquivoEmDisco } from '../src/services/sessoes.js'
import { analisarArquivo } from '../src/services/hls.js'

/*
 * A regra do disco é `path.join(torrent.path, arquivo.path)`: o caminho que o
 * WebTorrent expõe já traz o nome do torrent na frente. Estes testes fixam os
 * três desfechos que já custaram sessões travadas em "Aguardando dados da
 * fonte...":
 *
 * - torrent de **um arquivo só** (todo lançamento de filme): o caminho exposto
 *   é o nome do torrent, sem barra, e prefixar de novo criava
 *   ".../Filme.mkv/Filme.mkv" — o download corria no caminho certo e o ffprobe
 *   procurava um caminho que nunca existiria;
 * - torrent de **vários arquivos** (pack): o caminho vem com a pasta e não pode
 *   ser duplicado;
 * - nome com **caractere que o disco não aceita**: o store remove esses
 *   caracteres ao gravar, e o preparo precisa procurar pelo nome já limpo.
 *
 * O comportamento da biblioteca (WebTorrent 2.8.5 + fs-chunk-store) foi medido
 * em execução, criando torrents de verdade e gravando um pedaço pelo store.
 */

test('monta o caminho com a pasta do torrent quando o arquivo não vem prefixado', () => {
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

/*
 * O caso de todo lançamento de filme: um arquivo só. O caminho exposto pelo
 * WebTorrent é o próprio nome do torrent, sem barra — e era aí que a checagem
 * por `startsWith(nome + '/')` deixava passar a duplicação.
 */
test('não duplica o nome do torrent quando o torrent tem um arquivo só', () => {
  const torrent = { path: '/tmp', name: 'Filme.2025.1080p.WEB-DL.mkv' }
  const arquivo = { path: 'Filme.2025.1080p.WEB-DL.mkv', name: 'Filme.2025.1080p.WEB-DL.mkv' }

  assert.equal(
    caminhoDoArquivo(torrent, arquivo),
    path.join('/tmp', 'Filme.2025.1080p.WEB-DL.mkv')
  )
})

test('remove do nome os caracteres que o store não grava', () => {
  const torrent = { path: '/tmp', name: 'Episodio 01: O Início.mkv' }
  const arquivo = { path: 'Episodio 01: O Início.mkv', name: 'Episodio 01: O Início.mkv' }

  assert.equal(caminhoDoArquivo(torrent, arquivo), path.join('/tmp', 'Episodio 01 O Início.mkv'))
})

test('remove os caracteres reservados também no arquivo de dentro de um pack', () => {
  const torrent = { path: '/tmp', name: 'Serie 1' }
  const arquivo = { path: 'Serie 1/01 - Pergunta?.mkv', name: '01 - Pergunta?.mkv' }

  assert.equal(
    caminhoDoArquivo(torrent, arquivo),
    path.join('/tmp', 'Serie 1', '01 - Pergunta.mkv')
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
 * O freio da leitura, que só existe no caminho de disco, usa essa mesma régua
 * para decidir quando liberar a conversão. A retomada não pode exigir mais folga
 * do que o arquivo tem para dar: com um alvo inalcançável o ffmpeg ficava
 * pausado (SIGSTOP) até o prazo do buffer inicial estourar — num arquivo menor
 * que a margem, ou nos últimos megabytes de um filme longo já baixado.
 */
test('a retomada do freio nunca pede mais folga do que o arquivo pode dar', () => {
  const margem = 32 * 1024 * 1024
  const passo = 16 * 1024 * 1024

  // Arquivo pequeno: o alvo encosta no arquivo e a conversão volta assim que ele
  // está inteiro em disco.
  assert.equal(limiteDeRetomada(margem, passo, 1224964), 1224964)

  // Filme longo com download em andamento: o alvo segue sendo a margem + passo.
  assert.equal(limiteDeRetomada(margem, passo, 3 * 1024 * 1024 * 1024), margem + passo)

  // Arquivo lido até o fim: nada a esperar, mas o valor precisa continuar
  // distinto de zero — o freio usa zero como "não está pausado".
  assert.equal(limiteDeRetomada(margem, passo, 0), 1)
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
