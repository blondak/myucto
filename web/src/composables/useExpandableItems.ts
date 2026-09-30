import { ref, shallowRef, watch } from 'vue'

export function useExpandableItems<T>(fetchItems: (id: number) => Promise<T[]>, scope: () => unknown) {
  const expandedId = ref<number | null>(null)
  const expandedItems = shallowRef<T[] | null>(null)
  const expandedLoading = ref(false)
  const cache = new Map<number, Promise<T[]>>()
  let revision = 0

  function clearExpandedItems() {
    revision++
    cache.clear()
    expandedId.value = null
    expandedItems.value = null
    expandedLoading.value = false
  }

  async function toggleItems(id: number) {
    const current = ++revision
    expandedItems.value = null
    if (expandedId.value === id) {
      expandedId.value = null
      expandedLoading.value = false
      return
    }
    expandedId.value = id
    expandedLoading.value = true
    let pending = cache.get(id)
    if (!pending) {
      pending = fetchItems(id)
      cache.set(id, pending)
    }
    try {
      const items = await pending
      if (current === revision) expandedItems.value = items
    } catch {
      if (cache.get(id) === pending) cache.delete(id)
      if (current === revision) expandedId.value = null
    } finally {
      if (current === revision) expandedLoading.value = false
    }
  }

  watch(scope, clearExpandedItems, { flush: 'sync' })
  return { expandedId, expandedItems, expandedLoading, toggleItems, clearExpandedItems }
}
