<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { personalNumberLabel } from '@/pages/payroll/employmentLifecycleUi'
import {
  payrollImportsApi,
  type AttendanceBatch,
  type AttendanceBatchRow,
} from '@/api/payrollImports'
import { apiErrorMessage } from '@/api/errors'
import { formatDateTime, formatMoneyMinor } from '@/composables/useFormat'
import { btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatHours } from './importHelpers'

const props = defineProps<{
  period: string
}>()

const { t, te, locale } = useI18n()

const batches = ref<AttendanceBatch[]>([])
const loading = ref(false)
const error = ref('')
const onlyPeriod = ref(false)
const expanded = ref<number | null>(null)
const detail = ref<Record<number, AttendanceBatchRow[]>>({})
const detailLoading = ref<number | null>(null)
const detailError = ref('')

async function reload() {
  loading.value = true
  error.value = ''
  try {
    batches.value = await payrollImportsApi.attendanceBatches(onlyPeriod.value ? props.period : null)
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.attendance.history.load_failed'))
  } finally {
    loading.value = false
  }
}

async function toggle(batch: AttendanceBatch) {
  if (expanded.value === batch.id) {
    expanded.value = null
    return
  }
  expanded.value = batch.id
  detailError.value = ''
  if (detail.value[batch.id]) return
  detailLoading.value = batch.id
  try {
    const response = await payrollImportsApi.attendanceBatch(batch.id)
    detail.value[batch.id] = response.rows
  } catch (err) {
    detailError.value = apiErrorMessage(err, t('payroll_imports.attendance.history.detail_failed'))
  } finally {
    detailLoading.value = null
  }
}

function meaningLabel(meaning: string): string {
  const key = `payroll_imports.meanings.${meaning}`
  return te(key) ? t(key) : meaning
}

watch(onlyPeriod, () => { void reload() })
watch(() => props.period, () => { if (onlyPeriod.value) void reload() })

onMounted(reload)
defineExpose({ reload })
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-testid="attendance-history">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3">
      <div>
        <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.history.title') }}</h3>
        <p class="text-xs text-neutral-500">{{ t('payroll_imports.attendance.history.hint') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="onlyPeriod" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
          {{ t('payroll_imports.attendance.history.only_period', { period }) }}
        </label>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="reload">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll_imports.common.reload') }}
        </button>
      </div>
    </div>

    <p v-if="error" role="alert" class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</p>
    <div v-else-if="loading && batches.length === 0" class="space-y-2 p-4">
      <div v-for="index in 3" :key="index" class="h-10 animate-pulse rounded-lg bg-neutral-100" />
    </div>
    <p v-else-if="batches.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('payroll_imports.attendance.history.empty') }}</p>

    <ul v-else class="divide-y divide-neutral-100">
      <li v-for="batch in batches" :key="batch.id">
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
          <div class="min-w-0">
            <p class="font-medium text-neutral-900">
              {{ t('payroll_imports.attendance.history.batch_title', { id: batch.id, period: batch.period }) }}
            </p>
            <p class="text-xs text-neutral-500">
              {{ formatDateTime(batch.created_at) }}<template v-if="batch.created_by_name"> · {{ batch.created_by_name }}</template>
            </p>
            <p class="truncate text-xs text-neutral-400" :title="batch.files.map(file => file.name).join(', ')">{{ batch.files.map(file => file.name).join(', ') }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ t('payroll_imports.attendance.history.persons', { count: batch.person_count }) }}</span>
            <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ t('payroll_imports.attendance.history.metrics', { count: batch.metric_count }) }}</span>
            <span class="rounded-full bg-payroll-50 px-2 py-0.5 text-xs text-payroll-700">{{ t('payroll_imports.attendance.history.inputs', { count: batch.input_count }) }}</span>
            <button type="button" :class="btnOutlineSm('neutral')" :aria-expanded="expanded === batch.id" @click="toggle(batch)">
              <svg class="h-3.5 w-3.5 transition-transform" :class="expanded === batch.id ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
              {{ t(expanded === batch.id ? 'payroll_imports.attendance.history.hide' : 'payroll_imports.attendance.history.show') }}
            </button>
          </div>
        </div>

        <div v-if="expanded === batch.id" class="border-t border-neutral-100 bg-neutral-50/60 px-4 py-3">
          <p v-if="detailLoading === batch.id" class="text-sm text-neutral-500">{{ t('payroll_imports.common.working') }}</p>
          <p v-else-if="detailError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ detailError }}</p>
          <p v-else-if="(detail[batch.id] ?? []).length === 0" class="text-sm text-neutral-500">{{ t('payroll_imports.attendance.history.no_rows') }}</p>
          <div v-else class="max-h-96 overflow-auto rounded-lg border border-neutral-200 bg-surface">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.history.columns.employee') }}</th>
                  <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.history.columns.meaning') }}</th>
                  <th class="sticky top-0 bg-surface px-3 py-2 text-right">{{ t('payroll_imports.attendance.history.columns.value') }}</th>
                  <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.history.columns.source') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in detail[batch.id]" :key="`${row.employment_id}-${row.meaning}`">
                  <td class="px-3 py-1.5"><span class="font-medium">{{ row.employee_name }}</span> <span class="text-xs text-neutral-500">{{ personalNumberLabel(t, row.employment_code) }}</span></td>
                  <td class="px-3 py-1.5">{{ meaningLabel(row.meaning) }}</td>
                  <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ row.hours !== null ? formatHours(row.hours, locale) : formatMoneyMinor(row.amount_minor) }}</td>
                  <td class="max-w-64 truncate px-3 py-1.5 font-mono text-xs text-neutral-500" :title="row.source">{{ row.source }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </li>
    </ul>
  </section>
</template>
