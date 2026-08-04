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
  parsePayrollHoursToMilli,
  payrollMinorToInput,
} from '@/pages/payroll/payrollComponentsUi'

interface UiRow extends PayrollQuickInputRow {
  baseAmount: string
  overtimeHours: string
  overtimeAmount: string
  bonusAmount: string
}

type ValidationCode =
  | 'amount_required'
  | 'amount_format'
  | 'amount_non_negative'
  | 'amount_limit'
  | 'hours_required'
  | 'hours_format'
  | 'hours_non_negative'
  | 'hours_limit'

const MAX_AMOUNT_MINOR = 1_000_000_000_000
const MAX_OVERTIME_HOURS_MILLI = 1_000_000
const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const period = ref(localPayrollPeriod())
const loading = ref(false)
const saving = ref(false)
const rows = ref<UiRow[]>([])
const saveError = ref<string | null>(null)
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
  return parsePayrollHoursToMilli(row.overtimeHours)
}

function amountError(value: string): ValidationCode | null {
  if (value.trim() === '') return 'amount_required'
  const parsed = parsedAmount(value)
  if (parsed === null) return 'amount_format'
  if (parsed < 0) return 'amount_non_negative'
  if (parsed > MAX_AMOUNT_MINOR) return 'amount_limit'
  return null
}

function hoursError(value: string): ValidationCode | null {
  const normalized = value.trim()
  if (normalized === '') return 'hours_required'
  if (normalized.startsWith('-')) return 'hours_non_negative'
  const parsed = parsePayrollHoursToMilli(normalized)
  if (parsed === null) return 'hours_format'
  if (parsed > MAX_OVERTIME_HOURS_MILLI) return 'hours_limit'
  return null
}

function baseError(row: UiRow): ValidationCode | null {
  return row.base_managed_elsewhere || !editable(row.inputs.base)
    ? null
    : amountError(row.baseAmount)
}

function bonusError(row: UiRow): ValidationCode | null {
  return row.bonus_managed_elsewhere || !editable(row.inputs.bonus)
    ? null
    : amountError(row.bonusAmount)
}

function overtimeError(row: UiRow): ValidationCode | null {
  if (row.overtime_managed_elsewhere || !editable(row.inputs.overtime)) return null
  return row.overtime_mode === 'hours'
    ? hoursError(row.overtimeHours)
    : amountError(row.overtimeAmount)
}

function rowInvalid(row: UiRow): boolean {
  return baseError(row) !== null
    || overtimeError(row) !== null
    || bonusError(row) !== null
}

const hasInvalidRows = computed(() => rows.value.some(rowInvalid))
const invalidFieldCount = computed(() => rows.value.reduce(
  (count, row) => count
    + Number(baseError(row) !== null)
    + Number(overtimeError(row) !== null)
    + Number(bonusError(row) !== null),
  0,
))

function validationMessage(code: ValidationCode | null): string {
  return code === null ? '' : t(`payroll.quick_inputs.validation.${code}`)
}

function fieldClass(error: ValidationCode | null, alignRight = false): string[] {
  return [
    'h-10 rounded-md border bg-surface px-3 text-sm text-neutral-900 outline-none',
    'focus:ring-2 disabled:cursor-not-allowed disabled:bg-neutral-50 disabled:text-neutral-500',
    alignRight ? 'text-right tabular-nums' : '',
    error === null
      ? 'border-neutral-300 focus:border-payroll-500 focus:ring-payroll-500/20'
      : 'border-danger-400 focus:border-danger-500 focus:ring-danger-500/20',
  ]
}

function modeButtonClass(active: boolean): string[] {
  return [
    'h-9 cursor-pointer rounded-md border px-3 text-xs font-medium transition-colors',
    'disabled:cursor-not-allowed disabled:opacity-50',
    active
      ? 'border-payroll-600 bg-payroll-50 text-payroll-700'
      : 'border-neutral-300 bg-surface text-neutral-600 hover:border-payroll-300 hover:text-payroll-700',
  ]
}

function validAmount(value: string): number {
  const parsed = parsedAmount(value)
  return parsed !== null && parsed >= 0 && parsed <= MAX_AMOUNT_MINOR ? parsed : 0
}

function overtimePreview(row: UiRow): number {
  if (row.overtime_mode === 'amount') return validAmount(row.overtimeAmount)
  const hours = parsedHoursMilli(row)
  if (hours === null || hours > MAX_OVERTIME_HOURS_MILLI) return 0
  if (row.inputs.overtime && row.inputs.overtime.quantity_milliunits === hours) {
    return row.inputs.overtime.amount_minor
  }
  if (!row.overtime_hourly_rate_minor) return 0
  return Math.round(row.overtime_hourly_rate_minor * ((hours ?? 0) / 1000) * 1.25)
}

function grossPreview(row: UiRow): number {
  return validAmount(row.baseAmount)
    + overtimePreview(row)
    + validAmount(row.bonusAmount)
    + row.other_amount_minor
}

