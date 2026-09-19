<script setup lang="ts">
/*
 * PAM-11 — „naše přepočtená mzda vs. mzda převzatá z původního systému".
 *
 * Přepočet historického měsíce je bez téhle obrazovky hazard: původní systém ta
 * čísla už podal do JMHZ, na zdravotní pojišťovny a na finanční úřad, takže
 * účetní potřebuje vidět, kde se výsledek rozešel — a hlavně kde protějšek CHYBÍ.
 * Chybějící strana se proto kreslí jako „—" s vlastním odznakem, nikdy jako nula.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollMigrationReconciliationApi,
  payrollTakeoverWagesApi,
  type PayrollMigrationCell,
  type PayrollMigrationCellStatus,
  type PayrollMigrationReconciliation,
  type PayrollMigrationRow,
  type PayrollMigrationRowMetric,
  type PayrollMigrationSource,
  type PayrollMigrationTotalMetric,
  type PayrollTakeoverImportPreview,
  type PayrollTakeoverOverview,
  type PayrollTakeoverPresence,
} from '@/api/payrollMigrationReconciliation'
import { apiErrorMessage } from '@/api/errors'
import { readFileAsBase64 } from '@/components/payroll/imports/importHelpers'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const canRead = computed(() => auth.canRead('payroll.reports'))
const canImport = computed(() => auth.canWrite('payroll.employment.write'))

const year = ref(new Date().getFullYear())
const source = ref<PayrollMigrationSource | ''>('')
const hideMatching = ref(true)
const loading = ref(false)
const loadError = ref('')
const report = ref<PayrollMigrationReconciliation | null>(null)
let loadSequence = 0

const rowMetrics = computed<PayrollMigrationRowMetric[]>(() => report.value?.row_metrics ?? [])
const totalMetrics = computed<PayrollMigrationTotalMetric[]>(() => report.value?.total_metrics ?? [])

/** Řádky měsíce po zapnutí „skrýt shodné". Chybějící protějšek se neskrývá nikdy. */
function visibleRows(rows: PayrollMigrationRow[]): PayrollMigrationRow[] {
  return hideMatching.value ? rows.filter(row => row.has_deviation) : rows
}

const visibleMonths = computed(() =>
  (report.value?.months ?? []).filter(month => !hideMatching.value || visibleRows(month.rows).length > 0),
)

function money(minor: number | null): string {
  return minor === null ? '—' : formatMoneyMinor(minor)
}

function metricLabel(metric: PayrollMigrationTotalMetric): string {
  return t(`payroll.migration_reconciliation.metric.${metric}`)
}

function statusLabel(status: PayrollMigrationCellStatus): string {
  return t(`payroll.migration_reconciliation.status.${status}`)
}

function cellClass(cell: PayrollMigrationCell): string {
  if (cell.status === 'differs') return 'text-danger-700 font-semibold'
  if (cell.status !== 'match') return 'text-warning-700 font-semibold'
  return 'text-neutral-700'
}

function personLabel(row: { full_name: string | null; external_person_ref: string | null }): string {
  if (row.full_name) return row.full_name
  return row.external_person_ref
    ? t('payroll.migration_reconciliation.external_person', { ref: row.external_person_ref })
    : t('payroll.migration_reconciliation.unknown_person')
}

function presenceBadgeClass(presence: PayrollMigrationRow['presence']): string {
  return presence === 'both' ? 'bg-neutral-100 text-neutral-600' : 'bg-warning-50 text-warning-700'
}

