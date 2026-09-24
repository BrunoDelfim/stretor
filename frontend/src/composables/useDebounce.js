import { onUnmounted } from 'vue'

/**
 * Encapsula o padrão de debounce usado em campos de busca.
 *
 * O timer é limpo automaticamente na desmontagem do componente, então quem usa
 * o composable não precisa lembrar de fazer isso no `onUnmounted`.
 *
 * @param {Function} callback Função executada após o intervalo sem novas chamadas.
 * @param {number} espera Milissegundos de silêncio antes de disparar.
 * @returns {Function} Versão debounced do callback.
 */
export function useDebounce(callback, espera) {
  let timer = null

  function executar(...argumentos) {
    clearTimeout(timer)
    timer = setTimeout(() => callback(...argumentos), espera)
  }

  onUnmounted(() => clearTimeout(timer))

  return executar
}