async function load(): Promise<void> {
  const requestedPeriod = period.value
  const generation = ++loadGeneration
  loading.value = true
  saveError.value = null
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
  saveError.value = null
  try {
    const month = await payrollApi.saveQuickInputs(payload())
    if (generation !== loadGeneration || period.value !== requestedPeriod
      || month.period !== requestedPeriod) return
    rows.value = month.items.map(toUi)
    toast.success(t('payroll.quick_inputs.saved'))
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.quick_inputs.save_failed'))
    toast.error(saveError.value)
  } finally {
    saving.value = false
  }
}

function setOvertimeMode(row: UiRow, mode: 'hours' | 'amount'): void {
  if (mode === 'hours' && !row.overtime_hours_available) return
  row.overtime_mode = mode
  saveError.value = null
}

function clearSaveError(): void {
  saveError.value = null
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

    <div
      v-if="!canWrite"
      class="rounded-xl border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-600"
    >
      {{ t('payroll.quick_inputs.readonly_hint') }}
    </div>

    <div
      v-if="saveError"
      class="rounded-xl border border-danger-200 bg-danger-50 p-4"
      role="alert"
      data-testid="quick-payroll-save-error"
    >
      <p class="font-medium text-danger-800">{{ t('payroll.quick_inputs.save_error_title') }}</p>
      <p class="mt-1 text-sm text-danger-700">{{ saveError }}</p>
    </div>

    <div
      v-if="rows.length && hasInvalidRows"
      class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800"
      role="alert"
      data-testid="quick-payroll-validation-summary"
    >
      {{ t('payroll.quick_inputs.validation_summary', { count: invalidFieldCount }) }}
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
                <th class="px-4 py-3">{{ t('payroll.quick_inputs.base_amount') }}</th>
                <th class="px-4 py-3">{{ t('payroll.quick_inputs.overtime') }}</th>
                <th class="px-4 py-3">{{ t('payroll.quick_inputs.bonus_amount') }}</th>
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
                </td>
                <td class="px-4 py-4">
                  <input
                    :data-testid="`quick-base-${row.employment_id}`"
                    v-model="row.baseAmount"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-label="t('payroll.quick_inputs.base_amount')"
                    :aria-invalid="baseError(row) !== null"
                    :aria-describedby="baseError(row) ? `quick-base-error-${row.employment_id}` : undefined"
                    :class="[fieldClass(baseError(row), true), 'w-36']"
                    :disabled="loading || saving || !canWrite || row.base_managed_elsewhere || !editable(row.inputs.base)"
                    @input="clearSaveError"
                  >
                  <p
                    v-if="baseError(row)"
                    :id="`quick-base-error-${row.employment_id}`"
                    class="mt-1 max-w-40 text-xs text-danger-700"
                  >
                    {{ validationMessage(baseError(row)) }}
                  </p>
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-wrap gap-1" role="group" :aria-label="t('payroll.quick_inputs.overtime_mode')">
                    <button
                      :data-testid="`overtime-mode-hours-${row.employment_id}`"
                      type="button"
                      :class="modeButtonClass(row.overtime_mode === 'hours')"
                      :aria-pressed="row.overtime_mode === 'hours'"
                      :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !row.overtime_hours_available || !editable(row.inputs.overtime)"
                      @click="setOvertimeMode(row, 'hours')"
                    >{{ t('payroll.quick_inputs.hours') }}</button>
                    <button
                      type="button"
                      :class="modeButtonClass(row.overtime_mode === 'amount')"
                      :aria-pressed="row.overtime_mode === 'amount'"
                      :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                      @click="setOvertimeMode(row, 'amount')"
                    >{{ t('payroll.quick_inputs.total_amount') }}</button>
                  </div>
                  <input
                    v-if="row.overtime_mode === 'hours'"
                    v-model="row.overtimeHours"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-label="t('payroll.quick_inputs.overtime_hours')"
                    :aria-invalid="overtimeError(row) !== null"
                    :aria-describedby="overtimeError(row) ? `quick-overtime-error-${row.employment_id}` : undefined"
                    :class="[fieldClass(overtimeError(row), true), 'mt-2 w-36']"
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                    @input="clearSaveError"
                  >
                  <input
                    v-else
                    v-model="row.overtimeAmount"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-label="t('payroll.quick_inputs.overtime_amount')"
                    :aria-invalid="overtimeError(row) !== null"
                    :aria-describedby="overtimeError(row) ? `quick-overtime-error-${row.employment_id}` : undefined"
                    :class="[fieldClass(overtimeError(row), true), 'mt-2 w-36']"
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                    @input="clearSaveError"
                  >
                  <p
                    v-if="overtimeError(row)"
                    :id="`quick-overtime-error-${row.employment_id}`"
                    class="mt-1 max-w-56 text-xs text-danger-700"
                  >
                    {{ validationMessage(overtimeError(row)) }}
                  </p>
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
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-label="t('payroll.quick_inputs.bonus_amount')"
                    :aria-invalid="bonusError(row) !== null"
                    :aria-describedby="bonusError(row) ? `quick-bonus-error-${row.employment_id}` : undefined"
                    :class="[fieldClass(bonusError(row), true), 'w-36']"
                    :disabled="loading || saving || !canWrite || row.bonus_managed_elsewhere || !editable(row.inputs.bonus)"
                    @input="clearSaveError"
                  >
                  <p
                    v-if="bonusError(row)"
                    :id="`quick-bonus-error-${row.employment_id}`"
                    class="mt-1 max-w-40 text-xs text-danger-700"
                  >
                    {{ validationMessage(bonusError(row)) }}
                  </p>
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
              <div class="text-right">
                <strong class="text-payroll-700">{{ formatMoney(grossPreview(row)) }}</strong>
                <p v-if="row.other_amount_minor" class="mt-1 text-xs text-neutral-500">
                  {{ t('payroll.quick_inputs.other_inputs', { amount: formatMoney(row.other_amount_minor) }) }}
                </p>
              </div>
            </div>
            <p v-for="blocker in row.blockers" :key="blocker" class="mt-2 text-xs text-warning-700">
              {{ t(`payroll.quick_inputs.blockers.${blocker}`) }}
            </p>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.base_amount') }}</span>
                <input
                  :data-testid="`quick-base-mobile-${row.employment_id}`"
                  v-model="row.baseAmount"
                  type="text"
                  inputmode="decimal"
                  autocomplete="off"
                  :aria-invalid="baseError(row) !== null"
                  :class="[fieldClass(baseError(row)), 'w-full']"
                  :disabled="loading || saving || !canWrite || row.base_managed_elsewhere || !editable(row.inputs.base)"
                  @input="clearSaveError"
                >
                <span v-if="baseError(row)" class="mt-1 block text-xs text-danger-700">
                  {{ validationMessage(baseError(row)) }}
                </span>
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.bonus_amount') }}</span>
                <input
                  v-model="row.bonusAmount"
                  type="text"
                  inputmode="decimal"
                  autocomplete="off"
                  :aria-invalid="bonusError(row) !== null"
                  :class="[fieldClass(bonusError(row)), 'w-full']"
                  :disabled="loading || saving || !canWrite || row.bonus_managed_elsewhere || !editable(row.inputs.bonus)"
                  @input="clearSaveError"
                >
                <span v-if="bonusError(row)" class="mt-1 block text-xs text-danger-700">
                  {{ validationMessage(bonusError(row)) }}
                </span>
              </label>
              <div class="sm:col-span-2">
                <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.overtime') }}</span>
                <div class="flex flex-wrap gap-2" role="group" :aria-label="t('payroll.quick_inputs.overtime_mode')">
                  <button type="button" :class="modeButtonClass(row.overtime_mode === 'hours')" :aria-pressed="row.overtime_mode === 'hours'" :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !row.overtime_hours_available || !editable(row.inputs.overtime)" @click="setOvertimeMode(row, 'hours')">{{ t('payroll.quick_inputs.hours') }}</button>
                  <button type="button" :class="modeButtonClass(row.overtime_mode === 'amount')" :aria-pressed="row.overtime_mode === 'amount'" :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)" @click="setOvertimeMode(row, 'amount')">{{ t('payroll.quick_inputs.total_amount') }}</button>
                  <input
                    v-if="row.overtime_mode === 'hours'"
                    v-model="row.overtimeHours"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-label="t('payroll.quick_inputs.overtime_hours')"
                    :aria-invalid="overtimeError(row) !== null"
                    :class="[fieldClass(overtimeError(row)), 'min-w-0 flex-1']"
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                    @input="clearSaveError"
                  >
                  <input
                    v-else
                    v-model="row.overtimeAmount"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-label="t('payroll.quick_inputs.overtime_amount')"
                    :aria-invalid="overtimeError(row) !== null"
                    :class="[fieldClass(overtimeError(row)), 'min-w-0 flex-1']"
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                    @input="clearSaveError"
                  >
                </div>
                <p v-if="overtimeError(row)" class="mt-1 text-xs text-danger-700">
                  {{ validationMessage(overtimeError(row)) }}
                </p>
                <p v-else-if="row.overtime_mode === 'hours' && row.overtime_hourly_rate_minor" class="mt-1 text-xs text-neutral-500">
                  {{ t('payroll.quick_inputs.rate_hint', { rate: formatMoney(row.overtime_hourly_rate_minor) }) }}
                </p>
                <p v-if="!row.overtime_hours_available" class="mt-1 text-xs text-warning-700">{{ t('payroll.quick_inputs.hours_unavailable') }}</p>
              </div>
            </div>
          </article>
        </div>
      </template>
    </section>

    <div v-if="rows.length && canWrite" class="flex justify-end md:sticky md:bottom-4">
      <button data-testid="quick-payroll-save" :class="[btnFilled('primary'), 'w-full sm:w-auto']" :disabled="saving || loading || hasBlockingRows || hasInvalidRows" @click="save">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
        {{ t('payroll.quick_inputs.save_all') }}
      </button>
    </div>
  </div>
</template>
