<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import MovieCardSkeleton from '@/components/MovieCardSkeleton.vue'
import { MARGEM_SENTINELA, QUANTIDADE_SKELETONS } from '@/constants/ui'

/**
 * Sentinela reutilizável de rolagem infinita.
 *
 * Em vez de escutar o evento `scroll` da janela (que dispara dezenas de vezes
 * por segundo e exige throttle manual), usamos `IntersectionObserver`: o próprio
 * browser avisa quando o elemento entra na viewport, sem custo por frame.
 *
 * O `rootMargin` antecipa o disparo antes de o usuário encostar no fim da lista,
 * deixando a próxima página pronta antes de ela ser necessária.
 */
const props = defineProps({
  /** Quando falso, o observer é desligado (ex.: acabaram as páginas). */
  ativo: {
    type: Boolean,
    default: true,
  },
  /** Exibe os cards fantasma enquanto a próxima página chega. */
  carregando: {
    type: Boolean,
    default: false,
  },
  /**
   * Distância antecipada, em pixels, para iniciar o carregamento.
   *
   * Como a sentinela fica no fim do grid, uma margem generosa faz o observer
   * considerá-la "visível" enquanto o usuário ainda está descendo pela seção —
   * a próxima página chega antes de ele encostar no fim, eliminando a pausa
   * visível do carregamento.
   */
  margem: {
    type: String,
    default: MARGEM_SENTINELA,
  },
  /** Quantidade de cards fantasma exibidos durante o carregamento. */
  quantidade: {
    type: Number,
    default: QUANTIDADE_SKELETONS,
  },
})

const emit = defineEmits(['carregar-mais'])

const alvo = ref(null)
let observer = null

function observar() {
  if (!alvo.value || typeof IntersectionObserver === 'undefined') {
    return
  }

  // Garante que não exista um observer antigo ativo antes de criar outro —
  // evita vazamento quando `observar()` é chamado mais de uma vez.
  desconectar()

  observer = new IntersectionObserver(
    (entradas) => {
      // Só emite quando o sentinela realmente aparece e ainda há o que carregar.
      if (entradas.some((entrada) => entrada.isIntersecting) && props.ativo) {
        emit('carregar-mais')
      }
    },
    { rootMargin: props.margem }
  )

  observer.observe(alvo.value)
}

function desconectar() {
  observer?.disconnect()
  observer = null
}

onMounted(observar)

// Ao desativar (fim da lista), paramos de observar para não disparar eventos
// inúteis; se voltar a ficar ativo, reconectamos.
watch(
  () => props.ativo,
  (ativo) => {
    if (ativo) {
      observar()
    } else {
      desconectar()
    }
  }
)

/*
 * Re-arma o observer a cada carregamento concluído.
 *
 * O IntersectionObserver só dispara quando o estado de interseção muda. Como a
 * sentinela usa uma margem generosa (800px), ela costuma continuar "visível"
 * mesmo depois de os novos cards empurrarem o fim da lista para baixo — então o
 * estado nunca volta para falso e o callback não dispara de novo, parando a
 * rolagem em silêncio. Desconectar e reconectar força o browser a reavaliar a
 * interseção atual, disparando na hora se a sentinela ainda estiver na área.
 */
watch(
  () => props.carregando,
  (carregando, estavaCarregando) => {
    if (estavaCarregando && !carregando && props.ativo) {
      desconectar()
      observar()
    }
  }
)

onBeforeUnmount(desconectar)
</script>

<template>
  <!--
    O nó alvo do observer fica sempre no DOM (mesmo sem carregamento) para que a
    observação continue válida entre uma página e outra. Os cards fantasma usam
    o mesmo grid do MovieGrid para alinhar exatamente com os cards reais.
  -->
  <div ref="alvo" class="w-full">
    <div
      v-if="carregando"
      class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6"
      aria-hidden="true"
    >
      <MovieCardSkeleton v-for="n in quantidade" :key="n" />
    </div>

    <!--
      Fim da lista: quando não há mais páginas e nada está carregando, avisamos
      que o catálogo acabou. O `v-if` usa `!ativo` porque a sentinela é desligada
      exatamente quando `temMais` vira falso.
    -->
    <p
      v-else-if="!ativo"
      class="py-4 text-center text-sm text-slate-500"
    >
      Você chegou ao fim — não há mais filmes para carregar no momento.
    </p>
  </div>
</template>
