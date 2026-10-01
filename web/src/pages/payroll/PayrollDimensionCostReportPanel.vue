<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollDimensionCostReport,
  type PayrollDimensionCostReportRow,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

/*
 * Náklady na zaměstnance po dimenzi. Deník se po zaměstnanci neúčtuje, report
 * proto čte cílové alokace účetního můstku mezd — součet sedí na nákladové
 * řádky mzdového předpisu v deníku.
 */
const props = defineProps<{ initialYear: number }>()
const { t, locale } = useI18n()
const auth = useAuthStore()
const year = ref(props.initialYear)
const data = ref<PayrollDimensionCostReport | null>(null)
const loading = ref(false)
const loadError = ref('')
const canRead = computed(() => auth.canRead('payroll.reports'))
// Firma bez dimenzí report nevidí vůbec — rozhoduje API (`enabled`), ne šablona.
const visible = computed(() => canRead.value && (loadError.value !== '' || data.value?.enabled === true))

const formatter = computed(() => new Intl.NumberFormat(locale.value, {
  style: 'currency', currency: 'CZK', minimumFractionDigits: 2,
}))

function money(amount: number): string {
  return formatter.value.format(amount / 100)
}

function employeeLabel(row: PayrollDimensionCostReportRow): string {
  if (row.employment_id === null) return t('payroll.dimension_cost_report.unassigned')
  const name = row.employee_name ?? `#${row.employment_id}`
  return row.employment_code ? `${name} (${row.employment_code})` : name
}

function unallocatedHint(row: PayrollDimensionCostReportRow): string | undefined {
  if (row.employment_id !== null) return undefined
  if (row.unallocated_reason === 'employer_insurance_not_allocatable') {
    return t('payroll.dimension_cost_report.unassigned_reason.employer_insurance_not_allocatable')
  }
  if (row.unallocated_reason === 'firm_level_cost') {
    return t('payroll.dimension_cost_report.unassigned_reason.firm_level_cost')
  }
  return undefined
}

function dimensionLabel(row: PayrollDimensionCostReportRow): string {
  if (row.dimensions.length > 0) {
    return row.dimensions.map(dimension => `${dimension.type_name}: ${dimension.code}`).join(', ')
  }
  return row.cost_center ?? t('payroll.dimension_cost_report.no_dimension')
}

async function load(): Promise<void> {
  if (!canRead.value) return
  loading.value = true
  loadError.value = ''
  try {
    data.value = await payrollApi.dimensionCostReport(year.value)
  } catch {
    data.value = null
    loadError.value = t('payroll.dimension_cost_report.load_failed')
  } finally {
    loading.value = false
  }
}

watch(year, () => void load())
onMounted(load)
</script>

