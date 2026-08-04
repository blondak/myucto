<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollQuickInputRef,
  type PayrollQuickInputRow,
  type PayrollQuickInputSavePayload,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import {
  formatPayrollMinor,
  localPayrollPeriod,
  parsePayrollAmountToMinor,
  payrollMinorToInput,
} from '@/pages/payroll/payrollComponentsUi'

interface UiRow extends PayrollQuickInputRow {
  baseAmount: string
  overtimeHours: string
  overtimeAmount: string
  bonusAmount: string
}

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const period = ref(localPayrollPeriod())
const loading = ref(false)
const saving = ref(false)
const rows = ref<UiRow[]>([])
let loadGeneration = 0
const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))
const hasBlockingRows = computed(() => rows.value.some(row =>
  row.base_conflict || row.overtime_conflict || row.bonus_conflict))

function toUi(row: PayrollQuickInputRow): UiRow {
  return {
    ...row,
    baseAmount: row.base_requires_entry ? '' : payrollMinorToInput(row.base_amount_minor),
    overtimeHours: row.overtime_hours_milli === null
      ? ''
      : String(row.overtime_hours_milli / 1000),
    overtimeAmount: payrollMinorToInput(row.overtime_amount_minor),
    bonusAmount: payrollMinorToInput(row.bonus_amount_minor),
  }
}

function formatMoney(value: number): string {
  return formatPayrollMinor(value, locale.value)
}

function editable(input: PayrollQuickInputRef | null): boolean {
  return input === null || input.status === 'draft'
}

function parsedAmount(value: string): number | null {
  return parsePayrollAmountToMinor(value)
}

function parsedHoursMilli(row: UiRow): number | null {
  if (row.overtimeHours.trim() === '') return null
  const parsed = Number(row.overtimeHours.replace(',', '.'))
  return Number.isFinite(parsed) && parsed >= 0 ? Math.round(parsed * 1000) : null
}

function rowInvalid(row: UiRow): boolean {
  if (!row.base_managed_elsewhere && editable(row.inputs.base)
    && parsedAmount(row.baseAmount) === null) return true
  if (!row.bonus_managed_elsewhere && editable(row.inputs.bonus)
    && parsedAmount(row.bonusAmount) === null) return true
  if (row.overtime_managed_elsewhere || !editable(row.inputs.overtime)) return false
  return row.overtime_mode === 'hours'
    ? parsedHoursMilli(row) === null
    : parsedAmount(row.overtimeAmount) === null
}

const hasInvalidRows = computed(() => rows.value.some(rowInvalid))

function overtimePreview(row: UiRow): number {
  if (row.overtime_mode === 'amount') return parsedAmount(row.overtimeAmount) ?? 0
  const hours = parsedHoursMilli(row)
  if (row.inputs.overtime && row.inputs.overtime.quantity_milliunits === hours) {
    return row.inputs.overtime.amount_minor
  }
  if (!row.overtime_hourly_rate_minor) return 0
  return Math.round(row.overtime_hourly_rate_minor * ((hours ?? 0) / 1000) * 1.25)
}

function grossPreview(row: UiRow): number {
  return (parsedAmount(row.baseAmount) ?? 0)
    + overtimePreview(row)
    + (parsedAmount(row.bonusAmount) ?? 0)
    + row.other_amount_minor
}

async function load(): Promise<void> {
  const requestedPeriod = period.value
  const generation = ++loadGeneration
  loading.value = true
  try {
    const month = await payrollApi.quickInputs(requestedPeriod)
    if (generation !== loadGeneration || period.value !== requestedPeriod
      || month.period !== requestedPeriod) return
    rows.value = month.items.map(toUi)
  } catch (error) {
    if (generation === loadGeneration) {
      toast.error(apiErrorMessage(error, t('payroll.quick_inputs.load_failed')))
    }
  } finally {
    if (generation === loadGeneration) loading.value = false
  }
}

function payload(): PayrollQuickInputSavePayload {
  return {
    period: period.value,
    rows: rows.value.map(row => ({
      employment_id: row.employment_id,
      base_amount_minor: parsedAmount(row.baseAmount) as number,
      overtime_mode: row.overtime_mode,
      overtime_hours_milli: row.overtime_mode === 'hours' ? parsedHoursMilli(row) : null,
      overtime_amount_minor: row.overtime_mode === 'amount'
        ? parsedAmount(row.overtimeAmount)
        : null,
      overtime_average_snapshot_id: row.overtime_mode === 'hours'
        ? row.overtime_average_snapshot_id
        : null,
      overtime_average_snapshot_version: row.overtime_mode === 'hours'
        ? row.overtime_average_snapshot_version
        : null,
      bonus_amount_minor: parsedAmount(row.bonusAmount) as number,
      versions: {
        base: row.inputs.base?.row_version ?? null,
        overtime: row.inputs.overtime?.row_version ?? null,
        bonus: row.inputs.bonus?.row_version ?? null,
      },
    })),
  }
}

