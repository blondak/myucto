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
  type PayrollMigrationCell,
  type PayrollMigrationCellStatus,
  type PayrollMigrationReconciliation,
  type PayrollMigrationRow,
  type PayrollMigrationRowMetric,
  type PayrollMigrationSource,
  type PayrollMigrationTotalMetric,
} from '@/api/payrollMigrationReconciliation'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const canRead = computed(() => auth.canRead('payroll.reports'))

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

watch([year, source], () => void load())
onMounted(load)
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
