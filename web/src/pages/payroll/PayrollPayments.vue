<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollRun } from '@/api/payroll'
import {
  payrollPaymentsApi,
  type PayrollPayerOption,
  type PayrollPaymentAllocation,
  type PayrollPaymentBatch,
  type PayrollPaymentEvidence,
  type PayrollPaymentExport,
  type PayrollPaymentLiability,
  type PayrollPaymentLiabilityState,
  type PayrollPaymentMatch,
} from '@/api/payrollPayments'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import {
  btnFilled,
  btnFilledSm,
  btnOutline,
  btnOutlineSm,
  ICONS,
} from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { localPayrollPeriod } from './payrollComponentsUi'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const period = ref(localPayrollPeriod())
const activeTab = ref<'liabilities' | 'batches' | 'settlements'>('liabilities')
const loading = ref(true)
const materializing = ref(false)
const creatingBatch = ref(false)
const generatingBatchId = ref<number | null>(null)
const downloadingExportId = ref<number | null>(null)
const items = ref<PayrollPaymentLiability[]>([])
const runs = ref<PayrollRun[]>([])
const payerOptions = ref<PayrollPayerOption[]>([])
const batches = ref<PayrollPaymentBatch[]>([])
const allocations = ref<PayrollPaymentAllocation[]>([])
const paymentMatches = ref<PayrollPaymentMatch[]>([])
const bankEvidence = ref<PayrollPaymentEvidence[]>([])
const cashEvidence = ref<PayrollPaymentEvidence[]>([])
const selectedIds = ref<number[]>([])
const exportFormat = ref<'abo' | 'sepa' | 'manual' | null>(null)
const payerReference = ref<string | null>(null)
const selectedAllocationId = ref<number | null>(null)
const selectedMatchEvidence = ref<string | null>(null)
const matchAmount = ref('')
const matching = ref(false)
const selectedSourceMatchId = ref<number | null>(null)
const selectedReversalEvidence = ref<string | null>(null)
const reversalAmount = ref('')
const reversing = ref(false)
let loadSequence = 0
const pendingExportKeys = new Map<number, string>()
const pendingReconciliationKeys = new Map<string, string>()

const materializableRevisions = computed(() => {
  const seen = new Set<number>()
  return runs.value.filter(run => {
    if (run.revision_id === null || seen.has(run.revision_id)) return false
    if (!['approved', 'posted', 'payment_ready', 'paid', 'closed'].includes(run.status)) return false
    if (!run.payment_materialization_supported) return false
    seen.add(run.revision_id)
    return true
  })
})
const canMaterialize = computed(() =>
  auth.canWrite('payroll.payments') && materializableRevisions.value.length > 0,
)
const totals = computed(() => items.value.reduce(
  (value, item) => ({
    amount: value.amount + signed(item, item.amount_minor),
    allocated: value.allocated + signed(item, item.allocated_minor),
    settled: value.settled + signed(item, item.settled_minor),
  }),
  { amount: 0, allocated: 0, settled: 0 },
))
const selectedItems = computed(() => {
  const ids = new Set(selectedIds.value)
  return items.value.filter(item => ids.has(item.id))
})
const selectionAnchor = computed(() => selectedItems.value[0] ?? null)
const selectedTotalMinor = computed(() => selectedItems.value.reduce(
  (sum, item) => sum + remainingMinor(item),
  0,
))
const payerSelectOptions = computed(() => {
  const anchor = selectionAnchor.value
  if (!anchor) return []
  if (anchor.recipient_kind === 'cash') {
    return [{
      value: 'cash',
      label: t('payroll.payments.batch.cash_payer'),
      secondary: t('payroll.payments.recipient.cash'),
    }]
  }
  return payerOptions.value
    .filter(option =>
      option.currency_code === anchor.currency_code
      && exportFormat.value !== null
      && exportFormat.value !== 'manual'
      && option.export_formats.includes(exportFormat.value),
    )
    .map(option => ({
      value: option.reference,
      label: [option.bank_name, option.masked_account].filter(Boolean).join(' · '),
      secondary: option.currency_code,
    }))
})
const formatSelectOptions = computed(() => {
  const anchor = selectionAnchor.value
  if (!anchor) return []
  if (anchor.recipient_kind === 'cash') {
    return [{
      value: 'manual' as const,
      label: t('payroll.payments.batch.format.manual'),
    }]
  }
  if (anchor.currency_code === 'CZK') {
    return [{
      value: 'abo' as const,
      label: t('payroll.payments.batch.format.abo'),
    }]
  }
  if (anchor.currency_code === 'EUR') {
    return [{
      value: 'sepa' as const,
      label: t('payroll.payments.batch.format.sepa'),
    }]
  }
  return []
})
const canCreateBatch = computed(() =>
  auth.canWrite('payroll.payments')
  && selectedItems.value.length > 0
  && exportFormat.value !== null
  && payerReference.value !== null,
)
const selectedAllocation = computed(() =>
  allocations.value.find(item => item.id === selectedAllocationId.value)
  ?? null,
)
const reversibleMatches = computed(() => paymentMatches.value.filter(
  item => item.event_kind === 'matched' && item.reversible_minor > 0,
))
const selectedSourceMatch = computed(() =>
  reversibleMatches.value.find(item => item.id === selectedSourceMatchId.value)
  ?? null,
)
const matchEvidenceCandidates = computed(() => {
  const allocation = selectedAllocation.value
  if (!allocation) return []
  const evidence = allocation.channel === 'bank'
    ? bankEvidence.value
    : cashEvidence.value
  return evidence.filter(item =>
    item.currency_code === allocation.currency_code
    && item.direction === allocation.direction
    && item.available_match_minor > 0
    && (item.kind !== 'cash' || item.status === 'posted'),
  )
})
const reversalEvidenceCandidates = computed(() => {
  const match = selectedSourceMatch.value
  if (!match) return []
  const allocation = allocations.value.find(
    item => item.id === match.allocation_id,
  )
  if (!allocation) return []
  if (match.evidence_kind === 'cash') {
    return cashEvidence.value.filter(item =>
      item.cash_document_id === match.cash_document_id
      && item.status === 'reversed'
      && item.available_reversal_minor > 0,
    )
  }
  const direction = allocation.direction === 'outgoing'
    ? 'incoming'
    : 'outgoing'
  return bankEvidence.value.filter(item =>
    item.currency_code === allocation.currency_code
    && item.direction === direction
    && item.available_reversal_minor > 0,
  )
})
const allocationSelectOptions = computed(() => allocations.value
  .filter(item => item.remaining_minor > 0)
  .map(item => ({
    value: item.id,
    label: item.employee_name || t('payroll.payments.company'),
    secondary: `${kindLabel(item.liability_kind)} · ${formatMoney(
      item.remaining_minor,
      item.currency_code,
    )}`,
  })))
