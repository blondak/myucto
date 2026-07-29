<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BackfillDocumentIssue, BackfillReport } from '@/api/activation'

const props = defineProps<{ report: BackfillReport }>()
const { t } = useI18n()

const phaseRows = computed(() => {
  const phases = props.report.phases || {}
  const rows: Array<{ key: string; posted: number; skipped: number; failed: number }> = []
  const documents = phases.documents || {}
  if (documents.invoice) rows.push({ key: 'invoices', posted: Number(documents.invoice.posted || 0) + Number(documents.invoice.updated || 0), skipped: Number(documents.invoice.skipped || 0), failed: Number(documents.invoice.failed || 0) })
  if (documents.purchase_invoice) rows.push({ key: 'purchase_invoices', posted: Number(documents.purchase_invoice.posted || 0) + Number(documents.purchase_invoice.updated || 0), skipped: Number(documents.purchase_invoice.skipped || 0), failed: Number(documents.purchase_invoice.failed || 0) })
  if (phases.cash) rows.push({ key: 'cash', posted: Number(phases.cash.posted || 0), skipped: Number(phases.cash.skipped || 0), failed: Number(phases.cash.failed || 0) })
  if (phases.bank) rows.push({ key: 'bank', posted: Number(phases.bank.posted || 0), skipped: Number(phases.bank.skipped || 0), failed: 0 })
  return rows
})
const fatalMessage = computed(() => {
  if (!props.report.fatal_error) return ''
  const key = `activation.error.${props.report.fatal_error}`
  const translated = t(key)
  return translated === key ? t('activation.error.activation_failed') : translated
})
const documentIssues = computed(() => props.report.document_issues || [])
const hasFailedDocuments = computed(() => documentIssues.value.some(issue => issue.severity === 'failed'))

function documentLink(issue: BackfillDocumentIssue) {
  return issue.source_type === 'invoice'
    ? { name: 'invoice-detail', params: { id: issue.source_id } }
    : { name: 'purchase-invoice-detail', params: { id: issue.source_id } }
}

function documentNumber(issue: BackfillDocumentIssue): string {
  return issue.document_no || `#${issue.source_id}`
}

function reasonKey(code: string): string {
  const prefix = code.split(':')[0]
  const known: Record<string, string> = {
    document_not_postable: 'document_not_postable', advance_payment_only: 'advance_payment_only', date_locked: 'date_locked', period_closed: 'period_closed',
    document_not_posted: 'document_not_posted', allocation_mismatch: 'allocation_mismatch',
    period_not_open: 'period_closed', no_period: 'no_period', no_accounting_period: 'no_period',
    not_matched: 'not_matched', rules_disabled: 'not_matched', already_posted: 'already_posted',
    no_rule: 'no_rule', zero_amount: 'zero_amount',
    fx_not_supported: 'fx_not_supported', email_notice_provisional: 'ignored', ignored: 'ignored',
    reconcile_not_posted: 'not_matched', reconcile_error: 'processing_error', transaction_not_found: 'processing_error',
    unknown_supplier: 'processing_error', ambiguous_supplier: 'processing_error', not_double_entry: 'processing_error',
    error: 'processing_error',
  }
  return `activation.skip.${known[prefix] || 'other'}`
}
</script>

