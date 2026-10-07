import test from 'node:test'
import assert from 'node:assert/strict'

import { contemIndicioPtBr } from '../src/utils/idiomas.js'

/*
 * A leitura do conteúdo do pack é a segunda barreira contra fontes em outro
 * idioma. A regra é conservadora: só a marca descritiva de áudio PT-BR
 * ("Dublado", "Nacional", "Português", a bandeira) ou o código colado de PT-BR
 * sustentam o veredito. O marcador genérico de multi-faixa ("Dual Audio") e o
 * "pt" solto ficam de fora — num pack de anime o "[Dual Audio]" quase sempre é
 * japonês + inglês, e tratar a tag genérica como prova fazia o pack passar por
 * dublado.
 */

const NOME_ANIME =
  '[Anime Time] Attack On Titan (Complete Collection) (S01-S04+OVA+Movies+Junior High) [BD] [Dual Audio][1080p][HEVC 10bit x265][AAC][Eng Sub]'

const CAMINHO_DUAL =
  '[Anime Time] Attack On Titan (Complete Collection) [BD] [Dual Audio][1080p]/Attack on Titan Season 4/[Anime Time] Attack on Titan - 87.mkv'

test('o "Dual Audio" do nome (anime japonês) não é dublado', () => {
  assert.equal(contemIndicioPtBr(NOME_ANIME), false)
})

test('o "Dual Audio" de uma pasta, sozinho, não sustenta PT-BR', () => {
  assert.equal(contemIndicioPtBr(CAMINHO_DUAL), false)
})

test('"Dublado" no nome prova o áudio', () => {
  assert.equal(contemIndicioPtBr('Dublado Português/Inglês'), true)
  assert.equal(contemIndicioPtBr('[Dual Audio] Dublado [Eng Sub]'), true)
})

test('uma pasta "Dublado/" sustenta o veredito', () => {
  const caminho = `${CAMINHO_DUAL}/Dublado/[Anime Time] Attack on Titan - 88.mkv`

  assert.equal(contemIndicioPtBr(caminho), true)
})

test('o código colado de PT-BR conta; o "pt" solto não', () => {
  assert.equal(contemIndicioPtBr('Attack on Titan S01 PT-BR 1080p'), true)
  assert.equal(contemIndicioPtBr('Filme Legendado pt BR'), false)
  assert.equal(contemIndicioPtBr('Filme pt'), false)
})
