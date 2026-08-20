import { ref, watch, type Ref } from 'vue'
import { usePaneDom } from '@/composables/usePaneDom'

export function useListCursor(count: Ref<number>) {
  const index = ref(-1)
  const paneDom = usePaneDom()

  function move(delta: 1 | -1): void {
    if (count.value <= 0) { index.value = -1; return }
    index.value = index.value < 0 ? (delta > 0 ? 0 : count.value - 1) : Math.max(0, Math.min(count.value - 1, index.value + delta))
    requestAnimationFrame(() => paneDom.querySelector<HTMLElement>(`[data-automation-row="${index.value}"]`)?.scrollIntoView({ block: 'nearest' }))
  }

  function reset(): void { index.value = -1 }
  watch(count, n => { if (n <= 0) index.value = -1; else if (index.value >= n) index.value = n - 1 })
  return { index, move, reset }
}
