<script setup>
defineProps({
  filme: {
    type: Object,
    required: true,
  },
})

defineEmits(['selecionar'])
</script>

<template>
  <button
    type="button"
    class="group flex flex-col overflow-hidden rounded-lg border border-white/10 bg-navy-900/70 text-left transition duration-300 hover:-translate-y-1 hover:border-white/30 hover:shadow-xl hover:shadow-black/50"
    @click="$emit('selecionar', filme)"
  >
    <div class="relative aspect-[2/3] w-full overflow-hidden bg-slate-700/40">
      <img
        v-if="filme.capa"
        :src="filme.capa"
        :alt="filme.titulo"
        loading="lazy"
        class="h-full w-full object-cover transition duration-500 group-hover:scale-105"
      />
      <div v-else class="flex h-full w-full items-center justify-center px-3 text-center text-xs text-slate-300">
        {{ filme.titulo }}
      </div>

      <span
        v-if="filme.nota"
        class="absolute right-2 top-2 rounded bg-black/70 px-1.5 py-0.5 text-xs font-semibold text-amber-400"
      >
        ★ {{ filme.nota }}
      </span>
    </div>

    <div class="flex flex-1 flex-col gap-1 p-3">
      <h3 class="truncate text-sm font-semibold text-white" :title="filme.titulo">
        {{ filme.titulo }}
      </h3>
      <p class="truncate text-xs text-slate-400">{{ filme.genero }}</p>
      <p class="mt-auto text-xs font-medium text-slate-300">
        <span class="rounded border border-white/20 px-1.5 py-0.5">
          {{ filme.classificacao }}
        </span>
        <span v-if="filme.ano" class="ml-2 text-slate-500">{{ filme.ano }}</span>
      </p>
    </div>
  </button>
</template>
