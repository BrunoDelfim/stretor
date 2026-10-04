import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'

import {
  aguardarBufferInicial,
  comRetentativa,
  ehFalhaDeResolucao,
  ehFalhaTransitoria,
  iniciarConversao,
  opcoesRedeRemota,
  resumirErro,
} from '../src/services/hls.js'

/*
 * O caso que originou estes testes: a primeira abertura de um episódio pela
 * fonte direta falhava com `Failed to resolve hostname vaiquecol.com: Try again`
 * — o DNS frio do container. O ffprobe caía, a sessão escolhia o modo
 * conservador e o FFmpeg morria na abertura com o mesmo erro, enquanto a tela
 * exibia "Convertendo o vídeo..." até o prazo inteiro do buffer. Na tentativa
 * seguinte a resolução estava quente e tudo funcionava.
 *
 * O que estes testes fixam é a tríade que fecha esse buraco: reconhecer a falha
 * transitória, repetir a sondagem e encerrar na hora quando o processo já
 * morreu.
 */

test('reconhece o erro de DNS que travava a primeira tentativa', () => {
  const erro = new Error(
    'ffprobe exited with code 1\n[tcp @ 0x1] Failed to resolve hostname vaiquecol.com: Try again'
  )

  assert.equal(ehFalhaTransitoria(erro), true)
  assert.equal(ehFalhaDeResolucao(erro), true)
})

test('reconhece a falha de rede sem código, pelo texto do FFmpeg', () => {
  assert.equal(ehFalhaTransitoria(new Error('Error opening input file https://x/y.m3u8: I/O error')), true)
  assert.equal(ehFalhaTransitoria(new Error('Server returned 503 Service Unavailable')), true)
})

test('o código do erro do Node também conta como falha transitória', () => {
  const erro = Object.assign(new Error('getaddrinfo EAI_AGAIN vaiquecol.com'), { code: 'EAI_AGAIN' })

  assert.equal(ehFalhaTransitoria(erro), true)
  assert.equal(ehFalhaDeResolucao(erro), true)
})

test('um arquivo recusado não é confundido com falha de rede', () => {
  const erro = new Error('Invalid data found when processing input')

  assert.equal(ehFalhaTransitoria(erro), false)
  assert.equal(ehFalhaDeResolucao(erro), false)
})

test('o resumo do erro traz o código e a primeira linha, sem o banner do FFmpeg', () => {
  const erro = new Error('Error opening input files: I/O error\nffprobe version 8.0.1\nconfiguration: --disable-x')
  erro.code = 'EIO'

  assert.equal(resumirErro(erro), 'EIO: Error opening input files: I/O error')
  assert.ok(resumirErro(new Error('x'.repeat(400))).length <= 161)
})

test('comRetentativa repete a falha transitória e devolve o resultado da volta seguinte', async () => {
  let tentativas = 0

  const resultado = await comRetentativa(
    async () => {
      tentativas += 1

      if (tentativas === 1) {
        throw Object.assign(new Error('getaddrinfo EAI_AGAIN'), { code: 'EAI_AGAIN' })
      }

      return 'ok'
    },
    { tentativas: 3, esperaMs: 1 }
  )

  assert.equal(resultado, 'ok')
  assert.equal(tentativas, 2)
})

test('comRetentativa não insiste num erro definitivo', async () => {
  let tentativas = 0

  await assert.rejects(
    () =>
      comRetentativa(
        async () => {
          tentativas += 1
          throw new Error('Invalid data found when processing input')
        },
        { tentativas: 3, esperaMs: 1 }
      ),
    /Invalid data found/
  )

  assert.equal(tentativas, 1)
})

test('comRetentativa desiste depois do número de tentativas', async () => {
  let tentativas = 0

  await assert.rejects(() =>
    comRetentativa(
      async () => {
        tentativas += 1
        throw Object.assign(new Error('operation timed out'), { code: 'ETIMEDOUT' })
      },
      { tentativas: 2, esperaMs: 1 }
    )
  )

  assert.equal(tentativas, 2)
})

test('os ajustes de reconexão só entram numa origem remota', () => {
  assert.deepEqual(opcoesRedeRemota('https://vaiquecol.com/cdn/hls/831b/master.m3u8?md5=x'), [
    '-reconnect 1',
    '-reconnect_streamed 1',
    '-reconnect_delay_max 5',
    '-reconnect_on_http_error 5xx,429',
  ])

  // Num arquivo local não há conexão para religar, e a opção é do protocolo
  // HTTP: passá-la faria o FFmpeg abortar a abertura com `Option not found`.
  assert.deepEqual(opcoesRedeRemota('/tmp/torrent/filme.mkv'), [])
})

test('aguardarBufferInicial desiste na hora quando a conversão já morreu', async () => {
  const diretorio = fs.mkdtempSync(path.join(os.tmpdir(), 'stretor-buffer-'))
  const inicio = Date.now()

  try {
    await assert.rejects(
      () => aguardarBufferInicial(diretorio, 8, 180000, null, () => false),
      /terminou antes de publicar o buffer inicial/
    )

    // O ponto do teste é a espera: sem o `estaVivo` ela seria de 180 s.
    assert.ok(Date.now() - inicio < 3000, 'a espera deveria ser imediata')
  } finally {
    fs.rmSync(diretorio, { recursive: true, force: true })
  }
})

test('a conversão que morre na abertura deixa o erro acessível ao chamador', async () => {
  const base = fs.mkdtempSync(path.join(os.tmpdir(), 'stretor-conversao-'))
  const saida = path.join(base, 'saida')
  const comando = iniciarConversao({
    caminho: path.join(base, 'nao-existe.mp4'),
    extensao: '.mp4',
    diretorio: saida,
    modo: 'remux',
  })

  try {
    await assert.rejects(() => aguardarBufferInicial(saida, 8, 30000, null, () => comando.erro() === null))

    assert.ok(comando.erro(), 'o erro do FFmpeg precisa ficar acessível')
  } finally {
    comando.parar()
    fs.rmSync(base, { recursive: true, force: true })
  }
})