const matchEvidenceSelectOptions = computed(() =>
  matchEvidenceCandidates.value.map(evidenceOption),
)
const sourceMatchSelectOptions = computed(() => reversibleMatches.value.map(
  item => ({
    value: item.id,
    label: item.employee_name || t('payroll.payments.company'),
    secondary: `${formatDate(item.actual_payment_date)} · ${formatMoney(
      item.reversible_minor,
      item.evidence_currency_code,
    )}`,
  }),
))
const reversalEvidenceSelectOptions = computed(() =>
  reversalEvidenceCandidates.value.map(evidenceOption),
)
const selectedMatchEvidenceItem = computed(() =>
  matchEvidenceCandidates.value.find(
    item => evidenceKey(item) === selectedMatchEvidence.value,
  ) ?? null,
)
const selectedReversalEvidenceItem = computed(() =>
  reversalEvidenceCandidates.value.find(
    item => evidenceKey(item) === selectedReversalEvidence.value,
  ) ?? null,
)
const matchLimitMinor = computed(() => Math.min(
  selectedAllocation.value?.remaining_minor ?? 0,
  selectedMatchEvidenceItem.value?.available_match_minor ?? 0,
))
const reversalLimitMinor = computed(() => Math.min(
  selectedSourceMatch.value?.reversible_minor ?? 0,
  selectedReversalEvidenceItem.value?.available_reversal_minor ?? 0,
))
const canMatch = computed(() =>
  auth.canWrite('payroll.payments')
  && selectedAllocation.value !== null
  && selectedMatchEvidenceItem.value !== null
  && parseMinor(matchAmount.value) > 0
  && parseMinor(matchAmount.value) <= matchLimitMinor.value,
)
const canReverse = computed(() =>
  auth.canWrite('payroll.payments')
  && selectedSourceMatch.value !== null
  && selectedReversalEvidenceItem.value !== null
  && parseMinor(reversalAmount.value) > 0
  && parseMinor(reversalAmount.value) <= reversalLimitMinor.value,
)

function signed(item: PayrollPaymentLiability, amount: number): number {
  return item.direction === 'incoming' ? -amount : amount
}

function remainingMinor(item: PayrollPaymentLiability): number {
  return Math.max(0, item.amount_minor - item.allocated_minor)
}

function isSelectable(item: PayrollPaymentLiability): boolean {
  if (
    item.direction !== 'outgoing'
    || item.liability_kind !== 'net_wage'
    || !['open', 'partially_batched'].includes(item.state)
    || remainingMinor(item) <= 0
  ) return false
  const anchor = selectionAnchor.value
  return !anchor
    || anchor.id === item.id
    || (
      anchor.due_on === item.due_on
      && anchor.currency_code === item.currency_code
      && anchor.recipient_kind === item.recipient_kind
    )
}

function toggleSelection(item: PayrollPaymentLiability): void {
  if (!isSelectable(item) && !selectedIds.value.includes(item.id)) return
  selectedIds.value = selectedIds.value.includes(item.id)
    ? selectedIds.value.filter(id => id !== item.id)
    : [...selectedIds.value, item.id]
}

function toggleAll(): void {
  if (selectedIds.value.length > 0) {
    selectedIds.value = []
    return
  }
  const first = items.value.find(item => isSelectable(item))
  if (!first) return
  selectedIds.value = [first.id]
  selectedIds.value = items.value
    .filter(item => isSelectable(item))
    .map(item => item.id)
}

function isSelected(id: number): boolean {
  return selectedIds.value.includes(id)
}

function formatMoney(amountMinor: number, currencyCode = 'CZK'): string {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: currencyCode,
  }).format(amountMinor / 100)
}

function formatDate(value: string): string {
  const parsed = new Date(`${value}T00:00:00`)
  return Number.isNaN(parsed.getTime())
    ? value
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(parsed)
}