<template>
  <section v-if="visible" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-test="payroll-dimension-cost-report">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.dimension_cost_report.title') }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.dimension_cost_report.description') }}</p>
      </div>
      <label class="text-sm text-neutral-600">
        <span class="mb-1 block text-xs font-medium">{{ t('payroll.annual_report.year') }}</span>
        <input v-model.number="year" type="number" min="2000" max="2200" class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900">
      </label>
    </div>

    <div v-if="loading" class="mt-4 h-24 animate-pulse rounded-lg bg-neutral-100" />
    <div
      v-else-if="loadError"
      class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="dimension-cost-report-error"
    >
      <p>{{ loadError }}</p>
      <button type="button" :class="[btnOutlineSm('danger'), 'mt-3']" @click="load">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.retry') }}
      </button>
    </div>
    <template v-else-if="data">
      <p v-if="data.rows.length === 0" class="mt-4 text-sm text-neutral-500">{{ t('payroll.dimension_cost_report.empty') }}</p>
      <template v-else>
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="dimension-cost-report-summary">
          <div
            v-for="summary in data.by_dimension"
            :key="`${summary.value_id ?? 'c'}-${summary.code}`"
            class="rounded-lg bg-payroll-50 p-3"
          >
            <p class="text-xs text-payroll-800">
              {{ summary.type_name ? `${summary.type_name}: ${summary.code}` : (summary.code || t('payroll.dimension_cost_report.no_dimension')) }}
            </p>
            <p class="mt-1 text-lg font-semibold text-payroll-950">{{ money(summary.total_minor) }}</p>
          </div>
        </div>

        <div class="mt-4 grid gap-3 md:hidden">
          <article
            v-for="(row, index) in data.rows"
            :key="`mobile-${index}`"
            class="rounded-lg border border-neutral-200 bg-surface p-3"
          >
            <h3 class="font-semibold text-neutral-900">{{ employeeLabel(row) }}</h3>
            <p v-if="unallocatedHint(row)" class="mt-1 text-xs text-neutral-500" data-test="dimension-cost-report-unassigned-hint">{{ unallocatedHint(row) }}</p>
            <p class="text-xs text-neutral-500">{{ dimensionLabel(row) }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.dimension_cost_report.wages') }}</dt>
                <dd class="mt-0.5 font-medium text-neutral-900">{{ money(row.wages_minor) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.dimension_cost_report.insurance') }}</dt>
                <dd class="mt-0.5 font-medium text-neutral-900">{{ money(row.insurance_minor) }}</dd>
              </div>
              <div class="col-span-2">
                <dt class="text-xs text-neutral-500">{{ t('payroll.dimension_cost_report.total') }}</dt>
                <dd class="mt-0.5 font-semibold text-neutral-900">{{ money(row.total_minor) }}</dd>
              </div>
            </dl>
          </article>
        </div>
        <div class="mt-4 hidden overflow-x-auto md:block">
          <table class="min-w-full text-sm">
            <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
              <tr>
                <th class="px-2 py-2 font-medium">{{ t('payroll.dimension_cost_report.employee') }}</th>
                <th class="px-2 py-2 font-medium">{{ t('payroll.dimension_cost_report.dimension') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('payroll.dimension_cost_report.wages') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('payroll.dimension_cost_report.insurance') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('payroll.dimension_cost_report.other') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('payroll.dimension_cost_report.total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in data.rows" :key="index" class="border-b border-neutral-100">
                <td class="px-2 py-2 text-neutral-700">
                  <span
                    v-if="unallocatedHint(row)"
                    class="cursor-help underline decoration-dotted underline-offset-2"
                    :title="unallocatedHint(row)"
                    data-test="dimension-cost-report-unassigned"
                  >{{ employeeLabel(row) }}</span>
                  <template v-else>{{ employeeLabel(row) }}</template>
                </td>
                <td class="px-2 py-2 text-neutral-700">{{ dimensionLabel(row) }}</td>
                <td class="px-2 py-2 text-right text-neutral-700">{{ money(row.wages_minor) }}</td>
                <td class="px-2 py-2 text-right text-neutral-700">{{ money(row.insurance_minor) }}</td>
                <td class="px-2 py-2 text-right text-neutral-700">{{ money(row.other_minor) }}</td>
                <td class="px-2 py-2 text-right font-semibold text-neutral-900">{{ money(row.total_minor) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="font-semibold text-neutral-900">
                <td class="px-2 py-2" colspan="2">{{ t('payroll.dimension_cost_report.total') }}</td>
                <td class="px-2 py-2 text-right">{{ money(data.totals.wages_minor) }}</td>
                <td class="px-2 py-2 text-right">{{ money(data.totals.insurance_minor) }}</td>
                <td class="px-2 py-2 text-right">{{ money(data.totals.other_minor) }}</td>
                <td class="px-2 py-2 text-right">{{ money(data.totals.total_minor) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>
      <p class="mt-3 text-xs text-neutral-500">{{ t('payroll.dimension_cost_report.source_hint') }}</p>
    </template>
  </section>
</template>
