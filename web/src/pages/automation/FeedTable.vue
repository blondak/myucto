<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isBulkEligible, normalizeAutomationSource, type AutomationFeedItem, type AutomationProvenance } from '@/api/automation'
import WhyChip from '@/components/automation/WhyChip.vue'
import ConfidenceLabel from '@/components/automation/ConfidenceLabel.vue'
import PostingPreview from '@/components/automation/PostingPreview.vue'
import NeedsInputCard from '@/components/automation/NeedsInputCard.vue'

const props = defineProps<{ items: AutomationFeedItem[]; tab: 'auto' | 'pending' | 'needs_input'; cursorIndex: number; showSupplier: boolean; busy?: boolean }>()
const emit = defineEmits<{
  approve: [item: AutomationFeedItem, overrides?: Record<string, string>]
  reject: [item: AutomationFeedItem]
  unpost: [item: AutomationFeedItem]
  snooze: [item: AutomationFeedItem]
  inspect: [item: AutomationFeedItem]
  resolved: []
  'update:selected': [ids: string[]]
}>()
const { t } = useI18n()
const selected = ref(new Set<string>())
const expanded = ref(new Set<string>())
const overrides = ref<Record<string, { open: boolean; debit: string; credit: string }>>({})
watch(() => props.items, items => {
  selected.value = new Set([...selected.value].filter(id => items.some(item => item.id === id)))
  emit('update:selected', [...selected.value])
  const next: Record<string, { open: boolean; debit: string; credit: string }> = {}
  for (const item of items) next[item.id] = overrides.value[item.id] ?? {
    open: false, debit: item.debit_account_code ?? '', credit: item.credit_account_code ?? '',
  }
  overrides.value = next
}, { immediate: true })
const allEligible = computed(() => props.items.filter(isBulkEligible))
const columnCount = computed(() => 7 + (props.tab === 'pending' ? 1 : 0) + (props.showSupplier ? 1 : 0))

function provenance(item: AutomationFeedItem): AutomationProvenance {
  return { source: normalizeAutomationSource(item.source), mode: item.tab === 'auto' ? 'auto' : 'approved', confidence: item.confidence,
    detector: item.detector, rule_id: item.rule_id, rule_name: item.rule_name, suggestion_id: item.refs.suggestion_id,
    rule_approved_streak: item.rule_approved_streak,
    decided_at: item.date, decided_by: null }
}
function toggleSelected(item: AutomationFeedItem) {
  if (!isBulkEligible(item)) return
  const next = new Set(selected.value); next.has(item.id) ? next.delete(item.id) : next.add(item.id); selected.value = next
  emit('update:selected', [...next])
}
function toggleAll() {
  selected.value = selected.value.size === allEligible.value.length ? new Set() : new Set(allEligible.value.map(i => i.id))
  emit('update:selected', [...selected.value])
}
function toggleAt(index: number) { const item = props.items[index]; if (item) toggleExpanded(item) }
function toggleExpanded(item: AutomationFeedItem) { const n = new Set(expanded.value); n.has(item.id) ? n.delete(item.id) : n.add(item.id); expanded.value = n }
function approveAt(index: number) { const item = props.items[index]; if (item && item.tab === 'pending' && item.can_write && !item.period_closed) emit('approve', item) }
function rejectAt(index: number) { const item = props.items[index]; if (item && item.tab === 'pending' && item.can_write) emit('reject', item) }
function canOverride(item: AutomationFeedItem) {
  return item.tab === 'pending' && item.kind === 'bank_suggestion'
    && !['transfer', 'payment_match'].includes(item.source)
    && item.detector !== 'own_transfer' && item.operation_type !== 'bank.transfer.own'
}
function toggleOverride(item: AutomationFeedItem) { if (canOverride(item)) overrides.value[item.id].open = !overrides.value[item.id].open }
function approveOverride(item: AutomationFeedItem) {
  const draft = overrides.value[item.id]
  if (!draft?.debit.trim() || !draft.credit.trim()) return
  emit('approve', item, { debit_account_code: draft.debit.trim(), credit_account_code: draft.credit.trim() })
}
function overrideChanged(item: AutomationFeedItem) {
  const draft = overrides.value[item.id]
  return !!draft && (draft.debit.trim() !== (item.debit_account_code ?? '') || draft.credit.trim() !== (item.credit_account_code ?? ''))
}
function noteCode(item: AutomationFeedItem) { return (item.note ?? '').split(':')[0] }
function whyNotAuto(item: AutomationFeedItem) {
  return item.tab === 'pending' && (item.confidence ?? 0) >= 0.9
    && ['period_closed','amount_over_cap','liability_prescription_missing','liability_prescription_short','daily_limit_reached','anomaly','duplicate_suspect','policy_suggest'].includes(noteCode(item))
}
// Kontace chodí z API už po analytických přepisech zaúčtování. Když analytiku vlastního
// účtu určit nešlo, zůstane syntetika — a to se musí poznat, ať to nevypadá jako výsledek.
function accountsPending(item: AutomationFeedItem) { return item.accounts_resolved === false }
function isAnomaly(item: AutomationFeedItem) { return noteCode(item) === 'anomaly' }
function isSnoozed(item: AutomationFeedItem) { return !!item.snoozed_until && item.snoozed_until >= new Date().toISOString().slice(0, 19).replace('T', ' ') }
function showDayHeader(index: number) { return props.tab === 'auto' && (index === 0 || props.items[index - 1]?.date !== props.items[index]?.date) }
defineExpose({ toggleAt, approveAt, rejectAt })
const money = (item: AutomationFeedItem) => new Intl.NumberFormat(undefined, { style: 'currency', currency: item.currency }).format(item.amount)
</script>

