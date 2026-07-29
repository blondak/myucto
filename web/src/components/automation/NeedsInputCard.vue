<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { automationApi, normalizeAutomationSource, type AutomationFeedItem, type AutomationProvenance } from '@/api/automation'
import WhyChip from './WhyChip.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ item: AutomationFeedItem }>()
const emit = defineEmits<{ resolved: []; snooze: []; inspect: [] }>()
const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const working = ref(false)
const selectedRuleId = ref<number | null>(props.item.rule_id ?? props.item.conflict_rules?.[0]?.id ?? null)
const reason = computed(() => props.item.period_closed ? 'period_closed' : (props.item.note ?? 'document_not_posted').split(':')[0])
const snoozed = computed(() => !!props.item.snoozed_until && props.item.snoozed_until >= new Date().toISOString().slice(0, 19).replace('T', ' '))
const provenance = computed<AutomationProvenance>(() => ({
  source: normalizeAutomationSource(props.item.source), mode: 'approved', confidence: props.item.confidence,
  detector: props.item.detector, rule_id: props.item.rule_id, rule_name: props.item.rule_name,
  rule_approved_streak: props.item.rule_approved_streak,
  suggestion_id: props.item.refs.suggestion_id, decided_at: props.item.date, decided_by: null,
}))
const cta = computed(() => ({
  document_not_posted: 'book_document', period_closed: 'open_periods', rule_conflict: 'pick_rule',
  duplicate_suspect: 'compare', missing_document: 'request_document', advance_settlement_ambiguous: 'review',
  rule_disabled: 'review_rule',
} as Record<string, string>)[reason.value])

async function act() {
  if (!cta.value || !props.item.can_write) return
  if (reason.value === 'period_closed') return void router.push('/accounting/periods')
  if (reason.value === 'rule_disabled') return void router.push({ path: '/automation', query: { tab: 'rules', rule: String(props.item.rule_id) } })
  if (reason.value === 'advance_settlement_ambiguous') return void navigateDocument()
  if (reason.value === 'missing_document') return void router.push({ path: '/document-requests', query: { bank_transaction_id: String(props.item.refs.bank_transaction_id ?? '') } })
  if (reason.value === 'rule_conflict' || reason.value === 'duplicate_suspect') return
  working.value = true
  try {
    if (props.item.kind === 'unbooked_invoice') await automationApi.bookInvoice(props.item)
    else if (props.item.kind === 'unbooked_purchase') await automationApi.bookPurchase(props.item)
    toast.success(t('common.saved'))
    emit('resolved')
  } catch { toast.error(t('common.error')) } finally { working.value = false }
}
async function resolveSuggestion(action: 'approve' | 'reject') {
  if (!props.item.can_write || props.item.period_closed) return
  working.value = true
  try {
    if (action === 'approve') {
      await automationApi.approve(props.item, {}, reason.value === 'rule_conflict' ? selectedRuleId.value ?? undefined : undefined)
    } else {
      await automationApi.reject(props.item)
    }
    toast.success(t('common.saved'))
    emit('resolved')
  } catch { toast.error(t('common.error')) } finally { working.value = false }
}
function navigateDocument() {
  if (props.item.refs.invoice_id) router.push(`/invoices/${props.item.refs.invoice_id}`)
  else if (props.item.refs.purchase_invoice_id) router.push(`/purchase-invoices/${props.item.refs.purchase_invoice_id}`)
}
</script>