<template>
  <div class="space-y-4">
    <div v-if="fatalMessage" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-600">{{ fatalMessage }}</div>
    <div class="overflow-x-auto rounded-lg border border-neutral-200">
      <table class="w-full text-sm"><thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500"><tr><th class="px-3 py-2">{{ t('activation.report_source') }}</th><th class="px-3 py-2 text-right">{{ t('activation.report_ready') }}</th><th class="px-3 py-2 text-right">{{ t('activation.report_skipped') }}</th><th class="px-3 py-2 text-right">{{ t('activation.report_failed') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="row in phaseRows" :key="row.key"><td class="px-3 py-2">{{ t(`activation.source.${row.key}`) }}</td><td class="px-3 py-2 text-right font-mono">{{ row.posted }}</td><td class="px-3 py-2 text-right font-mono">{{ row.skipped }}</td><td class="px-3 py-2 text-right font-mono" :class="row.failed ? 'text-danger-600' : ''">{{ row.failed }}</td></tr></tbody></table>
    </div>
    <div v-if="documentIssues.length" class="overflow-hidden rounded-lg border" :class="hasFailedDocuments ? 'border-danger-500/30' : 'border-warning-500/30'">
      <h3 class="border-b px-4 py-3 font-semibold" :class="hasFailedDocuments ? 'border-danger-500/30 bg-danger-50 text-danger-700' : 'border-warning-500/30 bg-warning-50 text-warning-700'">{{ t('activation.document_issues_title') }}</h3>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500"><tr><th class="px-3 py-2">{{ t('activation.document_issue_document') }}</th><th class="px-3 py-2">{{ t('activation.document_issue_date') }}</th><th class="px-3 py-2">{{ t('activation.document_issue_result') }}</th><th class="px-3 py-2">{{ t('activation.document_issue_reason') }}</th></tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="issue in documentIssues" :key="`${issue.source_type}-${issue.source_id}-${issue.error_code}`">
              <td class="px-3 py-2 whitespace-nowrap"><RouterLink :to="documentLink(issue)" class="font-medium text-primary-600 hover:underline">{{ t(`activation.document_type.${issue.source_type}`) }} {{ documentNumber(issue) }}</RouterLink></td>
              <td class="px-3 py-2 whitespace-nowrap">{{ issue.entry_date || '—' }}</td>
              <td class="px-3 py-2"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="issue.severity === 'failed' ? 'bg-danger-50 text-danger-600' : 'bg-warning-50 text-warning-700'">{{ issue.severity === 'failed' ? t('activation.report_failed') : t('activation.report_skipped') }}</span></td>
              <td class="px-3 py-2">{{ issue.message }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="Object.keys(report.skip_reasons || {}).length" class="rounded-lg border border-warning-500/30 bg-warning-50 p-4"><h3 class="mb-2 font-semibold text-warning-700">{{ t('activation.skipped_title') }}</h3><ul class="space-y-1 text-sm text-warning-700"><li v-for="(count, reason) in report.skip_reasons" :key="reason">{{ count }}× {{ t(reasonKey(String(reason))) }}</li></ul></div>
    <div v-if="report.document_coverage" class="rounded-lg border p-4" :class="Object.values(report.document_coverage).every(row => row.complete) ? 'border-success-500/30 bg-success-50' : 'border-danger-500/30 bg-danger-50'">
      <h3 class="font-semibold">{{ t('activation.coverage_title') }}</h3>
      <p class="mt-1 text-sm">{{ Object.values(report.document_coverage).every(row => row.complete) ? t('activation.coverage_pass') : t('activation.coverage_fail') }}</p>
      <ul class="mt-2 space-y-1 text-sm">
        <li v-for="(row, key) in report.document_coverage" :key="key">
          {{ t(`activation.source.${key === 'invoice' ? 'invoices' : 'purchase_invoices'}`) }}:
          {{ t('activation.coverage_row', { expected: row.expected, handled: row.handled, missing: row.missing }) }}
        </li>
      </ul>
    </div>
    <div class="flex flex-wrap gap-3 text-sm"><span :class="report.failed_total === 0 ? 'text-success-600' : 'text-danger-600'">{{ report.failed_total === 0 ? t('activation.report_no_errors') : t('activation.report_has_errors', { n: report.failed_total }) }}</span><span :class="report.balance?.balanced ? 'text-success-600' : 'text-danger-600'">{{ report.balance?.balanced ? t('activation.balance_pass') : t('activation.balance_fail') }}</span></div>
  </div>
</template>
