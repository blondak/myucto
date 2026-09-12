<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { stockApi, type IntrastatExportRequest, type IntrastatPreview } from '@/api/stock'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'

const { t } = useI18n()
const toast = useToast()

function previousPeriod(): string {
  const [year, month] = appIsoDate().split('-').map(Number)
  const date = new Date(Date.UTC(year!, month! - 2, 1))
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`
}

const form = reactive<IntrastatExportRequest>({
  period: previousPeriod(),
  direction: 'dispatch',
  transaction_code: '11',
  transport_mode: '3',
  delivery_terms: 'K',
  record_type: 'ST',
  statistical_code: '',
})
const preview = ref<IntrastatPreview | null>(null)
const loading = ref(false)
const exporting = ref(false)
const error = ref('')
let formRevision = 0
let previewRequest = 0

watch(form, () => {
  formRevision += 1
  preview.value = null
  error.value = ''
}, { deep: true, flush: 'sync' })

const errorCount = computed(() => preview.value?.summary.error_count ?? 0)
const warningCount = computed(() => preview.value?.summary.warning_count ?? 0)
const canExport = computed(() => preview.value !== null && errorCount.value === 0)

async function createPreview() {
  if (loading.value) return
  const request = ++previewRequest
  const revision = formRevision
  loading.value = true
  error.value = ''
  try {
    const result = await stockApi.previewIntrastat({ ...form })
    if (request !== previewRequest || revision !== formRevision) return
    preview.value = result
  } catch (e: unknown) {
    if (request !== previewRequest || revision !== formRevision) return
    preview.value = null
    error.value = apiErrorMessage(e, t('stock.intrastat.error_preview'))
  } finally {
    if (request === previewRequest) loading.value = false
  }
}

async function downloadCsv() {
  if (!canExport.value || exporting.value) return
  exporting.value = true
  error.value = ''
  try {
    await stockApi.exportIntrastat({ ...form })
    toast.success(t('stock.intrastat.export_ready'))
  } catch (e: unknown) {
    error.value = apiErrorMessage(e, t('stock.intrastat.error_export'))
  } finally {
    exporting.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'preview',
    label: t('stock.intrastat.preview'),
    icon: 'eye',
    tier: preview.value ? 'secondary' : 'primary',
    variant: 'primary',
    loading: loading.value,
    disabled: loading.value || exporting.value,
    run: () => void createPreview(),
  },
  {
    key: 'download',
    label: t('stock.intrastat.download_csv'),
    icon: 'download',
    tier: 'primary',
    variant: 'success',
    show: preview.value !== null,
    loading: exporting.value,
    disabled: !canExport.value || loading.value || exporting.value,
    disabledReason: errorCount.value > 0 ? t('stock.intrastat.fix_errors_first') : undefined,
    run: () => void downloadCsv(),
  },
  {
    key: 'open-instatevo',
    label: t('stock.intrastat.open_instatevo'),
    icon: 'link',
    tier: 'secondary',
    variant: 'accent',
    href: 'https://instatevo.celnisprava.gov.cz/',
  },
])

function issueClass(severity: 'error' | 'warning'): string {
  return severity === 'error'
    ? 'border-danger-200 bg-danger-50 text-danger-700'
    : 'border-warning-200 bg-warning-50 text-warning-800'
}
</script>

<template>
  <div class="min-w-0">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('stock.intrastat.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('stock.intrastat.subtitle') }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <div class="mb-4 rounded-xl border border-primary-200 bg-primary-50/60 p-4">
      <h2 class="text-sm font-semibold text-primary-800">{{ t('stock.intrastat.workflow_title') }}</h2>
      <ol class="mt-2 grid gap-2 text-sm text-neutral-700 sm:grid-cols-3">
        <li class="flex gap-2"><span class="font-semibold text-primary-700">1.</span>{{ t('stock.intrastat.workflow_download') }}</li>
        <li class="flex gap-2"><span class="font-semibold text-primary-700">2.</span>{{ t('stock.intrastat.workflow_open') }}</li>
        <li class="flex gap-2"><span class="font-semibold text-primary-700">3.</span>{{ t('stock.intrastat.workflow_import') }}</li>
      </ol>
      <p class="mt-2 text-xs text-neutral-500">{{ t('stock.intrastat.csv_connection_hint') }}</p>
    </div>

    <section class="mb-5 rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
      <h2 class="mb-4 text-base font-semibold text-neutral-900">{{ t('stock.intrastat.settings') }}</h2>
      <div class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-sm">
          <span class="mb-1 block font-medium text-neutral-700">{{ t('stock.intrastat.period') }}</span>
          <input v-model="form.period" data-test="intrastat-period" type="month" required class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" />
        </label>
        <label class="text-sm">
          <span class="mb-1 block font-medium text-neutral-700">{{ t('stock.intrastat.direction') }}</span>
          <select v-model="form.direction" data-test="intrastat-direction" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3">
            <option value="dispatch">{{ t('stock.intrastat.direction_dispatch') }}</option>
            <option value="arrival">{{ t('stock.intrastat.direction_arrival') }}</option>
          </select>
        </label>
        <label class="text-sm">
          <span class="mb-1 block font-medium text-neutral-700">{{ t('stock.intrastat.transaction_code') }}</span>
          <select v-model="form.transaction_code" data-test="intrastat-transaction" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono">
            <option v-for="code in ['11', '12']" :key="code" :value="code">{{ code }}</option>
          </select>
        </label>
        <label class="text-sm">
          <span class="mb-1 block font-medium text-neutral-700">{{ t('stock.intrastat.transport_mode') }}</span>
          <select v-model="form.transport_mode" data-test="intrastat-transport" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3">
            <option v-for="code in ['2', '3', '4', '5', '7', '8', '9']" :key="code" :value="code">{{ code }} - {{ t(`stock.intrastat.transport.${code}`) }}</option>
          </select>
        </label>
        <label class="text-sm">
          <span class="mb-1 block font-medium text-neutral-700">{{ t('stock.intrastat.delivery_terms') }}</span>
          <select v-model="form.delivery_terms" data-test="intrastat-delivery" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono">
            <option v-for="code in ['K', 'L', 'M', 'N']" :key="code" :value="code">{{ code }}</option>
          </select>
          <span class="mt-1 block text-xs text-neutral-500">{{ t('stock.intrastat.delivery_terms_hint') }}</span>
        </label>
        <label class="text-sm">
          <span class="mb-1 block font-medium text-neutral-700">{{ t('stock.intrastat.record_type') }}</span>
          <select v-model="form.record_type" data-test="intrastat-record-type" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3">
            <option value="ST">ST</option>
          </select>
        </label>
        <label class="text-sm">
          <span class="mb-1 block font-medium text-neutral-700">{{ t('stock.intrastat.statistical_code') }}</span>
          <input v-model.trim="form.statistical_code" data-test="intrastat-statistical-code" maxlength="2" inputmode="numeric" pattern="[0-9]{2}" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono" />
          <span class="mt-1 block text-xs text-neutral-500">{{ t('stock.intrastat.statistical_code_hint') }}</span>
        </label>
      </div>
    </section>

    <p v-if="error" data-test="intrastat-error" class="mb-4 rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</p>

    <template v-if="preview">
      <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        <div class="rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm"><div class="text-xs text-neutral-500">{{ t('stock.intrastat.rows') }}</div><div class="mt-1 text-xl font-semibold font-mono">{{ preview.summary.row_count }}</div></div>
        <div class="rounded-lg border border-danger-200 bg-danger-50 p-3"><div class="text-xs text-danger-600">{{ t('stock.intrastat.errors') }}</div><div class="mt-1 text-xl font-semibold font-mono text-danger-700">{{ errorCount }}</div></div>
        <div class="rounded-lg border border-warning-200 bg-warning-50 p-3"><div class="text-xs text-warning-700">{{ t('stock.intrastat.warnings') }}</div><div class="mt-1 text-xl font-semibold font-mono text-warning-800">{{ warningCount }}</div></div>
        <div class="rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm"><div class="text-xs text-neutral-500">{{ t('stock.intrastat.total_mass') }}</div><div class="mt-1 text-xl font-semibold font-mono">{{ preview.summary.total_net_mass_kg ?? '-' }}</div></div>
        <div class="rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm"><div class="text-xs text-neutral-500">{{ t('stock.intrastat.total_value') }}</div><div class="mt-1 whitespace-nowrap text-xl font-semibold font-mono">{{ formatMoney(Number(preview.summary.total_invoiced_value), 'CZK', 0) }}</div></div>
      </div>

      <div v-if="preview.issues.length" class="mb-4 space-y-2">
        <div v-for="(issue, index) in preview.issues" :key="`${issue.code}-${issue.row_number ?? 0}-${index}`" :class="['rounded-lg border px-3 py-2 text-sm', issueClass(issue.severity)]">
          <strong>{{ t(`stock.intrastat.severity.${issue.severity}`) }}</strong><span v-if="issue.row_number"> · {{ t('stock.intrastat.row_number', { row: issue.row_number }) }}</span>: {{ issue.message }}
        </div>
      </div>

      <EmptyState v-if="preview.rows.length === 0" boxed accent="neutral" icon="doc" :title="t('stock.intrastat.empty_title')" :message="t('stock.intrastat.empty_hint')" />
      <div v-else class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div class="overflow-x-auto">
          <table class="min-w-[64rem] w-full text-sm">
            <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
              <tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">{{ t('stock.intrastat.source_document') }}</th><th class="px-3 py-2 text-left">{{ t('stock.intrastat.partner') }}</th><th class="px-3 py-2 text-left">KN8</th><th class="px-3 py-2 text-left">{{ t('stock.intrastat.country') }}</th><th class="px-3 py-2 text-right">{{ t('stock.intrastat.mass') }}</th><th class="px-3 py-2 text-right">{{ t('stock.intrastat.supplementary_quantity') }}</th><th class="px-3 py-2 text-right">{{ t('stock.intrastat.invoiced_value') }}</th><th class="px-3 py-2 text-left">{{ t('stock.intrastat.result') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in preview.rows" :key="row.row_number" :class="row.issues.some(i => i.severity === 'error') ? 'bg-danger-50/40' : 'hover:bg-neutral-50'">
                <td class="px-3 py-2 font-mono">{{ row.row_number }}</td><td class="px-3 py-2"><RouterLink :to="`/stock/documents/${row.source_document.id}`" class="font-mono text-primary-600 hover:text-primary-700">{{ row.source_document.number || `#${row.source_document.id}` }}</RouterLink></td><td class="px-3 py-2">{{ row.partner_name || '-' }}</td><td class="px-3 py-2 font-mono">{{ row.cn8_code || '-' }}</td><td class="px-3 py-2 font-mono">{{ row.country_of_origin || '-' }}</td><td class="px-3 py-2 text-right font-mono">{{ row.net_mass_kg ?? '-' }}</td><td class="px-3 py-2 text-right font-mono">{{ row.supplementary_quantity ?? '-' }}</td><td class="px-3 py-2 text-right font-mono">{{ row.invoiced_value ?? '-' }}</td>
                <td class="px-3 py-2"><span v-if="row.issues.length" :class="['inline-flex rounded-full border px-2 py-0.5 text-xs font-medium', issueClass(row.issues.some(i => i.severity === 'error') ? 'error' : 'warning')]">{{ row.issues.length }}</span><span v-else class="text-success-600">{{ t('stock.intrastat.ready') }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
