<script setup lang="ts">
/**
 * Objem dat firmy v přehledu firem — mřížka dlaždic (ikona, počet, popisek).
 * Každá dlaždice vede na seznam, kde doklady leží; přepnutí firmy řeší rodič.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PortfolioCompany, PortfolioVolume } from '@/api/portfolio'
import { ICONS } from '@/components/ui/buttonStyles'
import { formatNumber } from '@/composables/useFormat'

const props = defineProps<{ company: PortfolioCompany }>()
const emit = defineEmits<{ open: [path: string] }>()
const { t } = useI18n()

interface VolumeMetric {
  key: keyof PortfolioVolume
  icon: keyof typeof ICONS
  link: string
}

const METRICS: VolumeMetric[] = [
  { key: 'issued_invoices', icon: 'send', link: '/invoices' },
  { key: 'purchase_invoices', icon: 'inbox', link: '/purchase-invoices' },
  { key: 'bank_statements', icon: 'table', link: '/bank' },
  { key: 'bank_transactions', icon: 'swap', link: '/bank' },
  { key: 'cash_documents', icon: 'coin', link: '/accounting/cash' },
  { key: 'journal_entries', icon: 'archive', link: '/accounting/journal' },
]

// Deník existuje jen v podvojném účetnictví (route requiresDoubleEntry).
const metrics = computed(() => METRICS.filter(
  (m) => m.key !== 'journal_entries' || props.company.accounting_mode === 'double_entry',
))
</script>

<template>
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2" role="group" :aria-label="t('portfolio.volume_title')">
    <button v-for="m in metrics" :key="m.key" type="button"
      class="group cursor-pointer flex items-center gap-2.5 px-3 py-2 rounded-lg border border-neutral-200 bg-surface text-left hover:border-primary-300 hover:bg-primary-50/40 transition-colors"
      :title="t('portfolio.volume_hint_' + m.key)"
      @click="emit('open', m.link)">
      <span class="flex items-center justify-center w-8 h-8 rounded-md bg-neutral-100 text-neutral-500 group-hover:bg-primary-100 group-hover:text-primary-700 shrink-0">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[m.icon]" /></svg>
      </span>
      <span class="min-w-0">
        <span class="block text-base font-semibold tabular-nums leading-tight" :class="company.volume[m.key] > 0 ? 'text-neutral-900' : 'text-neutral-400'">{{ formatNumber(company.volume[m.key]) }}</span>
        <span class="block text-xs text-neutral-500 truncate">{{ t('portfolio.volume_' + m.key) }}</span>
      </span>
    </button>
  </div>
</template>