<template>
  <article class="rounded-lg border bg-surface p-4 shadow-sm" :class="[reason === 'anomaly' ? 'border-warning-500 bg-warning-50/50' : 'border-danger-500/30', snoozed ? 'opacity-65' : '']">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2"><span v-if="item.period_closed">🔒</span><strong>{{ item.description }}</strong><WhyChip v-if="item.kind === 'bank_suggestion' || item.kind === 'rule_disabled'" :provenance="provenance" /></div>
        <p class="mt-2 text-sm text-neutral-700">{{ item.kind === 'rule_disabled' ? t('automation.rule_disabled_row', { name: item.rule_name }) : t(`automation.reason.${reason}`) }}</p>
        <p class="mt-1 text-xs text-neutral-500">{{ item.supplier_name }} · {{ item.date }}</p>
      </div>
      <div class="flex flex-wrap justify-end gap-2"><button type="button" class="rounded border border-neutral-300 px-3 py-2 text-sm" @click="emit('inspect')">↗ {{ t('automation.source_detail') }}</button><button v-if="item.refs.suggestion_id" type="button" :disabled="working || !item.can_write" class="rounded border border-neutral-300 px-3 py-2 text-sm disabled:opacity-40" @click="emit('snooze')">⏰ {{ snoozed ? t('automation.unsnooze') : t('automation.snooze') }}</button><button v-if="cta && reason !== 'rule_conflict' && reason !== 'duplicate_suspect'" type="button" :disabled="working || !item.can_write" @click="act"
        class="cursor-pointer rounded-md bg-warning-500 px-3 py-2 text-sm font-medium text-white disabled:opacity-50 whitespace-nowrap">
        {{ t(`automation.cta.${cta}`) }}
      </button></div>
    </div>
    <div v-if="reason === 'rule_conflict' && item.conflict_rules?.length" class="mt-4 space-y-2 rounded-md border border-warning-500/30 bg-warning-50 p-3">
      <label v-for="rule in item.conflict_rules" :key="rule.id" class="flex cursor-pointer items-center justify-between gap-3 rounded bg-surface px-3 py-2 text-sm">
        <span class="flex items-center gap-2"><input v-model="selectedRuleId" type="radio" :value="rule.id"><strong>{{ rule.name }}</strong></span>
        <span class="font-mono">{{ rule.debit_account_code }}/{{ rule.credit_account_code }}</span>
      </label>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :disabled="working || !selectedRuleId" class="rounded bg-primary-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-40" @click="resolveSuggestion('approve')">✓ {{ t('automation.use_selected_rule') }}</button>
        <button type="button" :disabled="working" class="rounded border border-neutral-300 px-3 py-2 text-sm" @click="resolveSuggestion('reject')">✕ {{ t('bank.posting.action_reject') }}</button>
      </div>
    </div>
    <div v-if="reason === 'duplicate_suspect' && item.duplicate_entry" class="mt-4 rounded-md border border-warning-500/30 bg-warning-50 p-3">
      <div class="grid gap-3 text-sm md:grid-cols-2">
        <div><div class="text-xs font-medium uppercase text-neutral-500">{{ t('automation.suggested_entry') }}</div><div class="mt-1 font-mono">{{ item.debit_account_code }}/{{ item.credit_account_code }} · {{ item.amount }} {{ item.currency }}</div></div>
        <div><div class="text-xs font-medium uppercase text-neutral-500">{{ t('automation.existing_entry') }}</div><RouterLink :to="{ path: '/accounting/journal', query: { entry_id: item.duplicate_entry.journal_entry_id } }" class="mt-1 inline-block font-mono text-primary-700">{{ item.duplicate_entry.debit_account_code }}/{{ item.duplicate_entry.credit_account_code }} · {{ item.duplicate_entry.amount }} {{ item.currency }}</RouterLink><div class="text-xs text-neutral-500">{{ item.duplicate_entry.entry_date }} · {{ item.duplicate_entry.document_no || '—' }}</div></div>
      </div>
      <div class="mt-3 flex flex-wrap justify-end gap-2">
        <button type="button" :disabled="working" class="rounded bg-warning-500 px-3 py-2 text-sm font-medium text-white" @click="resolveSuggestion('approve')">✓ {{ t('automation.approve_anyway') }}</button>
        <button type="button" :disabled="working" class="rounded border border-neutral-300 px-3 py-2 text-sm" @click="resolveSuggestion('reject')">✕ {{ t('automation.already_covered') }}</button>
      </div>
    </div>
  </article>
</template>
