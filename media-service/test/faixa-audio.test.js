import test from 'node:test'
import assert from 'node:assert/strict'

import { descreverFaixaAudio, temFaixaPortuguesa } from '../src/services/hls.js'

/*
 * O porteiro de idioma pergunta "o arquivo tem faixa em português?" e age sobre a
 * resposta: `true` deixa tocar, `false` descarta a fonte e `null` não decide. A
 * regra que originou estes testes: só a **etiqueta** do contêiner prova um idioma
 * estrangeiro. Uma faixa sem código (`und`/`mul` ou sem tag alguma) é ausência de
 * prova, não prova de áudio original — era o que reprovava o pack dublado do
 * `[IceBlue]` que o ffprobe mede como `#0[sem-código]`.
 *
 * O `title` da faixa entra do lado que confirma: quando o código não identifica
 * idioma, é ele que diz "Dublado"/"Português (BR)".
 */

/** Monta o stream de áudio no formato que o ffprobe entrega. */
function stream(tags = {}, extra = {}) {
  return { codec_type: 'audio', codec_name: 'aac', tags, ...extra }
}

/** Descreve faixas em sequência, exatamente como `analisarArquivo` faz. */
function faixas(...streams) {
  return streams.map(descreverFaixaAudio)
}

test('o código sem informação deixa o título da faixa falar', () => {
  const faixa = descreverFaixaAudio(stream({ language: 'und', title: 'Dublado' }), 0)

  assert.equal(faixa.codigo, 'und')
  assert.equal(faixa.rotulo, 'Dublado')
})

test('o código de idioma conhecido vence o título', () => {
  const faixa = descreverFaixaAudio(stream({ language: 'por', title: 'faixa 1' }), 0)

  assert.equal(faixa.rotulo, 'Português')
})

test('sem código e sem título o rótulo cai no código genérico', () => {
  const faixa = descreverFaixaAudio(stream({ language: 'und' }), 0)

  assert.equal(faixa.rotulo, 'Idioma não informado')
})

test('uma faixa sem tag alguma fica sem rótulo', () => {
  const faixa = descreverFaixaAudio(stream(), 0)

  assert.equal(faixa.codigo, null)
  assert.equal(faixa.rotulo, null)
})

test('a dublagem marcada só no título confirma português', () => {
  assert.equal(temFaixaPortuguesa(faixas(stream({ language: 'und', title: 'Dublado' }))), true)
})

test('a faixa sem código nenhum não julga — devolve null, não false', () => {
  assert.equal(temFaixaPortuguesa(faixas(stream())), null)
})

test('sem faixas para julgar também é null', () => {
  assert.equal(temFaixaPortuguesa([]), null)
})

test('japonês + inglês etiquetados provam áudio original', () => {
  const lista = faixas(stream({ language: 'jpn' }), stream({ language: 'eng' }))

  assert.equal(temFaixaPortuguesa(lista), false)
})

test('uma única faixa japonesa etiquetada já prova o original', () => {
  assert.equal(temFaixaPortuguesa(faixas(stream({ language: 'jpn' }))), false)
})

test('japonês + português aprova a fonte', () => {
  const lista = faixas(stream({ language: 'jpn' }), stream({ language: 'por' }))

  assert.equal(temFaixaPortuguesa(lista), true)
})

test('uma faixa sem etiqueta ao lado de uma estrangeira impede o veredito', () => {
  const lista = faixas(stream({ language: 'jpn' }), stream())

  assert.equal(temFaixaPortuguesa(lista), null)
})

test('o código pt-br na faixa confirma português', () => {
  assert.equal(temFaixaPortuguesa(faixas(stream({ language: 'pt-BR' }))), true)
})