async function load(): Promise<void> {
  if (!canRead.value) return
  const sequence = ++loadSequence
  loading.value = true
  loadError.value = ''
  try {
    const data = await payrollMigrationReconciliationApi.report(year.value, source.value || null)
    if (sequence !== loadSequence) return
    report.value = data
  } catch (error) {
    if (sequence !== loadSequence) return
    report.value = null
    loadError.value = apiErrorMessage(error, t('payroll.migration_reconciliation.load_failed'))
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

// ─── Převzaté mzdy: přehled a nahrání ───────────────────────────────────────
// Kontrolní sestava umí jen ukázat rozdíl. Tady se převzatá strana pořizuje —
// a hlavně je vidět, co za rok vůbec je: měsíc, který není ani převzatý, ani
// spočítaný, je díra v roce, kterou ani jedna z obou tabulek výš neukáže.

const TAKEOVER_SOURCES: PayrollMigrationSource[] = ['other', 'pamica', 'pohoda', 'money_s3']

const takeover = ref<PayrollTakeoverOverview | null>(null)
const takeoverLoading = ref(false)
const importSource = ref<PayrollMigrationSource>('other')
const importFile = ref<File | null>(null)
const importPreview = ref<PayrollTakeoverImportPreview | null>(null)
const importResult = ref<string>('')
const importError = ref('')
const importBusy = ref(false)

const importFormat = computed<'csv' | 'xlsx' | null>(() => {
  const name = importFile.value?.name.toLowerCase() ?? ''
  if (name.endsWith('.csv')) return 'csv'
  if (name.endsWith('.xlsx')) return 'xlsx'
  return null
})

const canApplyImport = computed(() =>
  importPreview.value !== null
  && importPreview.value.errors.length === 0
  && importPreview.value.people.length > 0,
)

function presenceClass(presence: PayrollTakeoverPresence): string {
  return {
    takeover_only: 'bg-payroll-50 text-payroll-800',
    calculated_only: 'bg-success-50 text-success-700',
    both: 'bg-warning-50 text-warning-700',
    none: 'bg-neutral-100 text-neutral-500',
  }[presence]
}

async function loadTakeover(): Promise<void> {
  if (!canRead.value) return
  takeoverLoading.value = true
  try {
    takeover.value = await payrollTakeoverWagesApi.overview(year.value, source.value || null)
  } catch {
    // Přehled je doplněk hlavní sestavy; jeho selhání nesmí obrazovku shodit.
    takeover.value = null
  } finally {
    takeoverLoading.value = false
  }
}

function onImportFile(event: Event): void {
  const input = event.target as HTMLInputElement
  importFile.value = input.files?.[0] ?? null
  importPreview.value = null
  importResult.value = ''
  importError.value = ''
}

async function importPayload(): Promise<{
  source: PayrollMigrationSource
  format: 'csv' | 'xlsx'
  source_name: string
  content_base64: string
} | null> {
  const file = importFile.value
  const format = importFormat.value
  if (!file || format === null) {
    importError.value = t('payroll.migration_reconciliation.takeover.format_unsupported')
    return null
  }
  return {
    source: importSource.value,
    format,
    source_name: file.name,
    content_base64: await readFileAsBase64(file),
  }
}

async function runImportPreview(): Promise<void> {
  if (importBusy.value) return
  importBusy.value = true
  importError.value = ''
  importResult.value = ''
  try {
    const payload = await importPayload()
    if (payload === null) return
    importPreview.value = await payrollTakeoverWagesApi.importPreview(payload)
  } catch (error) {
    importPreview.value = null
    importError.value = apiErrorMessage(error, t('payroll.migration_reconciliation.takeover.preview_failed'))
  } finally {
    importBusy.value = false
  }
}

async function runImportApply(): Promise<void> {
  if (importBusy.value || !canApplyImport.value) return
  importBusy.value = true
  importError.value = ''
  try {
    const payload = await importPayload()
    if (payload === null) return
    const result = await payrollTakeoverWagesApi.importApply(payload)
    importResult.value = t('payroll.migration_reconciliation.takeover.apply_done', {
      rows: result.written,
      people: result.employee_count,
      months: result.periods.length,
    })
    importPreview.value = null
    importFile.value = null
    await Promise.all([loadTakeover(), load()])
  } catch (error) {
    importError.value = apiErrorMessage(error, t('payroll.migration_reconciliation.takeover.apply_failed'))
  } finally {
    importBusy.value = false
  }
}

watch([year, source], () => {
  void load()
  void loadTakeover()
})
onMounted(() => {
  void load()
  void loadTakeover()
})
</script>

<template>
  <div v-if="canRead" class="space-y-6" data-test="payroll-migration-reconciliation">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">
          {{ t('payroll.migration_reconciliation.title') }}
        </h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.migration_reconciliation.subtitle') }}
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.migration_reconciliation.year') }}
          </span>
          <input
            v-model.number="year"
            type="number"
            min="2000"
            max="2200"
            class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          >
        </label>
        <label v-if="(report?.sources.length ?? 0) > 1" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.migration_reconciliation.source') }}
          </span>
          <select
            v-model="source"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          >
            <option value="">{{ t('payroll.migration_reconciliation.source_all') }}</option>
            <option v-for="item in report?.sources ?? []" :key="item" :value="item">
              {{ t(`payroll.migration_reconciliation.source_name.${item}`) }}
            </option>
          </select>
        </label>
        <button
          type="button"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
          :disabled="loading"
          data-test="migration-reconciliation-reload"
          @click="load"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.migration_reconciliation.reload') }}
        </button>
      </div>
    </header>

    <section
      class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
      data-test="payroll-takeover-panel"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.migration_reconciliation.takeover.title') }}
          </h2>
          <p class="mt-1 max-w-3xl text-sm text-neutral-500">
            {{ t('payroll.migration_reconciliation.takeover.subtitle') }}
          </p>
        </div>
        <a
          :href="payrollTakeoverWagesApi.importTemplateUrl"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
          download
          data-test="takeover-template"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.download" />
          </svg>
          {{ t('payroll.migration_reconciliation.takeover.template') }}
        </a>
      </div>

      <div v-if="takeoverLoading" class="mt-4 h-20 animate-pulse rounded-lg bg-neutral-100" />
      <template v-else-if="takeover">
        <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
          <div
            v-for="item in takeover.periods"
            :key="`takeover-${item.period}`"
            class="rounded-lg border border-neutral-200 p-2"
            :data-test="`takeover-month-${item.period}`"
          >
            <p class="flex flex-wrap items-center gap-1 text-xs font-medium text-neutral-700">
              {{ formatPeriod(item.period) }}
              <span
                v-if="item.historical"
                class="rounded bg-neutral-100 px-1 py-0.5 text-[10px] text-neutral-500"
              >{{ t('payroll.migration_reconciliation.takeover.historical') }}</span>
            </p>
            <p class="mt-1">
              <span class="rounded px-1.5 py-0.5 text-xs" :class="presenceClass(item.presence)">
                {{ t(`payroll.migration_reconciliation.takeover.presence.${item.presence}`) }}
              </span>
            </p>
            <p v-if="item.takeover_employee_count > 0" class="mt-1 text-xs text-neutral-500">
              {{ t('payroll.migration_reconciliation.takeover.people_count', { count: item.takeover_employee_count }) }}
              ·
              {{ item.sources.map(code => t(`payroll.migration_reconciliation.source_name.${code}`)).join(', ') }}
            </p>
          </div>
        </div>
        <p v-if="takeover.missing_periods.length > 0" class="mt-3 text-sm text-warning-700">
          {{ t('payroll.migration_reconciliation.takeover.missing', {
            periods: takeover.missing_periods.map(formatPeriod).join(', '),
          }) }}
        </p>
        <p v-if="takeover.overlapping_periods.length > 0" class="mt-1 text-sm text-neutral-600">
          {{ t('payroll.migration_reconciliation.takeover.overlapping', {
            periods: takeover.overlapping_periods.map(formatPeriod).join(', '),
          }) }}
        </p>
      </template>

      <div v-if="canImport" class="mt-5 border-t border-neutral-200 pt-4">
        <h3 class="font-semibold text-neutral-900">
          {{ t('payroll.migration_reconciliation.takeover.upload_title') }}
        </h3>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.migration_reconciliation.takeover.upload_hint') }}
        </p>
        <div class="mt-3 flex flex-wrap items-end gap-2">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.migration_reconciliation.takeover.import_source') }}
            </span>
            <select
              v-model="importSource"
              class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
              data-test="takeover-import-source"
            >
              <option v-for="item in TAKEOVER_SOURCES" :key="`src-${item}`" :value="item">
                {{ t(`payroll.migration_reconciliation.source_name.${item}`) }}
              </option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.migration_reconciliation.takeover.file') }}
            </span>
            <input
              type="file"
              accept=".csv,.xlsx"
              class="h-9 text-sm text-neutral-700"
              data-test="takeover-import-file"
              @change="onImportFile"
            >
          </label>
          <button
            type="button"
            :class="[btnOutline('primary'), 'whitespace-nowrap']"
            :disabled="importBusy || !importFile"
            data-test="takeover-import-preview"
            @click="runImportPreview"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.eye" />
            </svg>
            {{ t('payroll.migration_reconciliation.takeover.preview') }}
          </button>
          <button
            type="button"
            :class="[btnFilled('primary'), 'whitespace-nowrap']"
            :disabled="importBusy || !canApplyImport"
            data-test="takeover-import-apply"
            @click="runImportApply"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.upload" />
            </svg>
            {{ t('payroll.migration_reconciliation.takeover.apply') }}
          </button>
        </div>

        <p
          v-if="importError"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="takeover-import-error"
        >{{ importError }}</p>
        <p v-if="importResult" class="mt-3 text-sm text-success-700" data-test="takeover-import-result">
          {{ importResult }}
        </p>

        <template v-if="importPreview">
          <p
            v-if="importPreview.errors.length > 0"
            class="mt-3 text-sm text-danger-700"
            data-test="takeover-import-invalid"
          >
            {{ t('payroll.migration_reconciliation.takeover.errors_block', {
              count: importPreview.errors.length,
            }) }}
          </p>
          <ul v-if="importPreview.errors.length > 0" class="mt-2 space-y-1 text-sm text-danger-700">
            <li v-for="item in importPreview.errors.slice(0, 20)" :key="`err-${item.row_number}-${item.error_code}`">
              {{ t('payroll.migration_reconciliation.takeover.error_row', { row: item.row_number }) }}
              {{ item.error_message }}
            </li>
          </ul>
          <div v-else class="mt-3 overflow-x-auto">
            <p class="text-sm text-neutral-600">
              {{ t('payroll.migration_reconciliation.takeover.preview_summary', {
                rows: importPreview.row_count,
                people: importPreview.people.length,
                months: importPreview.periods.length,
              }) }}
            </p>
            <table class="mt-2 min-w-full text-sm">
              <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
                <tr>
                  <th class="px-2 py-2 font-medium">{{ t('payroll.migration_reconciliation.person') }}</th>
                  <th class="px-2 py-2 text-right font-medium">
                    {{ t('payroll.migration_reconciliation.takeover.months') }}
                  </th>
                  <th class="px-2 py-2 text-right font-medium">
                    {{ t('payroll.migration_reconciliation.metric.gross') }}
                  </th>
                  <th class="px-2 py-2 text-right font-medium">
                    {{ t('payroll.migration_reconciliation.takeover.net_payable') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="person in importPreview.people"
                  :key="`prev-${person.employee_id}`"
                  class="border-b border-neutral-100"
                >
                  <td class="px-2 py-2 text-neutral-700">{{ person.employee_name }}</td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ person.month_count }}</td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ money(person.gross_minor) }}</td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ money(person.net_payable_minor) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </section>

    <div
      v-if="loadError"
      class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="migration-reconciliation-error"
    >
      <p>{{ loadError }}</p>
      <button type="button" :class="[btnOutline('danger'), 'mt-3']" @click="load">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.retry') }}
      </button>
    </div>

    <div v-else-if="loading" class="h-40 animate-pulse rounded-lg bg-neutral-100" />

    <template v-else-if="report">
      <section class="grid grid-cols-1 gap-3 sm:grid-cols-4">
        <div class="rounded-lg bg-payroll-50 p-3">
          <p class="text-xs text-payroll-800">{{ t('payroll.migration_reconciliation.summary.rows') }}</p>
          <p class="mt-1 text-lg font-semibold text-payroll-950">{{ report.summary.row_count }}</p>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <p class="text-xs text-neutral-600">{{ t('payroll.migration_reconciliation.summary.deviations') }}</p>
          <p class="mt-1 text-lg font-semibold text-neutral-900">{{ report.summary.deviation_count }}</p>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <p class="text-xs text-neutral-600">{{ t('payroll.migration_reconciliation.summary.missing') }}</p>
          <p class="mt-1 text-lg font-semibold text-neutral-900">{{ report.summary.missing_counterpart_count }}</p>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <p class="text-xs text-neutral-600">{{ t('payroll.migration_reconciliation.summary.max_difference') }}</p>
          <p class="mt-1 text-lg font-semibold text-neutral-900">
            {{ money(report.summary.max_abs_difference_minor) }}
          </p>
        </div>
      </section>

      <EmptyState
        v-if="report.summary.row_count === 0"
        variant="empty"
        accent="accent"
        :title="t('payroll.migration_reconciliation.empty_title')"
        :description="t('payroll.migration_reconciliation.empty_description')"
      />

      <template v-else>
        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold text-neutral-900">
                {{ t('payroll.migration_reconciliation.deviations_title') }}
              </h2>
              <p class="mt-1 max-w-3xl text-sm text-neutral-500">
                {{ t('payroll.migration_reconciliation.deviations_hint') }}
              </p>
            </div>
          </div>

          <p v-if="report.deviations.length === 0" class="mt-4 text-sm text-success-700">
            {{ t('payroll.migration_reconciliation.no_deviations') }}
          </p>
          <div v-else class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
                <tr>
                  <th class="px-2 py-2 font-medium">{{ t('payroll.migration_reconciliation.period') }}</th>
                  <th class="px-2 py-2 font-medium">{{ t('payroll.migration_reconciliation.person') }}</th>
                  <th class="px-2 py-2 font-medium">{{ t('payroll.migration_reconciliation.metric_column') }}</th>
                  <th class="px-2 py-2 text-right font-medium">{{ t('payroll.migration_reconciliation.reference') }}</th>
                  <th class="px-2 py-2 text-right font-medium">{{ t('payroll.migration_reconciliation.calculated') }}</th>
                  <th class="px-2 py-2 text-right font-medium">{{ t('payroll.migration_reconciliation.difference') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(deviation, index) in report.deviations"
                  :key="`${deviation.period}-${deviation.employee_id ?? deviation.external_person_ref}-${deviation.metric}-${index}`"
                  class="border-b border-neutral-100"
                >
                  <td class="px-2 py-2 text-neutral-700">{{ formatPeriod(deviation.period) }}</td>
                  <td class="px-2 py-2 text-neutral-700">{{ personLabel(deviation) }}</td>
                  <td class="px-2 py-2 text-neutral-700">{{ metricLabel(deviation.metric) }}</td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ money(deviation.reference_minor) }}</td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ money(deviation.calculated_minor) }}</td>
                  <td class="px-2 py-2 text-right">
                    <span v-if="deviation.difference_minor !== null" class="font-semibold text-danger-700">
                      {{ money(deviation.difference_minor) }}
                    </span>
                    <span v-else class="rounded px-1.5 py-0.5 text-xs bg-warning-50 text-warning-700">
                      {{ statusLabel(deviation.status) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="text-lg font-semibold text-neutral-900">
              {{ t('payroll.migration_reconciliation.year_totals') }}
            </h2>
          </div>
          <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
                <tr>
                  <th class="px-2 py-2 font-medium">{{ t('payroll.migration_reconciliation.metric_column') }}</th>
                  <th class="px-2 py-2 text-right font-medium">{{ t('payroll.migration_reconciliation.reference') }}</th>
                  <th class="px-2 py-2 text-right font-medium">{{ t('payroll.migration_reconciliation.calculated') }}</th>
                  <th class="px-2 py-2 text-right font-medium">{{ t('payroll.migration_reconciliation.difference') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="metric in totalMetrics" :key="`year-${metric}`" class="border-b border-neutral-100">
                  <td class="px-2 py-2 text-neutral-700">
                    {{ metricLabel(metric) }}
                    <span
                      v-if="report.totals[metric].incomplete"
                      class="ml-2 rounded bg-warning-50 px-1.5 py-0.5 text-xs text-warning-700"
                      :title="t('payroll.migration_reconciliation.incomplete_hint')"
                    >{{ t('payroll.migration_reconciliation.incomplete') }}</span>
                  </td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ money(report.totals[metric].reference_minor) }}</td>
                  <td class="px-2 py-2 text-right text-neutral-700">{{ money(report.totals[metric].calculated_minor) }}</td>
                  <td class="px-2 py-2 text-right" :class="cellClass(report.totals[metric])">
                    {{ report.totals[metric].difference_minor !== null
                      ? money(report.totals[metric].difference_minor)
                      : statusLabel(report.totals[metric].status) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="text-lg font-semibold text-neutral-900">
              {{ t('payroll.migration_reconciliation.months_title') }}
            </h2>
            <label class="flex items-center gap-2 text-sm text-neutral-600">
              <input v-model="hideMatching" type="checkbox" class="rounded border-neutral-300" data-test="hide-matching">
              {{ t('payroll.migration_reconciliation.hide_matching') }}
            </label>
          </div>

          <p v-if="visibleMonths.length === 0" class="mt-4 text-sm text-success-700">
            {{ t('payroll.migration_reconciliation.all_matching') }}
          </p>

          <article v-for="month in visibleMonths" :key="month.period" class="mt-6 first:mt-4">
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="font-semibold text-neutral-900">{{ formatPeriod(month.period) }}</h3>
              <span
                v-if="month.calculated_revision_status && month.calculated_revision_status !== 'approved'"
                class="rounded bg-warning-50 px-1.5 py-0.5 text-xs text-warning-700"
              >
                {{ t('payroll.migration_reconciliation.revision_status', { status: month.calculated_revision_status }) }}
              </span>
              <span
                v-if="month.missing_counterpart_count > 0"
                class="rounded bg-warning-50 px-1.5 py-0.5 text-xs text-warning-700"
              >
                {{ t('payroll.migration_reconciliation.missing_count', { count: month.missing_counterpart_count }) }}
              </span>
            </div>

            <div class="mt-3 overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
                  <tr>
                    <th class="px-2 py-2 font-medium">{{ t('payroll.migration_reconciliation.person') }}</th>
                    <th class="px-2 py-2 font-medium">{{ t('payroll.migration_reconciliation.side') }}</th>
                    <th
                      v-for="metric in rowMetrics"
                      :key="`head-${month.period}-${metric}`"
                      class="whitespace-nowrap px-2 py-2 text-right font-medium"
                    >{{ metricLabel(metric) }}</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="row in visibleRows(month.rows)" :key="`${month.period}-${row.employee_id ?? row.external_person_ref}`">
                    <tr class="border-t border-neutral-100">
                      <td class="px-2 py-2 align-top text-neutral-900" rowspan="3">
                        <span class="font-medium">{{ personLabel(row) }}</span>
                        <span
                          class="ml-2 rounded px-1.5 py-0.5 text-xs"
                          :class="presenceBadgeClass(row.presence)"
                        >{{ t(`payroll.migration_reconciliation.presence.${row.presence}`) }}</span>
                        <span v-if="row.relationships.length > 1" class="mt-1 block text-xs text-neutral-500">
                          {{ t('payroll.migration_reconciliation.relationship_count', { count: row.relationships.length }) }}
                        </span>
                      </td>
                      <td class="whitespace-nowrap px-2 py-1 text-xs text-neutral-500">
                        {{ t('payroll.migration_reconciliation.reference') }}
                      </td>
                      <td
                        v-for="metric in rowMetrics"
                        :key="`ref-${month.period}-${row.employee_id ?? row.external_person_ref}-${metric}`"
                        class="whitespace-nowrap px-2 py-1 text-right text-neutral-700"
                      >{{ money(row.metrics[metric].reference_minor) }}</td>
                    </tr>
                    <tr>
                      <td class="whitespace-nowrap px-2 py-1 text-xs text-neutral-500">
                        {{ t('payroll.migration_reconciliation.calculated') }}
                      </td>
                      <td
                        v-for="metric in rowMetrics"
                        :key="`calc-${month.period}-${row.employee_id ?? row.external_person_ref}-${metric}`"
                        class="whitespace-nowrap px-2 py-1 text-right text-neutral-700"
                      >{{ money(row.metrics[metric].calculated_minor) }}</td>
                    </tr>
                    <tr class="border-b border-neutral-100">
                      <td class="whitespace-nowrap px-2 py-1 text-xs text-neutral-500">
                        {{ t('payroll.migration_reconciliation.difference') }}
                      </td>
                      <td
                        v-for="metric in rowMetrics"
                        :key="`diff-${month.period}-${row.employee_id ?? row.external_person_ref}-${metric}`"
                        class="whitespace-nowrap px-2 py-1 text-right"
                        :class="cellClass(row.metrics[metric])"
                      >
                        {{ row.metrics[metric].difference_minor !== null
                          ? money(row.metrics[metric].difference_minor)
                          : statusLabel(row.metrics[metric].status) }}
                      </td>
                    </tr>
                  </template>
                </tbody>
                <tfoot>
                  <tr class="border-t-2 border-neutral-200 text-neutral-900">
                    <td class="px-2 py-2 font-semibold" colspan="2">
                      {{ t('payroll.migration_reconciliation.month_total') }}
                    </td>
                    <td
                      v-for="metric in rowMetrics"
                      :key="`total-${month.period}-${metric}`"
                      class="whitespace-nowrap px-2 py-2 text-right font-semibold"
                      :class="cellClass(month.totals[metric])"
                    >
                      {{ month.totals[metric].difference_minor !== null
                        ? money(month.totals[metric].difference_minor)
                        : statusLabel(month.totals[metric].status) }}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <p class="mt-2 text-xs text-neutral-500">
              {{ t('payroll.migration_reconciliation.employer_social_note') }}
              <strong>{{ money(month.totals.employer_social.reference_minor) }}</strong>
              /
              <strong>{{ money(month.totals.employer_social.calculated_minor) }}</strong>
              ({{ month.totals.employer_social.difference_minor !== null
                ? money(month.totals.employer_social.difference_minor)
                : statusLabel(month.totals.employer_social.status) }})
            </p>
          </article>
        </section>

        <p class="text-xs text-neutral-500">{{ t('payroll.migration_reconciliation.grain_hint') }}</p>
      </template>
    </template>
  </div>
</template>