function formatDateTime(value: string): string {
  const parsed = new Date(value.replace(' ', 'T'))
  return Number.isNaN(parsed.getTime())
    ? value
    : new Intl.DateTimeFormat(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(parsed)
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  return `${new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 1,
  }).format(bytes / 1024)} kB`
}

function evidenceKey(evidence: PayrollPaymentEvidence): string {
  return evidence.kind === 'bank'
    ? `bank:${evidence.bank_statement_id}:${evidence.bank_transaction_id}`
    : `cash:${evidence.cash_document_id}`
}

function evidenceOption(evidence: PayrollPaymentEvidence) {
  return {
    value: evidenceKey(evidence),
    label: `${formatDate(evidence.date)} · ${formatMoney(
      evidence.amount_minor,
      evidence.currency_code,
    )}`,
    secondary: evidence.description
      || evidence.reference
      || t(`payroll.payments.recipient.${evidence.kind}`),
  }
}

function parseMinor(value: string): number {
  const normalized = value.trim().replace(/\s+/g, '').replace(',', '.')
  const match = normalized.match(/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/)
  if (!match) return 0
  const whole = Number(match[1])
  const fraction = Number((match[2] || '').padEnd(2, '0'))
  if (!Number.isSafeInteger(whole) || !Number.isSafeInteger(fraction)) return 0
  const minor = (whole * 100) + fraction
  return Number.isSafeInteger(minor) ? minor : 0
}

function minorInput(amountMinor: number): string {
  const whole = Math.floor(amountMinor / 100)
  const fraction = String(amountMinor % 100).padStart(2, '0')
  return `${whole},${fraction}`
}

function evidencePayload(evidence: PayrollPaymentEvidence) {
  if (evidence.kind === 'bank') {
    return {
      kind: 'bank' as const,
      bank_statement_id: evidence.bank_statement_id!,
      bank_transaction_id: evidence.bank_transaction_id!,
    }
  }
  return {
    kind: 'cash' as const,
    cash_document_id: evidence.cash_document_id!,
  }
}

function reconciliationKey(scope: string): string {
  const existing = pendingReconciliationKeys.get(scope)
  if (existing) return existing
  const random = globalThis.crypto?.randomUUID?.()
    ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
  const key = `payroll-${scope}-${random}`
  pendingReconciliationKeys.set(scope, key)
  return key
}

function kindLabel(kind: string): string {
  const key = `payroll.payments.kind.${kind}`
  const translated = t(key)
  return translated === key ? kind : translated
}

function stateLabel(state: PayrollPaymentLiabilityState): string {
  return t(`payroll.payments.state.${state}`)
}

function stateClass(state: PayrollPaymentLiabilityState): string {
  if (state === 'settled') return 'bg-success-50 text-success-700'
  if (state === 'partially_settled') return 'bg-warning-50 text-warning-700'
  if (state === 'batched' || state === 'partially_batched') {
    return 'bg-payroll-50 text-payroll-700'
  }
  return 'bg-neutral-100 text-neutral-700'
}

async function load(): Promise<void> {
  const sequence = ++loadSequence
  const requestedPeriod = period.value
  loading.value = true
  try {
    const [
      liabilityList,
      runList,
      payerList,
      batchList,
      reconciliation,
    ] = await Promise.all([
      payrollPaymentsApi.liabilities(requestedPeriod),
      payrollApi.runs(requestedPeriod),
      payrollPaymentsApi.payerOptions(),
      payrollPaymentsApi.batches(requestedPeriod),
      payrollPaymentsApi.reconciliation(requestedPeriod),
    ])
    if (sequence === loadSequence && requestedPeriod === period.value) {
      items.value = liabilityList.items
      runs.value = runList
      payerOptions.value = payerList
      batches.value = batchList.items
      allocations.value = reconciliation.allocations
      paymentMatches.value = reconciliation.matches
      bankEvidence.value = reconciliation.bank_evidence
      cashEvidence.value = reconciliation.cash_evidence
      if (!reconciliation.allocations.some(
        item => item.id === selectedAllocationId.value,
      )) {
        selectedAllocationId.value = null
      }
      if (!reconciliation.matches.some(
        item => item.id === selectedSourceMatchId.value,
      )) {
        selectedSourceMatchId.value = null
      }
      selectedIds.value = selectedIds.value.filter(id =>
        liabilityList.items.some(item => item.id === id),
      )
    }
  } catch (error) {
    if (sequence === loadSequence) {
      items.value = []
      runs.value = []
      payerOptions.value = []
      batches.value = []
      allocations.value = []
      paymentMatches.value = []
      bankEvidence.value = []
      cashEvidence.value = []
      selectedIds.value = []
      toast.error(apiErrorMessage(error, t('payroll.payments.load_failed')))
    }
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

async function createBatch(): Promise<void> {
  if (!canCreateBatch.value || creatingBatch.value) return
  creatingBatch.value = true
  try {
    const result = await payrollPaymentsApi.createBatch({
      export_format: exportFormat.value!,
      payer_reference: payerReference.value!,
      items: selectedItems.value.map(item => ({
        liability_id: item.id,
        amount_minor: remainingMinor(item),
      })),
    })
    toast.success(t('payroll.payments.batch.created', {
      count: result.declared_item_count,
    }))
    selectedIds.value = []
    activeTab.value = 'batches'
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.batch.create_failed'),
    ))
  } finally {
    creatingBatch.value = false
  }
}

function idempotencyKey(batchId: number): string {
  const pending = pendingExportKeys.get(batchId)
  if (pending) return pending
  const random = globalThis.crypto?.randomUUID?.()
    ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
  const key = `payroll-export-${batchId}-${random}`
  pendingExportKeys.set(batchId, key)
  return key
}

async function generateExport(batch: PayrollPaymentBatch): Promise<void> {
  if (
    !auth.canWrite('payroll.payments')
    || batch.export_format === 'manual'
    || generatingBatchId.value !== null
  ) return
  generatingBatchId.value = batch.id
  try {
    await payrollPaymentsApi.generateExport(
      batch.id,
      idempotencyKey(batch.id),
    )
    pendingExportKeys.delete(batch.id)
    toast.success(t('payroll.payments.batch.export_created'))
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.batch.export_failed'),
    ))
  } finally {
    generatingBatchId.value = null
  }
}

