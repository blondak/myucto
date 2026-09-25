<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatMoney, formatNumber } from '@/composables/useFormat'

defineProps<{
  rows?: Array<{ rate: number; base: number; vat: number }>
  currency: string
}>()

const { t } = useI18n()
</script>

<template>
  <div v-if="rows?.length" class="space-y-0.5 text-[11px] leading-tight">
    <div v-for="row in rows" :key="row.rate" class="flex items-baseline gap-x-1.5 whitespace-nowrap" :title="`${row.rate} %: ${formatMoney(row.base, currency)} + ${formatMoney(row.vat, currency)}`">
      <span class="font-semibold text-neutral-700">{{ row.rate }} %</span>
      <span class="font-mono text-neutral-600" :title="t('invoice.col_base')">{{ formatNumber(row.base, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
      <span class="font-mono text-primary-700" :title="t('invoice.col_vat')">+ {{ formatMoney(row.vat, currency) }}</span>
    </div>
  </div>
  <span v-else class="text-neutral-300">—</span>
</template>
