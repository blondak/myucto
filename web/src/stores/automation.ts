import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { automationApi, type AutomationCounts } from '@/api/automation'

export const useAutomationStore = defineStore('automation', () => {
  const counts = ref<AutomationCounts | null>(null)
  const scopeSupplierId = ref<number | 'all'>('all')
  const actionable = computed(() => (counts.value?.pending ?? 0) + (counts.value?.needs_input ?? 0))
  let timer: number | null = null

  async function refresh(): Promise<void> {
    try { counts.value = await automationApi.counts() } catch { counts.value = null }
  }
  function startPolling(): void {
    if (timer !== null) return
    void refresh()
    timer = window.setInterval(() => void refresh(), 300_000)
    document.addEventListener('visibilitychange', () => { if (!document.hidden) void refresh() })
  }
  function setScopeSupplier(id: number | 'all'): void {
    scopeSupplierId.value = id
  }

  return { counts, scopeSupplierId, actionable, refresh, startPolling, setScopeSupplier }
})