async function downloadExport(file: PayrollPaymentExport): Promise<void> {
  if (downloadingExportId.value !== null) return
  downloadingExportId.value = file.id
  try {
    const grant = await payrollPaymentsApi.createDownloadGrant(file.id)
    const blob = await payrollPaymentsApi.downloadExport(grant.token)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = file.suggested_filename
    link.click()
    URL.revokeObjectURL(url)
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.batch.download_failed'),
    ))
  } finally {
    downloadingExportId.value = null
  }
}

async function materialize(): Promise<void> {
  if (!canMaterialize.value || materializing.value) return
  materializing.value = true
  let created = 0
  let succeeded = 0
  const failures: unknown[] = []
  for (const run of materializableRevisions.value) {
    if (run.revision_id === null) continue
    try {
      created += (await payrollPaymentsApi.materializeNetWages(run.revision_id)).created_count
      succeeded += 1
    } catch (error) {
      failures.push(error)
    }
  }
  try {
    if (succeeded > 0) {
      toast.success(t(
        created > 0
          ? 'payroll.payments.materialized'
          : 'payroll.payments.materialized_replay',
        { count: created },
      ))
    }
    if (failures.length > 0) {
      const detail = apiErrorMessage(
        failures[0],
        t('payroll.payments.materialize_failed'),
      )
      toast.error(t(
        'payroll.payments.materialize_partial_failed',
        { count: failures.length, detail },
      ))
    }
    if (succeeded > 0) {
      await load()
    }
  } finally {
    materializing.value = false
  }
}

async function matchPayment(): Promise<void> {
  const allocation = selectedAllocation.value
  const evidence = selectedMatchEvidenceItem.value
  const amountMinor = parseMinor(matchAmount.value)
  if (!allocation || !evidence || !canMatch.value || matching.value) return
  const scope = `match-${allocation.id}-${evidenceKey(evidence)}-${amountMinor}`
  matching.value = true
  try {
    await payrollPaymentsApi.match({
      allocation_id: allocation.id,
      amount_minor: amountMinor,
      evidence: evidencePayload(evidence),
      idempotency_key: reconciliationKey(scope),
    })
    pendingReconciliationKeys.delete(scope)
    toast.success(t('payroll.payments.settlements.match_success'))
    selectedAllocationId.value = null
    selectedMatchEvidence.value = null
    matchAmount.value = ''
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.settlements.match_failed'),
    ))
  } finally {
    matching.value = false
  }
}

async function reversePayment(): Promise<void> {
  const source = selectedSourceMatch.value
  const evidence = selectedReversalEvidenceItem.value
  const amountMinor = parseMinor(reversalAmount.value)
  if (!source || !evidence || !canReverse.value || reversing.value) return
  const scope = `reverse-${source.id}-${evidenceKey(evidence)}-${amountMinor}`
  reversing.value = true
  try {
    await payrollPaymentsApi.reverse({
      source_match_id: source.id,
      amount_minor: amountMinor,
      evidence: evidencePayload(evidence),
      idempotency_key: reconciliationKey(scope),
    })
    pendingReconciliationKeys.delete(scope)
    toast.success(t('payroll.payments.settlements.reverse_success'))
    selectedSourceMatchId.value = null
    selectedReversalEvidence.value = null
    reversalAmount.value = ''
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.payments.settlements.reverse_failed'),
    ))
  } finally {
    reversing.value = false
  }
}

watch(selectedIds, () => {
  const anchor = selectionAnchor.value
  if (!anchor) {
    exportFormat.value = null
    payerReference.value = null
    return
  }
  const nextFormat = anchor.recipient_kind === 'cash'
    ? 'manual'
    : anchor.currency_code === 'CZK'
      ? 'abo'
      : anchor.currency_code === 'EUR'
        ? 'sepa'
        : null
  if (!formatSelectOptions.value.some(option =>
    option.value === exportFormat.value,
  )) {
    exportFormat.value = nextFormat
  }
  const options = payerSelectOptions.value
  if (!options.some(option => option.value === payerReference.value)) {
    payerReference.value = options[0]?.value ?? null
  }
}, { deep: true })

watch(exportFormat, () => {
  if (!payerSelectOptions.value.some(option =>
    option.value === payerReference.value,
  )) {
    payerReference.value = payerSelectOptions.value[0]?.value ?? null
  }
})

watch([selectedAllocationId, selectedMatchEvidence], () => {
  const options = matchEvidenceCandidates.value
  if (!options.some(
    item => evidenceKey(item) === selectedMatchEvidence.value,
  )) {
    selectedMatchEvidence.value = options[0]
      ? evidenceKey(options[0])
      : null
    return
  }
  matchAmount.value = matchLimitMinor.value > 0
    ? minorInput(matchLimitMinor.value)
    : ''
})

