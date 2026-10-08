<script setup lang="ts">
/**
 * Urgence firmy ({@link SupplierUrgencyInfo}, BE `SupplierDirectory`): štítek úrovně
 * a důvody, nejzávažnější první. Přehled firem ukazuje důvody rozepsané, správa firem
 * jen štítek (`compact`) s důvody v nápovědě.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SupplierUrgencyInfo } from '@/api/suppliers'

const props = defineProps<{ urgency: SupplierUrgencyInfo; compact?: boolean }>()
const { t } = useI18n()

type Tone = 'danger' | 'warning' | 'neutral'

const levelLabel = computed(() => ({
  high: t('supplier.listing.level_high'),
  medium: t('supplier.listing.level_medium'),
  low: t('supplier.listing.level_low'),
  none: t('supplier.listing.no_urgency'),
})[props.urgency.level])

const badgeClass = computed(() => {
  if (props.urgency.level === 'high') return 'bg-danger-50 text-danger-600 ring-danger-500/20'
  if (props.urgency.level === 'medium') return 'bg-warning-50 text-warning-600 ring-warning-500/20'
  return 'bg-neutral-100 text-neutral-600 ring-neutral-300/40'
})

const reasons = computed(() => {
  const u = props.urgency
  const out: { text: string; tone: Tone }[] = []
  if (u.vat?.status === 'overdue') out.push({ text: t('supplier.listing.vat_overdue', { period: u.vat.period }), tone: 'danger' })
  if (u.vat?.status === 'due_soon') out.push({ text: t('supplier.listing.vat_due_soon', { period: u.vat.period, days: u.vat.days }), tone: 'warning' })
  if (u.overdue_payables > 0) out.push({ text: t('supplier.listing.overdue_payables', { n: u.overdue_payables }), tone: 'warning' })
  if (u.overdue_receivables > 0) out.push({ text: t('supplier.listing.overdue_receivables', { n: u.overdue_receivables }), tone: 'warning' })
  if (u.unmatched_bank_transactions > 0) out.push({ text: t('supplier.listing.unmatched_bank', { n: u.unmatched_bank_transactions }), tone: 'neutral' })
  if (u.purchase_drafts > 0) out.push({ text: t('supplier.listing.purchase_drafts', { n: u.purchase_drafts }), tone: 'neutral' })
  return out
})

function reasonClass(tone: Tone): string {
  if (tone === 'danger') return 'text-danger-600'
  if (tone === 'warning') return 'text-warning-600'
  return 'text-neutral-500'
}
</script>

<template>
  <span v-if="urgency.level === 'none'" class="text-xs text-neutral-400" data-testid="supplier-urgency">{{ levelLabel }}</span>
  <span v-else class="inline-flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" data-testid="supplier-urgency">
    <span class="inline-flex items-center px-2 py-0.5 rounded-full font-medium ring-1 ring-inset whitespace-nowrap" :class="badgeClass"
      :title="compact ? reasons.map(r => r.text).join(' · ') : undefined">
      <template v-if="!compact">{{ t('supplier.listing.col_urgency') }}: </template>{{ levelLabel }}
    </span>
    <template v-if="!compact">
      <span v-for="r in reasons" :key="r.text" class="whitespace-nowrap" :class="reasonClass(r.tone)">{{ r.text }}</span>
    </template>
  </span>
</template>
