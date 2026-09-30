import { watch } from 'vue'

export function useBankFilterMemory<T>(key: () => string, state: () => T, restore: (value: Partial<T>) => void) {
  let restoring = false
  watch(key, value => {
    restoring = true
    try {
      const stored = sessionStorage.getItem(`myinvoice.bank.filters.${value}`)
      restore(stored ? JSON.parse(stored) : {})
    } catch {
      restore({})
    } finally {
      restoring = false
    }
  }, { immediate: true, flush: 'sync' })
  watch(state, value => {
    if (restoring) return
    try { sessionStorage.setItem(`myinvoice.bank.filters.${key()}`, JSON.stringify(value)) } catch {}
  }, { deep: true, flush: 'sync' })
}
