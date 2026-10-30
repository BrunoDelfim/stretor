<script setup>
/**
 * Seletor de temporadas em formato de balões clicáveis.
 *
 * É o padrão que os streamings consagraram: um botão por temporada, com a ativa
 * destacada, todas visíveis de uma vez e a troca a um clique — sem `<select>`,
 * sem menu para abrir. A faixa **quebra linha** em vez de rolar na horizontal:
 * série longa escondia as últimas temporadas atrás de um scroll que passava
 * despercebido, e a impressão era de que o modal só tinha as primeiras.
 */
defineProps({
  /** Lista de temporadas normalizadas pelo backend. */
  temporadas: {
    type: Array,
    default: () => [],
  },
  /** Número da temporada atualmente selecionada. */
  ativa: {
    type: Number,
    default: null,
  },
})

const emit = defineEmits(['selecionar'])

/**
 * Largura máxima do nome dentro do balão.
 *
 * Séries de antologia têm nomes longos ("A História de Uma Vida"); quebrar o
 * balão em três linhas desalinha a faixa inteira. O corte é só visual — o nome
 * completo continua no `title` e no rótulo acessível.
 */
const LIMITE_DO_NOME = 18

function selecionar(numero) {
  emit('selecionar', numero)
}

/**
 * Nome da temporada quando ele diz algo além do número.
 *
 * O TMDB nem sempre numera as temporadas como o público as conhece: "Lupin"
 * tem a Parte 1 e a Parte 2 dentro da temporada 1, e a Parte 4 aparece como
 * temporada 3. Mostrar só "T3" faz o usuário concluir que a temporada nova não
 * está lá. Quando o nome é o genérico do TMDB ("Temporada 3", "Season 3"), ele
 * não acrescenta nada e o balão fica só com o número.
 *
 * @param {object} temporada
 * @returns {string|null}
 */
function nomeProprio(temporada) {
  const nome = (temporada?.nome ?? '').trim()

  if (!nome) return null
  if (/^(temporada|season|temporada especial|specials)\s*\d*$/i.test(nome)) return null

  return nome
}

/**
 * O nome já cortado para caber no balão. `null` quando não há o que mostrar.
 *
 * @param {object} temporada
 * @returns {string|null}
 */
function nomeCurto(temporada) {
  const nome = nomeProprio(temporada)

  if (!nome) return null

  return nome.length > LIMITE_DO_NOME ? `${nome.slice(0, LIMITE_DO_NOME - 1)}…` : nome
}

/**
 * Texto completo do balão, usado no `title` e no rótulo acessível.
 *
 * Junta nome, quantidade de episódios e ano — o que o usuário costuma conferir
 * antes de escolher a temporada — sem poluir o visual do botão.
 *
 * @param {object} temporada
 */
function descricaoCompleta(temporada) {
  const partes = [`Temporada ${temporada.numero}`]

  const nome = nomeProprio(temporada)
  if (nome) partes.push(nome)

  if (temporada.qtd_episodios) {
    partes.push(`${temporada.qtd_episodios} ${temporada.qtd_episodios === 1 ? 'episódio' : 'episódios'}`)
  }

  if (temporada.ano) partes.push(String(temporada.ano))

  return partes.join(' · ')
}
</script>

<template>
  <div v-if="temporadas.length" class="space-y-2">
    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Temporadas</h3>

    <!--
      A faixa quebra linha em vez de rolar na horizontal: série longa (Dexter
      tem oito temporadas) escondia as últimas atrás de um scroll que o usuário
      não percebia estar ali — e a impressão era de que o modal só tinha as
      primeiras. Com `flex-wrap`, todas as temporadas ficam visíveis de uma vez.
    -->
    <div class="flex flex-wrap gap-2 -mx-1 px-1">
      <button
        v-for="temporada in temporadas"
        :key="temporada.numero"
        type="button"
        class="shrink-0 rounded-full border px-4 py-1.5 text-sm font-semibold transition"
        :class="
          temporada.numero === ativa
            ? 'border-white bg-white text-navy-950'
            : 'border-white/25 text-slate-200 hover:bg-white/10'
        "
        :title="descricaoCompleta(temporada)"
        :aria-label="descricaoCompleta(temporada)"
        :aria-pressed="temporada.numero === ativa"
        @click="selecionar(temporada.numero)"
      >
        T{{ temporada.numero }}
        <span
          v-if="nomeCurto(temporada)"
          class="ml-1 font-normal opacity-80"
        >
          {{ nomeCurto(temporada) }}
        </span>
      </button>
    </div>
  </div>
</template>
