<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import {
  payrollAbsenceApi,
  type AbsencePayload,
  type AbsenceType,
  type AverageSnapshot,
  type LeaveEntry,
  type PayrollAbsence,
  type PayrollAbsenceEmployment,
} from '@/api/payrollAbsences'

const { t, locale } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const today = new Date()
const year = today.getFullYear()
const month = String(today.getMonth() + 1).padStart(2, '0')
const monthStart = `${year}-${month}-01`
function localDate(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
const monthEnd = localDate(new Date(year, today.getMonth() + 1, 0))
const applicationQuarter = Math.floor(today.getMonth() / 3) + 1
const applicationQuarterStartMonth = (applicationQuarter - 1) * 3
const decisiveFrom = localDate(new Date(year, applicationQuarterStartMonth - 3, 1))
const decisiveTo = localDate(new Date(year, applicationQuarterStartMonth, 0))

const loading = ref(true)
const saving = ref(false)
const tab = ref<'absences' | 'averages' | 'leave'>('absences')
const employments = ref<PayrollAbsenceEmployment[]>([])
const absences = ref<PayrollAbsence[]>([])
const averages = ref<AverageSnapshot[]>([])
const leaveEntries = ref<LeaveEntry[]>([])
const leaveBalance = ref(0)
const selectedEmploymentId = ref<number | null>(null)
const filterFrom = ref(monthStart)
const filterTo = ref(monthEnd)
const leaveYear = ref(year)
const canWrite = computed(() => auth.canWrite('payroll.time.write'))
const fieldClass = 'mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20'
const textareaClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20'
const absenceTypes: AbsenceType[] = [
  'vacation', 'dpn', 'quarantine', 'ocr', 'long_term_care', 'ppm',
  'paternity', 'parental', 'unpaid_leave', 'employee_obstacle',
  'employer_obstacle', 'other',
]
const leaveEntryTypes = ['carryover', 'adjustment', 'shortening', 'overdrawn', 'payout']

const absenceForm = reactive<AbsencePayload>({
  employment_id: 0,
  absence_type: 'vacation',
  date_from: monthStart,
  date_to: monthStart,
  timezone_name: 'Europe/Prague',
  partial_first_minutes: null,
  partial_last_minutes: null,
  average_snapshot_id: null,
  note: null,
})
const averageForm = reactive({
  employment_id: 0,
  applicable_year: year,
  applicable_quarter: applicationQuarter,
  decisive_from: decisiveFrom,
  decisive_to: decisiveTo,
  gross_earnings_minor: 0,
  longer_period_allocated_minor: 0,
  worked_minutes: 0,
  worked_days: 0,
  probable_hourly_minor: null as number | null,
  rationale: '',
})
const entitlementForm = reactive({
  employment_id: 0,
  leave_year: year,
  weekly_minutes: 2400,
  entitlement_weeks: 4,
  continuous_calendar_days: 365,
  worked_equivalent_minutes: 124800,
  rationale: '',
})
const entryForm = reactive({
  employment_id: 0,
  leave_year: year,
  effective_date: `${year}-01-01`,
  entry_type: 'adjustment',
  minutes_delta: 60,
  reason: '',
})
const dpnReviews = reactive<Record<number, {
  firstDayFullyWorked: boolean
  insuranceConfirmed: boolean
  noConflictingBenefit: boolean
}>>({})

const approvedAverages = computed(() => averages.value.filter(item => item.status === 'approved'))
const employmentOptions = computed(() => employments.value.map(item => ({
  value: item.id,
  label: `${item.full_name} · ${item.code}`,
  secondary: t(`payroll.people.relations.${item.relation_type}`),
})))
const absenceTypeOptions = computed(() => absenceTypes.map(type => ({
  value: type,
  label: t(`payroll_absence.types.${type}`),
})))
const averageOptions = computed(() => approvedAverages.value.map(item => ({
  value: item.id,
  label: `${item.applicable_year}/Q${item.applicable_quarter}`,
  secondary: money(item.average_hourly_minor),
})))
const leaveEntryTypeOptions = computed(() => leaveEntryTypes.map(type => ({
  value: type,
  label: t(`payroll_absence.leave.types.${type}`),
})))
const needsAverage = computed(() =>
  ['vacation', 'dpn', 'quarantine', 'employee_obstacle', 'employer_obstacle']
    .includes(absenceForm.absence_type),
)
const canCreateAbsence = computed(() =>
  !saving.value && (!needsAverage.value || absenceForm.average_snapshot_id !== null),
)

async function loadContext() {
  employments.value = await payrollAbsenceApi.context()
  if (employments.value.length > 0 && selectedEmploymentId.value === null) {
    selectedEmploymentId.value = employments.value[0].id
  }
}

async function loadData() {
  if (selectedEmploymentId.value === null) return
  loading.value = true
  try {
    const employmentId = selectedEmploymentId.value
    const [absenceData, averageData, leaveData] = await Promise.all([
      payrollAbsenceApi.absences(filterFrom.value, filterTo.value, employmentId),
      payrollAbsenceApi.averages(employmentId),
      payrollAbsenceApi.leaveLedger(employmentId, leaveYear.value),
    ])
    absences.value = absenceData
    for (const item of absenceData) {
      if (['dpn', 'quarantine'].includes(item.absence_type) && !dpnReviews[item.id]) {
        dpnReviews[item.id] = {
          firstDayFullyWorked: false,
          insuranceConfirmed: false,
          noConflictingBenefit: false,
        }
      }
    }
    averages.value = averageData
    leaveEntries.value = leaveData.entries
    leaveBalance.value = leaveData.balance_minutes
    absenceForm.employment_id = employmentId
    averageForm.employment_id = employmentId
    entitlementForm.employment_id = employmentId
    entryForm.employment_id = employmentId
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.load_failed'))
  } finally {
    loading.value = false
  }
}

async function createAbsence() {
  if (!canCreateAbsence.value) {
    toast.error(t('payroll_absence.messages.average_required'))
    return
  }
  saving.value = true
  try {
    await payrollAbsenceApi.createAbsence({
      ...absenceForm,
      average_snapshot_id: needsAverage.value ? absenceForm.average_snapshot_id : null,
    })
    toast.success(t('payroll_absence.messages.absence_created'))
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function decide(item: PayrollAbsence, decision: 'approved' | 'rejected') {
  const review = dpnReviews[item.id]
  saving.value = true
  try {
    await payrollAbsenceApi.decide(item.id, {
      row_version: item.row_version,
      decision,
      first_day_fully_worked: review?.firstDayFullyWorked ?? false,
      insurance_eligibility_confirmed: review?.insuranceConfirmed ?? false,
      conflicting_benefit_excluded: review?.noConflictingBenefit ?? false,
    })
    toast.success(t(`payroll_absence.messages.${decision}`))
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function cancel(item: PayrollAbsence) {
  if (!window.confirm(t('payroll_absence.absences.cancel_confirm'))) return
  saving.value = true
  try {
    await payrollAbsenceApi.cancel(item.id, item.row_version)
    toast.success(t('payroll_absence.messages.cancelled'))
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function createAverage() {
  saving.value = true
  try {
    await payrollAbsenceApi.createAverage({ ...averageForm })
    toast.success(t('payroll_absence.messages.average_created'))
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function approveAverage(item: AverageSnapshot) {
  saving.value = true
  try {
    await payrollAbsenceApi.approveAverage(item.id, item.row_version)
    toast.success(t('payroll_absence.messages.average_approved'))
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function createEntitlement() {
  saving.value = true
  try {
    await payrollAbsenceApi.createEntitlement({ ...entitlementForm })
    toast.success(t('payroll_absence.messages.entitlement_created'))
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function createEntry() {
  saving.value = true
  try {
    await payrollAbsenceApi.createLeaveEntry({ ...entryForm })
    toast.success(t('payroll_absence.messages.entry_created'))
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

function money(minor: number | null) {
  if (minor === null) return '—'
  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'CZK' }).format(minor / 100)
}

function minutes(value: number) {
  const sign = value < 0 ? '−' : ''
  const absolute = Math.abs(value)
  return `${sign}${Math.floor(absolute / 60)}:${String(absolute % 60).padStart(2, '0')}`
}

watch(selectedEmploymentId, () => {
  absenceForm.average_snapshot_id = null
  void loadData()
})
watch(leaveYear, loadData)
watch(
  [() => averageForm.applicable_year, () => averageForm.applicable_quarter],
  ([selectedYear, selectedQuarter]) => {
    if (!Number.isInteger(selectedYear) || !Number.isInteger(selectedQuarter)
      || selectedQuarter < 1 || selectedQuarter > 4) return
    const startMonth = (selectedQuarter - 1) * 3
    averageForm.decisive_from = localDate(new Date(selectedYear, startMonth - 3, 1))
    averageForm.decisive_to = localDate(new Date(selectedYear, startMonth, 0))
  },
)
onMounted(async () => {
  try {
    await loadContext()
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.load_failed'))
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll_absence.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_absence.subtitle') }}</p>
      </div>
      <span class="rounded-full bg-warning-50 px-3 py-1 text-xs font-medium text-warning-700">
        {{ t('payroll_absence.manual_review') }}
      </span>
    </header>

    <section class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800">
      {{ t('payroll_absence.review_notice') }}
    </section>

    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
      <div class="grid gap-4 md:grid-cols-4">
        <div class="md:col-span-2">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.employment') }}</span>
          <SearchableSelect
            v-model="selectedEmploymentId"
            :options="employmentOptions"
            :clearable="false"
            accent="payroll"
            :aria-label="t('payroll_absence.employment')"
          />
        </div>
        <label>
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.from') }}</span>
          <input v-model="filterFrom" type="date" :class="fieldClass">
        </label>
        <label>
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.to') }}</span>
          <input v-model="filterTo" type="date" :class="fieldClass">
        </label>
      </div>
      <div class="mt-3 flex flex-wrap justify-end">
        <button :class="btnOutline('neutral')" :disabled="loading" @click="loadData">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>
    </section>

    <nav class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200" :aria-label="t('payroll_absence.tabs.label')">
      <button
        v-for="name in (['absences', 'averages', 'leave'] as const)"
        :key="name"
        type="button"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="tab === name
          ? 'border-payroll-600 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="tab = name"
      >
        {{ t(`payroll_absence.tabs.${name}`) }}
      </button>
    </nav>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2">
      <div v-for="index in 4" :key="index" class="h-40 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else-if="tab === 'absences'">
      <section v-if="canWrite" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_absence.absences.new') }}</h2>
        <form class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="createAbsence">
          <div>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.type') }}</span>
            <SearchableSelect
              v-model="absenceForm.absence_type"
              :options="absenceTypeOptions"
              :clearable="false"
              accent="payroll"
              :aria-label="t('payroll_absence.absences.type')"
            />
          </div>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.from') }}</span>
            <input v-model="absenceForm.date_from" required type="date" :class="fieldClass">
          </label>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.to') }}</span>
            <input v-model="absenceForm.date_to" required type="date" :class="fieldClass">
          </label>
          <div v-if="needsAverage">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.average') }}</span>
            <SearchableSelect
              v-model="absenceForm.average_snapshot_id"
              :options="averageOptions"
              :placeholder="t('payroll_absence.select')"
              accent="payroll"
              :aria-label="t('payroll_absence.absences.average')"
            />
          </div>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.partial_first') }}</span>
            <input v-model.number="absenceForm.partial_first_minutes" min="1" type="number" :class="fieldClass">
          </label>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.partial_last') }}</span>
            <input v-model.number="absenceForm.partial_last_minutes" min="1" type="number" :class="fieldClass">
          </label>
          <label class="sm:col-span-2">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.note') }}</span>
            <input v-model="absenceForm.note" maxlength="1000" type="text" :class="fieldClass">
          </label>
          <div class="flex flex-wrap justify-end sm:col-span-2 lg:col-span-4">
            <button :class="btnFilled('primary')" :disabled="!canCreateAbsence">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.plus" />
              </svg>
              {{ t('payroll_absence.absences.create') }}
            </button>
          </div>
        </form>
      </section>

      <section>
        <h2 class="mb-3 text-lg font-semibold text-neutral-900">{{ t('payroll_absence.absences.list') }}</h2>
        <p v-if="absences.length === 0" class="rounded-xl border border-dashed border-neutral-300 p-8 text-center text-sm text-neutral-500">
          {{ t('payroll_absence.absences.empty') }}
        </p>
        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <article v-for="item in absences" :key="item.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h3 class="font-semibold text-neutral-900">{{ t(`payroll_absence.types.${item.absence_type}`) }}</h3>
                <p class="mt-0.5 text-sm text-neutral-500">{{ item.full_name }} · {{ item.employment_code }}</p>
              </div>
              <span class="rounded-full px-2 py-1 text-xs font-medium" :class="{
                'bg-warning-50 text-warning-700': item.status === 'requested',
                'bg-success-50 text-success-700': item.status === 'approved',
                'bg-danger-50 text-danger-700': item.status === 'rejected',
                'bg-neutral-100 text-neutral-600': item.status === 'cancelled',
              }">
                {{ t(`payroll_absence.status.${item.status}`) }}
              </span>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-neutral-500">{{ t('payroll_absence.period') }}</dt><dd class="font-medium text-neutral-900">{{ item.date_from }} – {{ item.date_to }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('payroll_absence.absences.average') }}</dt><dd class="font-medium text-neutral-900">{{ money(item.average_hourly_minor) }}</dd></div>
            </dl>
            <p v-if="item.note" class="mt-3 text-sm text-neutral-600">{{ item.note }}</p>
            <div v-if="item.correction_pending" class="mt-3 rounded-lg bg-warning-50 p-2 text-xs text-warning-800">
              {{ t('payroll_absence.absences.correction_pending') }}
            </div>
            <div
              v-if="item.status === 'requested' && ['dpn', 'quarantine'].includes(item.absence_type)"
              class="mt-4 space-y-2 rounded-lg border border-warning-200 bg-warning-50 p-3 text-xs text-warning-900"
            >
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].insuranceConfirmed" type="checkbox"> {{ t('payroll_absence.dpn.insurance') }}</label>
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].noConflictingBenefit" type="checkbox"> {{ t('payroll_absence.dpn.no_conflict') }}</label>
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].firstDayFullyWorked" type="checkbox"> {{ t('payroll_absence.dpn.first_day_worked') }}</label>
            </div>
            <div v-if="canWrite && item.status === 'requested'" class="mt-4 flex flex-wrap gap-2">
              <button :class="btnFilled('success')" :disabled="saving" @click="decide(item, 'approved')">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                {{ t('payroll_absence.actions.approve') }}
              </button>
              <button :class="btnOutline('danger')" :disabled="saving" @click="decide(item, 'rejected')">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
                {{ t('payroll_absence.actions.reject') }}
              </button>
            </div>
            <div v-else-if="canWrite && item.status === 'approved'" class="mt-4 flex flex-wrap">
              <button :class="btnOutline('warning')" :disabled="saving" @click="cancel(item)">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>
                {{ t('payroll_absence.actions.cancel') }}
              </button>
            </div>
          </article>
        </div>
      </section>
    </template>

    <template v-else-if="tab === 'averages'">
      <section v-if="canWrite" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_absence.averages.new') }}</h2>
        <form class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="createAverage">
          <label><span class="form-label">{{ t('payroll_absence.averages.year') }}</span><input v-model.number="averageForm.applicable_year" min="2026" max="2026" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.quarter') }}</span><input v-model.number="averageForm.applicable_quarter" min="1" max="4" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.decisive_from') }}</span><input v-model="averageForm.decisive_from" type="date" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.decisive_to') }}</span><input v-model="averageForm.decisive_to" type="date" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.gross_minor') }}</span><input v-model.number="averageForm.gross_earnings_minor" min="0" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.allocated_minor') }}</span><input v-model.number="averageForm.longer_period_allocated_minor" min="0" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.worked_minutes') }}</span><input v-model.number="averageForm.worked_minutes" min="0" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.worked_days') }}</span><input v-model.number="averageForm.worked_days" min="0" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.probable_minor') }}</span><input v-model.number="averageForm.probable_hourly_minor" min="1" type="number" :class="fieldClass"></label>
          <label class="sm:col-span-2 lg:col-span-3"><span class="form-label">{{ t('payroll_absence.averages.rationale') }}</span><input v-model="averageForm.rationale" maxlength="1000" type="text" :class="fieldClass"></label>
          <div class="flex flex-wrap justify-end sm:col-span-2 lg:col-span-4">
            <button :class="btnFilled('primary')" :disabled="saving"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll_absence.averages.create') }}</button>
          </div>
        </form>
      </section>
      <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article v-for="item in averages" :key="item.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <div class="flex justify-between gap-3"><h3 class="font-semibold text-neutral-900">{{ item.applicable_year }}/Q{{ item.applicable_quarter }}</h3><span class="text-xs text-neutral-500">{{ t(`payroll_absence.average_source.${item.source_kind}`) }}</span></div>
          <p class="mt-3 text-2xl font-semibold text-payroll-600">{{ money(item.average_hourly_minor) }}</p>
          <p class="mt-1 text-sm text-neutral-500">{{ t(`payroll_absence.average_status.${item.status}`) }}</p>
          <button v-if="canWrite && item.status === 'manual_review'" :class="btnFilled('success')" class="mt-4" :disabled="saving" @click="approveAverage(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('payroll_absence.actions.approve') }}</button>
        </article>
      </section>
    </template>

    <template v-else>
      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div><h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_absence.leave.balance') }}</h2><p class="mt-1 text-3xl font-semibold text-payroll-600">{{ minutes(leaveBalance) }}</p></div>
          <label><span class="form-label">{{ t('payroll_absence.leave.year') }}</span><input v-model.number="leaveYear" min="2024" max="2026" type="number" :class="[fieldClass, 'w-32']"></label>
        </div>
      </section>
      <div v-if="canWrite" class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <h2 class="font-semibold text-neutral-900">{{ t('payroll_absence.leave.entitlement') }}</h2>
          <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="createEntitlement">
            <label><span class="form-label">{{ t('payroll_absence.leave.weekly_minutes') }}</span><input v-model.number="entitlementForm.weekly_minutes" min="1" type="number" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.weeks') }}</span><input v-model.number="entitlementForm.entitlement_weeks" min="1" type="number" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.duration_days') }}</span><input v-model.number="entitlementForm.continuous_calendar_days" min="1" type="number" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.worked_equivalent') }}</span><input v-model.number="entitlementForm.worked_equivalent_minutes" min="1" type="number" :class="fieldClass"></label>
            <label class="sm:col-span-2"><span class="form-label">{{ t('payroll_absence.leave.rationale') }}</span><textarea v-model="entitlementForm.rationale" required maxlength="1000" :class="textareaClass" rows="2" /></label>
            <div class="flex flex-wrap justify-end sm:col-span-2"><button :class="btnFilled('primary')" :disabled="saving"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll_absence.leave.calculate') }}</button></div>
          </form>
        </section>
        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <h2 class="font-semibold text-neutral-900">{{ t('payroll_absence.leave.manual_entry') }}</h2>
          <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="createEntry">
            <div><span class="form-label">{{ t('payroll_absence.leave.entry_type') }}</span><SearchableSelect v-model="entryForm.entry_type" :options="leaveEntryTypeOptions" :clearable="false" accent="payroll" :aria-label="t('payroll_absence.leave.entry_type')" /></div>
            <label><span class="form-label">{{ t('payroll_absence.leave.effective_date') }}</span><input v-model="entryForm.effective_date" type="date" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.minutes_delta') }}</span><input v-model.number="entryForm.minutes_delta" type="number" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.reason') }}</span><input v-model="entryForm.reason" required maxlength="1000" :class="fieldClass"></label>
            <div class="flex flex-wrap justify-end sm:col-span-2"><button :class="btnFilled('primary')" :disabled="saving"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll_absence.leave.add') }}</button></div>
          </form>
        </section>
      </div>
      <section>
        <h2 class="mb-3 text-lg font-semibold text-neutral-900">{{ t('payroll_absence.leave.ledger') }}</h2>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <article v-for="entry in leaveEntries" :key="entry.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <div class="flex justify-between gap-3"><h3 class="font-medium text-neutral-900">{{ t(`payroll_absence.leave.types.${entry.entry_type}`) }}</h3><strong :class="entry.minutes_delta < 0 ? 'text-danger-600' : 'text-success-600'">{{ minutes(entry.minutes_delta) }}</strong></div>
            <p class="mt-2 text-xs text-neutral-500">{{ entry.effective_date }}</p><p class="mt-2 text-sm text-neutral-600">{{ entry.reason }}</p>
          </article>
        </div>
      </section>
    </template>
  </div>
</template>