async function save(): Promise<void> {
  if (hasInvalidRows.value) {
    toast.error(t('payroll.quick_inputs.validation_failed'))
    return
  }
  const requestedPeriod = period.value
  const generation = loadGeneration
  saving.value = true
  try {
    const month = await payrollApi.saveQuickInputs(payload())
    if (generation !== loadGeneration || period.value !== requestedPeriod
      || month.period !== requestedPeriod) return
    rows.value = month.items.map(toUi)
    toast.success(t('payroll.quick_inputs.saved'))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.quick_inputs.save_failed')))
  } finally {
    saving.value = false
  }
}

function setOvertimeMode(row: UiRow, mode: 'hours' | 'amount'): void {
  if (mode === 'hours' && !row.overtime_hours_available) return
  row.overtime_mode = mode
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-[1600px] space-y-5 p-4 sm:p-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="page-title">{{ t('payroll.quick_inputs.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.quick_inputs.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.period') }}</span>
          <input
            data-testid="quick-payroll-period"
            v-model="period"
            type="month"
            class="h-10 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            :disabled="loading || saving"
            @change="load"
          >
        </label>
        <button :class="btnOutline('neutral')" :disabled="loading || saving" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
          {{ t('common.refresh') }}
        </button>
      </div>
    </header>

    <div class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 text-sm text-neutral-700">
      {{ t('payroll.quick_inputs.info') }}
    </div>

    <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div v-if="loading" class="p-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else-if="rows.length === 0" class="p-8 text-center">
        <h2 class="font-semibold text-neutral-900">{{ t('payroll.quick_inputs.empty') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.quick_inputs.empty_hint') }}</p>
      </div>
      <template v-else>
        <div data-layout="desktop" class="hidden overflow-x-auto lg:block">
          <table class="min-w-[1120px] w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-4 py-3">{{ t('payroll.quick_inputs.person') }}</th>
                <th class="px-4 py-3">{{ t('payroll.quick_inputs.base') }}</th>
                <th class="px-4 py-3">{{ t('payroll.quick_inputs.overtime') }}</th>
                <th class="px-4 py-3">{{ t('payroll.quick_inputs.bonus') }}</th>
                <th class="px-4 py-3 text-right">{{ t('payroll.quick_inputs.gross_preview') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in rows" :key="row.employment_id" class="align-top">
                <td class="px-4 py-4">
                  <p class="font-semibold text-neutral-900">{{ row.full_name }}</p>
                  <p class="mt-0.5 text-xs text-neutral-500">{{ row.birth_number_masked ?? t('payroll.quick_inputs.identifier_missing') }}</p>
                  <p class="mt-1 text-xs text-neutral-500">{{ row.employment_code }}</p>
                  <p v-for="blocker in row.blockers" :key="blocker" class="mt-2 max-w-xs text-xs text-warning-700">
                    {{ t(`payroll.quick_inputs.blockers.${blocker}`) }}
                  </p>
                  <p v-if="rowInvalid(row)" class="mt-2 text-xs text-danger-700">{{ t('payroll.quick_inputs.invalid_amount') }}</p>
                </td>
                <td class="px-4 py-4">
                  <input
                    :data-testid="`quick-base-${row.employment_id}`"
                    v-model="row.baseAmount"
                    inputmode="decimal"
                    class="h-10 w-36 rounded-md border border-neutral-300 bg-surface px-3 text-right"
                    :disabled="loading || saving || !canWrite || row.base_managed_elsewhere || !editable(row.inputs.base)"
                  >
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-wrap gap-1">
                    <button
                      :data-testid="`overtime-mode-hours-${row.employment_id}`"
                      type="button"
                      class="rounded-md border px-3 py-1.5 text-xs font-medium"
                      :class="row.overtime_mode === 'hours' ? 'border-payroll-600 bg-payroll-50 text-payroll-700' : 'border-neutral-300 text-neutral-600'"
                      :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !row.overtime_hours_available || !editable(row.inputs.overtime)"
                      @click="setOvertimeMode(row, 'hours')"
                    >{{ t('payroll.quick_inputs.hours') }}</button>
                    <button
                      type="button"
                      class="rounded-md border px-3 py-1.5 text-xs font-medium"
                      :class="row.overtime_mode === 'amount' ? 'border-payroll-600 bg-payroll-50 text-payroll-700' : 'border-neutral-300 text-neutral-600'"
                      :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                      @click="setOvertimeMode(row, 'amount')"
                    >{{ t('payroll.quick_inputs.total_amount') }}</button>
                  </div>
                  <input
                    v-if="row.overtime_mode === 'hours'"
                    v-model="row.overtimeHours"
                    inputmode="decimal"
                    class="mt-2 h-10 w-36 rounded-md border border-neutral-300 bg-surface px-3 text-right"
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                  >
                  <input
                    v-else
                    v-model="row.overtimeAmount"
                    inputmode="decimal"
                    class="mt-2 h-10 w-36 rounded-md border border-neutral-300 bg-surface px-3 text-right"
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                  >
                  <p v-if="row.overtime_mode === 'hours' && row.overtime_hourly_rate_minor" class="mt-1 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.rate_hint', { rate: formatMoney(row.overtime_hourly_rate_minor) }) }}
                  </p>
                  <p v-else-if="!row.overtime_hours_available" class="mt-1 max-w-xs text-xs text-warning-700">
                    {{ t('payroll.quick_inputs.hours_unavailable') }}
                  </p>
                </td>
                <td class="px-4 py-4">
                  <input
                    v-model="row.bonusAmount"
                    inputmode="decimal"
                    class="h-10 w-36 rounded-md border border-neutral-300 bg-surface px-3 text-right"
                    :disabled="loading || saving || !canWrite || row.bonus_managed_elsewhere || !editable(row.inputs.bonus)"
                  >
                </td>
                <td class="px-4 py-4 text-right">
                  <p class="text-base font-semibold text-neutral-900">{{ formatMoney(grossPreview(row)) }}</p>
                  <p v-if="row.other_amount_minor" class="mt-1 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.other_inputs', { amount: formatMoney(row.other_amount_minor) }) }}
                  </p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div data-layout="mobile" class="space-y-4 p-4 lg:hidden">
          <article v-for="row in rows" :key="row.employment_id" class="rounded-xl border border-neutral-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-semibold text-neutral-900">{{ row.full_name }}</h2>
                <p class="text-xs text-neutral-500">{{ row.birth_number_masked ?? t('payroll.quick_inputs.identifier_missing') }} · {{ row.employment_code }}</p>
              </div>
              <strong class="text-payroll-700">{{ formatMoney(grossPreview(row)) }}</strong>
            </div>
            <p v-for="blocker in row.blockers" :key="blocker" class="mt-2 text-xs text-warning-700">
              {{ t(`payroll.quick_inputs.blockers.${blocker}`) }}
            </p>
            <p v-if="rowInvalid(row)" class="mt-2 text-xs text-danger-700">{{ t('payroll.quick_inputs.invalid_amount') }}</p>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.base') }}</span>
                <input :data-testid="`quick-base-mobile-${row.employment_id}`" v-model="row.baseAmount" inputmode="decimal" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" :disabled="loading || saving || !canWrite || row.base_managed_elsewhere || !editable(row.inputs.base)">
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.bonus') }}</span>
                <input v-model="row.bonusAmount" inputmode="decimal" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" :disabled="loading || saving || !canWrite || row.bonus_managed_elsewhere || !editable(row.inputs.bonus)">
              </label>
              <div class="sm:col-span-2">
                <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.overtime') }}</span>
                <div class="flex flex-wrap gap-2">
                  <button type="button" class="rounded-md border px-3 py-2 text-xs" :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !row.overtime_hours_available || !editable(row.inputs.overtime)" @click="setOvertimeMode(row, 'hours')">{{ t('payroll.quick_inputs.hours') }}</button>
                  <button type="button" class="rounded-md border px-3 py-2 text-xs" :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)" @click="setOvertimeMode(row, 'amount')">{{ t('payroll.quick_inputs.total_amount') }}</button>
                  <input v-if="row.overtime_mode === 'hours'" v-model="row.overtimeHours" inputmode="decimal" class="h-10 min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-3" :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)">
                  <input v-else v-model="row.overtimeAmount" inputmode="decimal" class="h-10 min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-3" :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)">
                </div>
                <p v-if="!row.overtime_hours_available" class="mt-1 text-xs text-warning-700">{{ t('payroll.quick_inputs.hours_unavailable') }}</p>
              </div>
            </div>
          </article>
        </div>
      </template>
    </section>

    <div v-if="rows.length && canWrite" class="sticky bottom-4 flex justify-end">
      <button data-testid="quick-payroll-save" :class="btnFilled('primary')" :disabled="saving || loading || hasBlockingRows || hasInvalidRows" @click="save">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
        {{ t('payroll.quick_inputs.save_all') }}
      </button>
    </div>
  </div>
</template>
