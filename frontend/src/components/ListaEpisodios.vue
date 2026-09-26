<script setup>
import SkeletonBlock from '@/components/SkeletonBlock.vue'

/**
 * Lista de episódios de uma temporada.
 *
 * Cada linha traz o essencial para o usuário decidir o que assistir: capa,
 * número, título, sinopse curta e nota. O botão de play emite o episódio
 * escolhido para o modal, que abre o player com a temporada e o episódio
 * corretos — é isso que faz a busca de fontes montar o termo "SxxExx".
 */
defineProps({
  /** Episódios normalizados pelo backend. */
  episodios: {
    type: Array,
    default: () => [],
  },
  /** Exibe os placeholders enquanto a temporada carrega. */
  carregando: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['assistir'])

function assistir(episodio) {
  emit('assistir', episodio)
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
            v-if="episodio.capa"
            :src="episodio.capa"
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
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