watch([selectedSourceMatchId, selectedReversalEvidence], () => {
  const options = reversalEvidenceCandidates.value
  if (!options.some(
    item => evidenceKey(item) === selectedReversalEvidence.value,
  )) {
    selectedReversalEvidence.value = options[0]
      ? evidenceKey(options[0])
      : null
    return
  }
  reversalAmount.value = reversalLimitMinor.value > 0
    ? minorInput(reversalLimitMinor.value)
    : ''
})

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.payments.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.payments.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.payments.period') }}</span>
          <input
            v-model="period"
            type="month"
            min="2024-01"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:ring-payroll-500/20"
            @change="load"
          >
        </label>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.payments.reload') }}
        </button>
        <button
          v-if="auth.canWrite('payroll.payments')"
          type="button"
          :class="btnFilled('primary')"
          :disabled="!canMaterialize || materializing"
          @click="materialize"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.coin" />
          </svg>
          {{ materializing ? t('payroll.payments.materializing') : t('payroll.payments.materialize') }}
        </button>
      </div>
    </header>

    <nav class="flex gap-1 overflow-x-auto border-b border-neutral-200" :aria-label="t('payroll.payments.tabs_label')">
      <button
        v-for="tab in (['liabilities', 'batches', 'settlements'] as const)"
        :key="tab"
        type="button"
        class="whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors"
        :class="activeTab === tab
          ? 'border-payroll-500 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="activeTab = tab"
      >
        {{ t(`payroll.payments.tabs.${tab}`) }}
      </button>
    </nav>

    <template v-if="activeTab === 'liabilities'">
      <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('payroll.payments.total_liabilities') }}</p>
          <p class="mt-2 text-xl font-semibold text-neutral-900">{{ formatMoney(totals.amount) }}</p>
        </article>
        <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('payroll.payments.total_batched') }}</p>
          <p class="mt-2 text-xl font-semibold text-payroll-700">{{ formatMoney(totals.allocated) }}</p>
        </article>
        <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('payroll.payments.total_settled') }}</p>
          <p class="mt-2 text-xl font-semibold text-success-700">{{ formatMoney(totals.settled) }}</p>
        </article>
      </section>

      <section
        v-if="selectedItems.length > 0"
        class="rounded-xl border border-payroll-200 bg-payroll-50/40 p-5 shadow-sm"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold text-neutral-900">
              {{ t('payroll.payments.batch.new_title') }}
            </h2>
            <p class="mt-1 text-sm text-neutral-600">
              {{ t('payroll.payments.batch.selection_summary', {
                count: selectedItems.length,
                amount: formatMoney(
                  selectedTotalMinor,
                  selectionAnchor?.currency_code || 'CZK',
                ),
              }) }}
            </p>
          </div>
          <button type="button" :class="btnOutline('neutral')" @click="selectedIds = []">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.x" />
            </svg>
            {{ t('payroll.payments.batch.clear_selection') }}
          </button>
        </div>
        <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_auto] lg:items-end">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.payments.batch.export_format') }}
            </span>
            <SearchableSelect
              v-model="exportFormat"
              :options="formatSelectOptions"
              :clearable="false"
              accent="payroll"
              :placeholder="t('payroll.payments.batch.select_format')"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.payments.batch.payer_account') }}
            </span>
            <SearchableSelect
              v-model="payerReference"
              :options="payerSelectOptions"
              :clearable="false"
              accent="payroll"
              :placeholder="t('payroll.payments.batch.select_payer')"
              :no-results-label="t('payroll.payments.batch.no_payer')"
            />
          </label>
          <button
            type="button"
            :class="btnFilled('primary')"
            :disabled="!canCreateBatch || creatingBatch"
            @click="createBatch"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.plus" />
            </svg>
            {{ creatingBatch
              ? t('payroll.payments.batch.creating')
              : t('payroll.payments.batch.create') }}
          </button>
        </div>
        <p
          v-if="payerSelectOptions.length === 0"
          class="mt-3 text-sm text-warning-700"
        >
          {{ t('payroll.payments.batch.no_payer_hint') }}
        </p>
      </section>

      <div v-if="loading" class="space-y-3">
        <div v-for="index in 4" :key="index" class="h-20 animate-pulse rounded-xl bg-neutral-100" />
      </div>
      <section v-else-if="items.length === 0" class="rounded-xl border border-dashed border-neutral-300 bg-surface px-5 py-12 text-center">
        <svg class="mx-auto h-10 w-10 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path :d="ICONS.coin" />
        </svg>
        <h2 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.payments.empty') }}</h2>
        <p class="mx-auto mt-1 max-w-xl text-sm text-neutral-500">
          {{ materializableRevisions.length ? t('payroll.payments.empty_ready') : t('payroll.payments.empty_blocked') }}
        </p>
      </section>

      <template v-else>
        <section data-layout="desktop" class="hidden overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm md:block">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th class="w-12 px-4 py-3">
                    <input
                      type="checkbox"
                      class="h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
                      :checked="selectedIds.length > 0"
                      :aria-label="t('payroll.payments.batch.select_all')"
                      @change="toggleAll"
                    >
                  </th>
                  <th class="px-4 py-3">{{ t('payroll.payments.employee') }}</th>
                  <th class="px-4 py-3">{{ t('payroll.payments.kind_label') }}</th>
                  <th class="px-4 py-3">{{ t('payroll.payments.destination') }}</th>
                  <th class="px-4 py-3">{{ t('payroll.payments.due_on') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('payroll.payments.amount') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('payroll.payments.settled') }}</th>
                  <th class="px-4 py-3">{{ t('payroll.payments.status') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="item in items" :key="item.id">
                  <td class="px-4 py-3">
                    <input
                      type="checkbox"
                      class="h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500 disabled:cursor-not-allowed disabled:opacity-40"
                      :checked="isSelected(item.id)"
                      :disabled="!isSelected(item.id) && !isSelectable(item)"
                      :aria-label="t('payroll.payments.batch.select_employee', {
                        name: item.employee_name || t('payroll.payments.company'),
                      })"
                      @change="toggleSelection(item)"
                    >
                  </td>
                  <td class="px-4 py-3 font-medium text-neutral-900">{{ item.employee_name || t('payroll.payments.company') }}</td>
                  <td class="px-4 py-3 text-neutral-700">{{ kindLabel(item.liability_kind) }}</td>
                  <td class="px-4 py-3 text-neutral-600">{{ t(`payroll.payments.recipient.${item.recipient_kind}`) }}</td>
                  <td class="whitespace-nowrap px-4 py-3 text-neutral-600">{{ formatDate(item.due_on) }}</td>
                  <td class="whitespace-nowrap px-4 py-3 text-right font-medium" :class="item.direction === 'incoming' ? 'text-success-700' : 'text-neutral-900'">
                    {{ formatMoney(signed(item, item.amount_minor), item.currency_code) }}
                  </td>
                  <td class="whitespace-nowrap px-4 py-3 text-right text-neutral-600">{{ formatMoney(signed(item, item.settled_minor), item.currency_code) }}</td>
                  <td class="px-4 py-3">
                    <span class="rounded-full px-2 py-1 text-xs font-medium" :class="stateClass(item.state)">{{ stateLabel(item.state) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section data-layout="mobile" class="grid grid-cols-1 gap-3 md:hidden">
          <article v-for="item in items" :key="item.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="flex min-w-0 items-start gap-3">
                <input
                  type="checkbox"
                  class="mt-1 h-4 w-4 shrink-0 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500 disabled:cursor-not-allowed disabled:opacity-40"
                  :checked="isSelected(item.id)"
                  :disabled="!isSelected(item.id) && !isSelectable(item)"
                  :aria-label="t('payroll.payments.batch.select_employee', {
                    name: item.employee_name || t('payroll.payments.company'),
                  })"
                  @change="toggleSelection(item)"
                >
                <div class="min-w-0">
                <h2 class="truncate font-semibold text-neutral-900">{{ item.employee_name || t('payroll.payments.company') }}</h2>
                <p class="mt-1 text-sm text-neutral-600">{{ kindLabel(item.liability_kind) }}</p>
                </div>
              </div>
              <span class="rounded-full px-2 py-1 text-xs font-medium" :class="stateClass(item.state)">{{ stateLabel(item.state) }}</span>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.payments.destination') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ t(`payroll.payments.recipient.${item.recipient_kind}`) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.payments.due_on') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatDate(item.due_on) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.payments.amount') }}</dt>
                <dd class="mt-0.5 font-semibold text-neutral-900">{{ formatMoney(signed(item, item.amount_minor), item.currency_code) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.payments.settled') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatMoney(signed(item, item.settled_minor), item.currency_code) }}</dd>
              </div>
            </dl>
          </article>
        </section>
      </template>
    </template>

    <template v-else-if="activeTab === 'batches'">
      <div v-if="loading" class="space-y-3">
        <div v-for="index in 3" :key="index" class="h-24 animate-pulse rounded-xl bg-neutral-100" />
      </div>
      <section
        v-else-if="batches.length === 0"
        class="rounded-xl border border-dashed border-neutral-300 bg-surface px-5 py-12 text-center"
      >
        <svg class="mx-auto h-10 w-10 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path :d="ICONS.coin" />
        </svg>
        <h2 class="mt-3 font-semibold text-neutral-900">
          {{ t('payroll.payments.batch.empty') }}
        </h2>
        <p class="mx-auto mt-1 max-w-xl text-sm text-neutral-500">
          {{ t('payroll.payments.batch.empty_hint') }}
        </p>
      </section>
      <template v-else>
        <section data-layout="batch-desktop" class="hidden overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm md:block">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th class="px-4 py-3">{{ t('payroll.payments.batch.date') }}</th>
                  <th class="px-4 py-3">{{ t('payroll.payments.batch.format_label') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('payroll.payments.batch.items') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('payroll.payments.batch.total') }}</th>
                  <th class="px-4 py-3">{{ t('payroll.payments.batch.exports') }}</th>
                  <th class="px-4 py-3 text-right">{{ t('payroll.payments.batch.actions') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="batch in batches" :key="batch.id" class="align-top">
                  <td class="whitespace-nowrap px-4 py-3 text-neutral-700">
                    {{ formatDate(batch.planned_payment_date) }}
                  </td>
                  <td class="px-4 py-3">
                    <span class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium uppercase text-payroll-700">
                      {{ batch.export_format }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-right text-neutral-700">
                    {{ batch.declared_item_count }}
                  </td>
                  <td class="whitespace-nowrap px-4 py-3 text-right font-medium text-neutral-900">
                    {{ formatMoney(batch.declared_total_minor, batch.currency_code) }}
                  </td>
                  <td class="px-4 py-3">
                    <div v-if="batch.exports.length" class="space-y-2">
                      <div v-for="file in batch.exports" :key="file.id" class="flex flex-wrap items-center gap-2">
                        <span class="text-neutral-700">
                          {{ t('payroll.payments.batch.revision', { revision: file.revision_no }) }}
                        </span>
                        <span class="text-xs text-neutral-500">
                          {{ formatFileSize(file.size_bytes) }} · {{ formatDateTime(file.created_at) }}
                        </span>
                        <button
                          v-if="auth.canWrite('payroll.payments')"
                          type="button"
                          :class="btnOutlineSm('neutral')"
                          :disabled="downloadingExportId !== null"
                          @click="downloadExport(file)"
                        >
                          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path :d="ICONS.download" />
                          </svg>
                          {{ downloadingExportId === file.id
                            ? t('payroll.payments.batch.downloading')
                            : t('payroll.payments.batch.download') }}
                        </button>
                      </div>
                    </div>
                    <span v-else class="text-neutral-500">
                      {{ batch.export_format === 'manual'
                        ? t('payroll.payments.batch.manual')
                        : t('payroll.payments.batch.no_export') }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-right">
                    <button
                      v-if="batch.export_format !== 'manual' && auth.canWrite('payroll.payments')"
                      type="button"
                      :class="btnFilledSm('primary')"
                      :disabled="generatingBatchId !== null"
                      @click="generateExport(batch)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.plus" />
                      </svg>
                      {{ generatingBatchId === batch.id
                        ? t('payroll.payments.batch.generating')
                        : t('payroll.payments.batch.generate') }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section data-layout="batch-mobile" class="grid grid-cols-1 gap-3 md:hidden">
          <article v-for="batch in batches" :key="batch.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-semibold text-neutral-900">
                  {{ formatMoney(batch.declared_total_minor, batch.currency_code) }}
                </h2>
                <p class="mt-1 text-sm text-neutral-500">
                  {{ formatDate(batch.planned_payment_date) }} ·
                  {{ t('payroll.payments.batch.item_count', { count: batch.declared_item_count }) }}
                </p>
              </div>
              <span class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium uppercase text-payroll-700">
                {{ batch.export_format }}
              </span>
            </div>
            <div v-if="batch.exports.length" class="mt-4 space-y-3">
              <div v-for="file in batch.exports" :key="file.id" class="rounded-lg bg-neutral-50 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <p class="text-sm font-medium text-neutral-800">
                      {{ t('payroll.payments.batch.revision', { revision: file.revision_no }) }}
                    </p>
                    <p class="mt-0.5 text-xs text-neutral-500">
                      {{ formatFileSize(file.size_bytes) }} · {{ formatDateTime(file.created_at) }}
                    </p>
                  </div>
                  <button
                    v-if="auth.canWrite('payroll.payments')"
                    type="button"
                    :class="btnOutlineSm('neutral')"
                    :disabled="downloadingExportId !== null"
                    @click="downloadExport(file)"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.download" />
                    </svg>
                    {{ t('payroll.payments.batch.download') }}
                  </button>
                </div>
              </div>
            </div>
            <p v-else class="mt-4 text-sm text-neutral-500">
              {{ batch.export_format === 'manual'
                ? t('payroll.payments.batch.manual')
                : t('payroll.payments.batch.no_export') }}
            </p>
            <button
              v-if="batch.export_format !== 'manual' && auth.canWrite('payroll.payments')"
              type="button"
              class="mt-4"
              :class="btnFilled('primary')"
              :disabled="generatingBatchId !== null"
              @click="generateExport(batch)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.plus" />
              </svg>
              {{ generatingBatchId === batch.id
                ? t('payroll.payments.batch.generating')
                : t('payroll.payments.batch.generate') }}
            </button>
          </article>
        </section>
      </template>
    </template>

    <template v-else>
      <div v-if="loading" class="space-y-3">
        <div v-for="index in 3" :key="index" class="h-28 animate-pulse rounded-xl bg-neutral-100" />
      </div>
      <template v-else>
        <section class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
          <h2 class="font-semibold text-neutral-900">
            {{ t('payroll.payments.settlements.title') }}
          </h2>
          <p class="mt-1 max-w-3xl text-sm text-neutral-600">
            {{ t('payroll.payments.settlements.foundation') }}
          </p>
        </section>

        <section
          v-if="auth.canWrite('payroll.payments')"
          class="grid grid-cols-1 gap-4 xl:grid-cols-2"
        >
          <form
            class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm"
            @submit.prevent="matchPayment"
          >
            <h2 class="font-semibold text-neutral-900">
              {{ t('payroll.payments.settlements.new_match') }}
            </h2>
            <p class="mt-1 text-sm text-neutral-500">
              {{ t('payroll.payments.settlements.new_match_hint') }}
            </p>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block sm:col-span-2">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.allocation') }}
                </span>
                <SearchableSelect
                  v-model="selectedAllocationId"
                  :options="allocationSelectOptions"
                  accent="payroll"
                  :placeholder="t('payroll.payments.settlements.select_allocation')"
                  :no-results-label="t('payroll.payments.settlements.no_allocations')"
                />
              </label>
              <label class="block sm:col-span-2">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.evidence') }}
                </span>
                <SearchableSelect
                  v-model="selectedMatchEvidence"
                  :options="matchEvidenceSelectOptions"
                  accent="payroll"
                  :placeholder="t('payroll.payments.settlements.select_evidence')"
                  :no-results-label="t('payroll.payments.settlements.no_evidence')"
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.match_amount') }}
                </span>
                <input
                  v-model="matchAmount"
                  type="text"
                  inputmode="decimal"
                  class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:ring-payroll-500/20"
                  :placeholder="t('payroll.payments.settlements.amount_placeholder')"
                >
              </label>
              <div class="flex items-end">
                <button
                  type="submit"
                  class="w-full sm:w-auto"
                  :class="btnFilled('success')"
                  :disabled="!canMatch || matching"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.check" />
                  </svg>
                  {{ matching
                    ? t('payroll.payments.settlements.matching')
                    : t('payroll.payments.settlements.match') }}
                </button>
              </div>
            </div>
            <p v-if="selectedAllocation && matchEvidenceCandidates.length === 0" class="mt-3 text-sm text-warning-700">
              {{ t('payroll.payments.settlements.no_compatible_evidence') }}
            </p>
          </form>

          <form
            class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm"
            @submit.prevent="reversePayment"
          >
            <h2 class="font-semibold text-neutral-900">
              {{ t('payroll.payments.settlements.new_reversal') }}
            </h2>
            <p class="mt-1 text-sm text-neutral-500">
              {{ t('payroll.payments.settlements.new_reversal_hint') }}
            </p>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block sm:col-span-2">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.source_match') }}
                </span>
                <SearchableSelect
                  v-model="selectedSourceMatchId"
                  :options="sourceMatchSelectOptions"
                  accent="payroll"
                  :placeholder="t('payroll.payments.settlements.select_source_match')"
                  :no-results-label="t('payroll.payments.settlements.no_reversible_matches')"
                />
              </label>
              <label class="block sm:col-span-2">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.reversal_evidence') }}
                </span>
                <SearchableSelect
                  v-model="selectedReversalEvidence"
                  :options="reversalEvidenceSelectOptions"
                  accent="payroll"
                  :placeholder="t('payroll.payments.settlements.select_reversal_evidence')"
                  :no-results-label="t('payroll.payments.settlements.no_reversal_evidence')"
                />
              </label>
              <label class="block">
                <span class="mb-1 block text-sm font-medium text-neutral-700">
                  {{ t('payroll.payments.settlements.reversal_amount') }}
                </span>
                <input
                  v-model="reversalAmount"
                  type="text"
                  inputmode="decimal"
                  class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:ring-payroll-500/20"
                  :placeholder="t('payroll.payments.settlements.amount_placeholder')"
                >
              </label>
              <div class="flex items-end">
                <button
                  type="submit"
                  class="w-full sm:w-auto"
                  :class="btnFilled('warning')"
                  :disabled="!canReverse || reversing"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.cycle" />
                  </svg>
                  {{ reversing
                    ? t('payroll.payments.settlements.reversing')
                    : t('payroll.payments.settlements.reverse') }}
                </button>
              </div>
            </div>
            <p v-if="selectedSourceMatch && reversalEvidenceCandidates.length === 0" class="mt-3 text-sm text-warning-700">
              {{ selectedSourceMatch.evidence_kind === 'cash'
                ? t('payroll.payments.settlements.cash_reversal_hint')
                : t('payroll.payments.settlements.no_reversal_evidence') }}
            </p>
          </form>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
          <h2 class="font-semibold text-neutral-900">
            {{ t('payroll.payments.settlements.history') }}
          </h2>
          <p v-if="paymentMatches.length === 0" class="mt-4 text-sm text-neutral-500">
            {{ t('payroll.payments.settlements.empty_history') }}
          </p>
          <div v-else class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
            <article
              v-for="event in paymentMatches"
              :key="event.id"
              class="rounded-lg border border-neutral-200 p-4"
            >
              <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                  <h3 class="truncate font-medium text-neutral-900">
                    {{ event.employee_name || t('payroll.payments.company') }}
                  </h3>
                  <p class="mt-1 text-sm text-neutral-500">
                    {{ kindLabel(event.liability_kind) }} ·
                    {{ formatDate(event.actual_payment_date) }}
                  </p>
                </div>
                <span
                  class="rounded-full px-2 py-1 text-xs font-medium"
                  :class="event.event_kind === 'matched'
                    ? 'bg-success-50 text-success-700'
                    : 'bg-warning-50 text-warning-700'"
                >
                  {{ t(`payroll.payments.settlements.event.${event.event_kind}`) }}
                </span>
              </div>
              <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div>
                  <dt class="text-xs text-neutral-500">
                    {{ t('payroll.payments.amount') }}
                  </dt>
                  <dd class="mt-0.5 font-semibold text-neutral-900">
                    {{ formatMoney(event.amount_minor, event.evidence_currency_code) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs text-neutral-500">
                    {{ t('payroll.payments.settlements.evidence') }}
                  </dt>
                  <dd class="mt-0.5 text-neutral-800">
                    {{ t(`payroll.payments.recipient.${event.evidence_kind}`) }}
                  </dd>
                </div>
              </dl>
            </article>
          </div>
        </section>
      </template>
    </template>
  </div>
</template>
