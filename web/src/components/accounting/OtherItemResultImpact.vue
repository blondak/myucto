<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import type { OtherItemResultImpact } from '@/api/dashboard'

defineProps<{ rows: OtherItemResultImpact[] }>()
const { t } = useI18n()
</script>

<template>
  <section v-if="rows.length" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
    <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
      <div>
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('other_items.result_impact_title') }}</h3>
        <p class="mt-1 text-xs text-neutral-500">{{ t('other_items.result_impact_hint') }}</p>
      </div>
      <RouterLink to="/other-items" class="text-sm font-medium text-primary-700 hover:underline">{{ t('other_items.title') }} ↗</RouterLink>
    </div>
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
      <div v-for="row in rows" :key="row.currency" class="rounded-lg border border-neutral-200 p-3">
        <div class="mb-2 text-xs font-semibold text-neutral-500">{{ row.currency }}</div>
        <dl class="space-y-1 text-sm">
          <div class="flex justify-between gap-3"><dt>{{ t('other_items.result_revenue') }}</dt><dd class="font-mono">{{ formatMoney(row.revenue, row.currency) }}</dd></div>
          <div class="flex justify-between gap-3"><dt>{{ t('other_items.result_costs') }}</dt><dd class="font-mono">{{ formatMoney(row.costs, row.currency) }}</dd></div>
          <div class="flex justify-between gap-3 border-t border-neutral-100 pt-1 font-semibold"><dt>{{ t('other_items.result_profit') }}</dt><dd class="font-mono" :class="row.profit < 0 ? 'text-danger-500' : 'text-success-600'">{{ formatMoney(row.profit, row.currency) }}</dd></div>
        </dl>
        <p class="mt-2 text-xs text-neutral-500">{{ t('other_items.result_posted') }}: {{ formatMoney(row.posted, row.currency) }} · {{ t('other_items.result_draft') }}: {{ formatMoney(row.draft, row.currency) }}</p>
      </div>
    </div>
  </section>
</template>
