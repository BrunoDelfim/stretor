<script setup>
import { computed } from 'vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import SeloVerificacao from '@/components/SeloVerificacao.vue'

/**
 * Lista de episódios de uma temporada.
 *
 * Cada linha traz o essencial para o usuário decidir o que assistir: capa,
 * número, título, sinopse curta e nota. O botão de play emite o episódio
 * escolhido para o modal, que abre o player com a temporada e o episódio
 * corretos — é isso que faz a busca de fontes montar o termo "SxxExx".
 *
 * O selo é do **episódio**, não da série: o E01 estar no ar não garante o resto
 * da temporada, e repetir o selo da série aqui seria prometer o que não foi
 * testado. Enquanto a verificação em segundo plano não passa por uma linha, ela
 * aparece como não verificada.
 */
const props = defineProps({
  /** Episódios normalizados pelo backend, já com o selo de cada um. */
  episodios: {
    type: Array,
    default: () => [],
  },
  /** Exibe os placeholders enquanto a temporada carrega. */
  carregando: {
    type: Boolean,
    default: false,
  },
  /**
   * Arte da série, usada como reserva quando o episódio não tem imagem própria.
   *
   * O TMDB nem sempre tem o `still` do episódio — séries antigas e episódios
   * recém-anunciados costumam vir sem. Repetir a arte da série é melhor que a
   * moldura cinza vazia: mantém a identidade visual e a linha continua clicável.
   */
  capaSerie: {
    type: String,
    default: null,
  },
  /** Quantos episódios desta temporada ainda estão sem prova de fonte. */
  pendentes: {
    type: Number,
    default: 0,
  },
  /** Total de episódios da temporada; sem ele, o tamanho da lista serve. */
  total: {
    type: Number,
    default: 0,
  },
})

const emit = defineEmits(['assistir'])

const totalDaTemporada = computed(() => props.total || props.episodios.length)

const verificados = computed(() =>
  Math.max(0, Math.min(totalDaTemporada.value, totalDaTemporada.value - props.pendentes))
)

/**
 * Percentual já coberto pela verificação.
 *
 * A barra mede a temporada inteira, e não a fila da sessão: o usuário vê o
 * quanto falta para a lista toda estar selada, sem precisar contar linhas.
 */
const progresso = computed(() => {
  if (!totalDaTemporada.value) return 0

  return Math.round((verificados.value / totalDaTemporada.value) * 100)
})

function assistir(episodio) {
  emit('assistir', episodio)
}

/**
 * Resolve a imagem da linha: a do episódio quando existe, senão a da série.
 */
function imagemDoEpisodio(episodio) {
  return episodio.capa || props.capaSerie || null
}
</script>

<template>
  <div class="space-y-3">
    <!-- Skeleton: mantém a altura aproximada das linhas reais para o modal não
         "pular" quando os episódios chegam. -->
    <template v-if="carregando">
      <div
        v-for="indice in 4"
        :key="indice"
        class="flex gap-4 rounded-lg border border-white/5 bg-white/5 p-3"
      >
        <SkeletonBlock largura="w-32" altura="h-20" arredondamento="rounded-md" />
        <div class="flex-1 space-y-2 py-1">
          <SkeletonBlock largura="w-1/3" altura="h-4" />
          <SkeletonBlock largura="w-full" altura="h-3" />
          <SkeletonBlock largura="w-2/3" altura="h-3" />
        </div>
      </div>
    </template>

    <template v-else>
      <!-- A verificação em segundo plano passa linha por linha. A barra mede a
           temporada inteira (não a fila da sessão), para o usuário ver o quanto
           falta de verdade — e não um contador que parece nunca acabar. -->
      <div
        v-if="pendentes > 0 && episodios.length"
        class="space-y-1.5"
        role="progressbar"
        :aria-valuenow="progresso"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="`Verificando fontes: ${verificados} de ${totalDaTemporada} episódios`"
      >
        <div class="flex items-center justify-between gap-3 text-xs">
          <span class="flex items-center gap-2 text-slate-400">
            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400" aria-hidden="true" />
            Verificando fontes dos episódios
          </span>
          <span class="shrink-0 tabular-nums text-slate-500">
            {{ verificados }} de {{ totalDaTemporada }} · {{ progresso }}%
          </span>
        </div>
        <div class="h-1 w-full overflow-hidden rounded-full bg-white/10">
          <div
            class="h-full rounded-full bg-emerald-400 transition-all duration-500"
            :style="{ width: `${progresso}%` }"
          />
        </div>
      </div>

      <div
        v-for="episodio in episodios"
        :key="episodio.numero"
        class="group flex gap-4 rounded-lg border border-white/5 bg-white/5 p-3 transition hover:bg-white/10"
      >
        <!-- Capa do episódio com o play sobreposto: o clique na imagem já
             dispara a reprodução, como nos streamings. -->
        <button
          type="button"
          class="relative h-20 w-32 shrink-0 overflow-hidden rounded-md bg-slate-700/40"
          :aria-label="`Assistir ao episódio ${episodio.numero}`"
          @click="assistir(episodio)"
        >
          <img
            v-if="imagemDoEpisodio(episodio)"
            :src="imagemDoEpisodio(episodio)"
            :alt="episodio.titulo"
            class="h-full w-full object-cover"
            loading="lazy"
          />
          <span
            class="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition group-hover:opacity-100"
          >
            <svg class="h-8 w-8 text-white" viewBox="0 0 24 24" fill="currentColor">
              <path d="M8 5v14l11-7z" />
            </svg>
          </span>
        </button>

        <div class="min-w-0 flex-1">
          <div class="flex items-start justify-between gap-3">
            <h4 class="truncate text-sm font-semibold text-white">
              {{ episodio.numero }}. {{ episodio.titulo }}
            </h4>
            <span v-if="episodio.nota" class="shrink-0 text-xs font-semibold text-amber-400">
              ★ {{ episodio.nota }}
            </span>
          </div>

          <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-slate-400">
            {{ episodio.sinopse }}
          </p>

          <div class="mt-2 flex items-center gap-3 text-xs text-slate-500">
            <span v-if="episodio.duracao">{{ episodio.duracao }}</span>
            <span v-if="episodio.data_exibicao">{{ episodio.data_exibicao }}</span>
            <!-- O selo da linha é o do episódio: verde só quando este E## tem
                 prova de fonte dentro do prazo. -->
            <SeloVerificacao
              :verificado="Boolean(episodio.verificado)"
              :verificado-em="episodio.verificado_em"
            />
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
