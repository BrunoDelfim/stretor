import { computed, onMounted, onUnmounted, ref } from 'vue'
import { FIM_SOLIDIFICACAO, INICIO_SOLIDIFICACAO } from '@/constants/ui'

/**
 * Calcula o progresso de solidificação da navbar conforme a página rola.
 *
 * A navbar nasce transparente e vai ganhando fundo até ficar opaca. Em vez de
 * escutar o evento `scroll` e recalcular a cada frame, guardamos apenas a
 * posição atual e deixamos o `computed` derivar a opacidade — o listener é
 * passivo e se remove sozinho na desmontagem.
 *
 * @returns {{ opacidadeFundo: import('vue').ComputedRef<number>, solidificada: import('vue').ComputedRef<boolean> }}
 */
export function useScrollSolidificacao() {
  const scrollY = ref(0)

  const opacidadeFundo = computed(() => {
    const progresso =
      (scrollY.value - INICIO_SOLIDIFICACAO) / (FIM_SOLIDIFICACAO - INICIO_SOLIDIFICACAO)

    return Math.min(1, Math.max(0, progresso))
  })

  const solidificada = computed(() => opacidadeFundo.value >= 1)

  function aoRolar() {
    scrollY.value = window.scrollY
  }

  onMounted(() => {
    aoRolar()
    window.addEventListener('scroll', aoRolar, { passive: true })
  })

  onUnmounted(() => {
    window.removeEventListener('scroll', aoRolar)
  })

  return { opacidadeFundo, solidificada }
}
