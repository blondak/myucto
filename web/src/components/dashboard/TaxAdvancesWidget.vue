<script setup lang="ts">
/**
 * Dashboard widget „Nadcházející zálohy na daň a pojistné" (E9, audit 2026-07).
 * Self-gating: nic nevykreslí, když nejsou žádné naplánované předpisy záloh.
 * Data z modulu předpisů záloh (tax_advance_schedules) — nejbližší splatnosti.
 */
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { taxReturnApi, type AdvanceSchedule } from '@/api/taxReturn'
import { formatMoney } from '@/composables/useFormat'

const { t } = useI18n()
const items = ref<AdvanceSchedule[]>([])

onMounted(async () => {
  try {
    const res = await taxReturnApi.upcomingAdvances()
    items.value = res.items.slice(0, 5)
  } catch { /* best-effort widget */ }
})

const hasItems = computed(() => items.value.length > 0)
function kindLabel(k: string): string {
  return t(`taxReturn.advance_kind_${k}`)
}
</script>

<template>
  <RouterLink v-if="hasItems" to="/reports/income-tax"
    class="block bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm hover:border-primary-300 transition">
    <div class="flex items-center justify-between mb-3">
      <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('taxReturn.advances_widget_title') }}</span>
      <span class="text-xs text-primary-600">{{ t('taxReturn.advances_widget_open') }} →</span>
    </div>
    <ul class="space-y-1.5">
      <li v-for="a in items" :key="a.id" class="flex items-center justify-between text-sm">
        <span class="flex items-center gap-2">
          <span class="font-mono text-xs" :class="a.is_overdue ? 'text-danger-500' : 'text-neutral-500'">{{ a.due_date }}</span>
          <span class="text-neutral-700">{{ kindLabel(a.advance_kind) }}</span>
        </span>
        <span class="font-mono" :class="a.is_overdue ? 'text-danger-600 font-semibold' : 'text-neutral-700'">{{ formatMoney(a.amount, 'CZK') }}</span>
      </li>
    </ul>
  </RouterLink>
</template>