<template>
  <div v-if="tab === 'needs_input'" class="space-y-3">
    <NeedsInputCard v-for="item in items" :key="item.id" :item="item" @resolved="emit('resolved')" @snooze="emit('snooze', item)" @inspect="emit('inspect', item)" />
  </div>
  <div v-else>
    <!-- Počet vybraných teď nese plovoucí BulkActionBar v rodičovské stránce, tady zůstává jen ovládání "vybrat vše". -->
    <div v-if="tab === 'pending' && allEligible.length" class="hidden md:flex items-center gap-3 mb-3 text-sm">
      <label class="inline-flex items-center gap-2"><input type="checkbox" :checked="selected.size === allEligible.length" @change="toggleAll">{{ t('automation.select_page') }}</label>
    </div>
    <div class="hidden md:block overflow-x-auto rounded-lg border border-neutral-200 bg-surface">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500"><tr>
          <th v-if="tab === 'pending'" class="p-3 w-8"></th><th v-if="showSupplier" class="p-3">{{ t('automation.col_supplier') }}</th>
          <th class="p-3">{{ t('common.date') }}</th><th class="p-3">{{ t('common.description') }}</th>
          <th class="p-3 text-right">{{ t('common.amount') }}</th><th class="p-3">{{ t('automation.col_why') }}</th>
          <th class="p-3">{{ t('automation.confidence_title') }}</th><th class="p-3">{{ t('automation.col_posting') }}</th><th class="p-3 text-right">{{ t('common.actions') }}</th>
        </tr></thead>
        <tbody class="divide-y divide-neutral-200">
          <template v-for="(item, index) in items" :key="item.id">
            <tr v-if="showDayHeader(index)" class="bg-neutral-100"><td :colspan="columnCount" class="px-3 py-2 text-xs font-semibold uppercase text-neutral-600">{{ item.date }}</td></tr>
            <tr :data-automation-row="index" :aria-selected="cursorIndex === index" :class="[cursorIndex === index ? 'bg-primary-50' : 'hover:bg-neutral-50', isAnomaly(item) ? 'border-l-4 border-warning-500 bg-warning-50/50' : '', isSnoozed(item) ? 'opacity-65' : '']">
              <td v-if="tab === 'pending'" class="p-3"><input type="checkbox" :checked="selected.has(item.id)" :disabled="!isBulkEligible(item)" :title="!isBulkEligible(item) && ['knn','llm'].includes(item.source) ? t('automation.ai_no_bulk') : ''" @change="toggleSelected(item)"></td>
              <td v-if="showSupplier" class="p-3 whitespace-nowrap">{{ item.supplier_name }}</td><td class="p-3 whitespace-nowrap">{{ item.date }}</td>
              <td class="p-3"><button type="button" class="cursor-pointer text-left font-medium hover:text-primary-700" @click="toggleExpanded(item)">{{ item.description || item.counterparty || '—' }}</button><div class="text-xs text-neutral-500">{{ item.counterparty }}</div><span v-if="isSnoozed(item)" class="mt-1 inline-flex rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ t('automation.snoozed_until', { date: item.snoozed_until?.slice(0, 10) }) }}</span></td>
              <td class="p-3 text-right font-mono whitespace-nowrap">{{ money(item) }}</td><td class="p-3"><WhyChip :provenance="provenance(item)" /><div v-if="whyNotAuto(item)" class="mt-1 inline-flex rounded bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700">{{ t('automation.why_not_auto', { reason: t(`automation.reason.${noteCode(item)}`) }) }}</div></td>
              <td class="p-3"><ConfidenceLabel v-if="item.confidence !== null" :confidence="item.confidence" /><span v-else>—</span></td>
              <td class="p-3 font-mono whitespace-nowrap">{{ item.debit_account_code || '—' }}/{{ item.credit_account_code || '—' }}<span v-if="accountsPending(item)" class="ml-1 cursor-help font-sans text-warning-600" :title="t('automation.accounts_not_final')">*</span></td>
              <td class="p-3"><div class="flex justify-end gap-2 whitespace-nowrap">
                <button v-if="tab === 'pending'" type="button" :disabled="busy || !item.can_write || item.period_closed" class="cursor-pointer rounded bg-primary-600 px-2.5 py-1.5 text-white disabled:opacity-40" @click="emit('approve', item)">✓ {{ t('bank.posting.action_approve') }}</button>
                <button v-if="canOverride(item)" type="button" :disabled="busy || !item.can_write || item.period_closed" class="cursor-pointer rounded border border-neutral-300 px-2.5 py-1.5 disabled:opacity-40" @click="toggleOverride(item)">✎ {{ t('automation.override_posting') }}</button>
                <button v-if="tab === 'pending'" type="button" :disabled="busy || !item.can_write" class="cursor-pointer rounded border border-neutral-300 px-2.5 py-1.5 disabled:opacity-40" @click="emit('reject', item)">✕ {{ t('bank.posting.action_reject') }}</button>
                <button v-if="tab !== 'auto' && item.refs.suggestion_id" type="button" :disabled="busy || !item.can_write" class="cursor-pointer rounded border border-neutral-300 px-2.5 py-1.5 disabled:opacity-40" @click="emit('snooze', item)">⏰ {{ isSnoozed(item) ? t('automation.unsnooze') : t('automation.snooze') }}</button>
                <button type="button" class="cursor-pointer rounded border border-neutral-300 px-2.5 py-1.5" @click="emit('inspect', item)">↗ {{ t('automation.source_detail') }}</button>
                <RouterLink v-if="tab === 'auto' && item.journal_entry_id" :to="{ path: '/accounting/journal', query: { entry_id: item.journal_entry_id } }" class="rounded border border-neutral-300 px-2.5 py-1.5">{{ t('automation.show_entry') }}</RouterLink>
                <button v-if="tab === 'auto' && !item.period_closed && item.can_write" type="button" class="cursor-pointer rounded border border-danger-500/40 px-2.5 py-1.5 text-danger-500" @click="emit('unpost', item)">{{ t('automation.reverse') }}</button>
              </div></td>
            </tr>
            <tr v-if="overrides[item.id]?.open"><td :colspan="columnCount" class="bg-warning-50 p-4"><div class="mb-3 flex flex-wrap items-center gap-2 text-sm"><span class="text-neutral-500">{{ t('automation.override_suggested') }}</span><strong class="font-mono">{{ item.debit_account_code }}/{{ item.credit_account_code }}</strong><span>→</span><strong class="font-mono text-primary-700">{{ overrides[item.id].debit || '—' }}/{{ overrides[item.id].credit || '—' }}</strong><span class="text-neutral-500">{{ t('automation.override_learning_hint') }}</span></div><div class="flex flex-wrap items-end gap-3"><label class="text-xs text-neutral-600">{{ t('bank.posting.debit') }}<input v-model="overrides[item.id].debit" class="mt-1 block w-32 rounded border border-neutral-300 px-3 py-2 font-mono text-sm" :class="overrides[item.id].debit !== item.debit_account_code ? 'border-warning-500 bg-surface' : ''"></label><label class="text-xs text-neutral-600">{{ t('bank.posting.credit') }}<input v-model="overrides[item.id].credit" class="mt-1 block w-32 rounded border border-neutral-300 px-3 py-2 font-mono text-sm" :class="overrides[item.id].credit !== item.credit_account_code ? 'border-warning-500 bg-surface' : ''"></label><button type="button" :disabled="busy || !overrides[item.id].debit.trim() || !overrides[item.id].credit.trim() || !overrideChanged(item)" class="rounded bg-primary-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-40" @click="approveOverride(item)">✓ {{ t('automation.approve_override') }}</button></div></td></tr>
            <tr v-if="expanded.has(item.id)"><td :colspan="columnCount" class="p-4 bg-neutral-50"><PostingPreview :debit="item.debit_account_code || ''" :credit="item.credit_account_code || ''" :amount="item.amount" :currency="item.currency" :date="item.date" :description="item.description" /></td></tr>
          </template>
        </tbody>
      </table>
    </div>
    <div class="md:hidden space-y-3">
      <template v-for="(item,index) in items" :key="item.id"><h3 v-if="showDayHeader(index)" class="pt-2 text-xs font-semibold uppercase text-neutral-500">{{ item.date }}</h3><article :data-automation-row="index" class="rounded-lg border bg-surface p-4" :class="[cursorIndex===index ? 'ring-2 ring-primary-500' : '', isAnomaly(item) ? 'border-warning-500 bg-warning-50/50' : 'border-neutral-200', isSnoozed(item) ? 'opacity-65' : '']">
        <div class="flex justify-between gap-3"><div><strong>{{ item.description }}</strong><div class="text-xs text-neutral-500">{{ item.supplier_name }} · {{ item.date }}</div></div><strong class="font-mono">{{ money(item) }}</strong></div>
        <div class="mt-3 flex flex-wrap gap-2"><WhyChip :provenance="provenance(item)" /><ConfidenceLabel v-if="item.confidence !== null" :confidence="item.confidence" /><span class="font-mono">{{ item.debit_account_code }}/{{ item.credit_account_code }}<span v-if="accountsPending(item)" class="ml-1 font-sans text-warning-600" :title="t('automation.accounts_not_final')">*</span></span><span v-if="whyNotAuto(item)" class="rounded bg-warning-50 px-2 py-0.5 text-xs text-warning-700">{{ t('automation.why_not_auto', { reason: t(`automation.reason.${noteCode(item)}`) }) }}</span></div>
        <div class="mt-3 grid grid-cols-2 gap-2"><button v-if="tab==='pending'" :disabled="busy || !item.can_write || item.period_closed" class="rounded bg-primary-600 py-2 text-white disabled:opacity-40" @click="emit('approve',item)">✓ {{ t('bank.posting.action_approve') }}</button><button v-if="canOverride(item)" :disabled="busy || !item.can_write || item.period_closed" class="rounded border border-neutral-300 py-2 disabled:opacity-40" @click="toggleOverride(item)">✎ {{ t('automation.override_posting') }}</button><button v-if="tab==='pending'" :disabled="busy || !item.can_write" class="rounded border border-neutral-300 py-2 disabled:opacity-40" @click="emit('reject',item)">✕ {{ t('bank.posting.action_reject') }}</button><button v-if="tab!=='auto' && item.refs.suggestion_id" :disabled="busy || !item.can_write" class="rounded border border-neutral-300 py-2 disabled:opacity-40" @click="emit('snooze',item)">⏰ {{ isSnoozed(item) ? t('automation.unsnooze') : t('automation.snooze') }}</button><button class="rounded border border-neutral-300 py-2" @click="emit('inspect',item)">↗ {{ t('automation.source_detail') }}</button></div>
        <div v-if="overrides[item.id]?.open" class="mt-3 rounded bg-warning-50 p-3"><div class="mb-2 flex flex-wrap items-center gap-2 text-xs"><span class="text-neutral-500">{{ t('automation.override_suggested') }}</span><strong class="font-mono">{{ item.debit_account_code }}/{{ item.credit_account_code }}</strong><span>→</span><strong class="font-mono text-primary-700">{{ overrides[item.id].debit || '—' }}/{{ overrides[item.id].credit || '—' }}</strong></div><div class="grid grid-cols-2 gap-2"><label class="text-xs text-neutral-600">{{ t('bank.posting.debit') }}<input v-model="overrides[item.id].debit" class="mt-1 block w-full rounded border border-neutral-300 px-3 py-2 font-mono text-sm" :class="overrides[item.id].debit !== item.debit_account_code ? 'border-warning-500 bg-surface' : ''"></label><label class="text-xs text-neutral-600">{{ t('bank.posting.credit') }}<input v-model="overrides[item.id].credit" class="mt-1 block w-full rounded border border-neutral-300 px-3 py-2 font-mono text-sm" :class="overrides[item.id].credit !== item.credit_account_code ? 'border-warning-500 bg-surface' : ''"></label></div><button type="button" :disabled="busy || !overrides[item.id].debit.trim() || !overrides[item.id].credit.trim() || !overrideChanged(item)" class="mt-2 w-full rounded bg-primary-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-40" @click="approveOverride(item)">✓ {{ t('automation.approve_override') }}</button></div>
      </article></template>
    </div>
  </div>
</template>
