<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollTimeCategory,
  type PayrollTimeImportPreview,
  type PayrollTimeOverview,
  type PayrollTimeOverviewItem,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import PayrollFileDropzone, {
  type PayrollFileRejectReason,
} from '@/components/payroll/PayrollFileDropzone.vue'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import {
  formatPayrollMinutes,
  payrollWallTimeToIso,
} from '@/pages/payroll/payrollTime'
import { localPayrollPeriod } from '@/pages/payroll/payrollComponentsUi'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const period = ref(localPayrollPeriod())
const incompleteOnly = ref(false)
const loading = ref(false)
const saving = ref(false)
const overview = ref<PayrollTimeOverview | null>(null)
const editorOpen = ref(false)
const importOpen = ref(false)
const recordType = ref<'entry' | 'shift'>('entry')
const employmentId = ref<number | null>(null)
const category = ref<PayrollTimeCategory>('regular')
const startsAt = ref('')
const endsAt = ref('')
const breakMinutes = ref(0)
const remoteWork = ref(false)
const standbyMinutes = ref(0)
const publish = ref(true)
const timezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Prague')
const importName = ref('')
const importFormat = ref<'csv' | 'xlsx'>('csv')
const importContent = ref('')
const importFileError = ref('')
const importPreview = ref<PayrollTimeImportPreview | null>(null)
const selectedEmploymentIds = ref<number[]>([])
const approvalItem = ref<PayrollTimeOverviewItem | null>(null)
const approvalStandardFund = ref('')
const approvalAgreedFund = ref('')
const approvalWeeklyWork = ref('')
const approvalWorked = ref('')
const approvalUnworkedOccurred = ref<boolean | null>(null)
const approvalObstaclesOccurred = ref<boolean | null>(null)
const approvalUnworkedTotal = ref('')
const approvalUnworkedPaid = ref('')
const approvalDpnWithoutCompensation = ref('')
const approvalDpnWithCompensation = ref('')
const approvalVacation = ref('')
const approvalCare = ref('')
const approvalEmployeeObstacle = ref('')
const approvalEmployerObstacle = ref('')
const approvalNote = ref('')
const reopenItem = ref<PayrollTimeOverviewItem | null>(null)
const reopenReason = ref('')
const reopenError = ref('')

const canWrite = computed(() => auth.canWrite('payroll.time.write'))
const canApprove = computed(() => auth.canWrite('payroll.approve'))
const canReopen = computed(() => auth.canWrite('payroll.reopen'))
const selected = computed(() =>
  overview.value?.items.find(item => item.employment.id === employmentId.value) ?? null,
)
const selectableItems = computed(() =>
  overview.value?.items.filter(item => item.month.status === 'open') ?? [],
)
const allSelectableSelected = computed(() =>
  selectableItems.value.length > 0
  && selectableItems.value.every(item => selectedEmploymentIds.value.includes(item.employment.id)),
)
const approvalConditionalComplete = computed(() =>
  approvalUnworkedOccurred.value !== null
  && approvalObstaclesOccurred.value !== null
  && (!approvalUnworkedOccurred.value || Boolean(approvalUnworkedTotal.value.trim()))
  && (!approvalObstaclesOccurred.value
    || Boolean(
      approvalEmployeeObstacle.value.trim()
      || approvalEmployerObstacle.value.trim(),
    )),
)

const categories: PayrollTimeCategory[] = [
  'regular',
  'overtime',
  'night',
  'weekend',
  'holiday',
  'difficult_environment',
]

async function load() {
  loading.value = true
  try {
    overview.value = await payrollApi.timeMonth(period.value, incompleteOnly.value)
    selectedEmploymentIds.value = []
    if (!employmentId.value && overview.value.items.length > 0) {
      employmentId.value = overview.value.items[0].employment.id
    }
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.load_failed'))
  } finally {
    loading.value = false
  }
}

function setDefaultTimes() {
  const day = `${period.value}-01`
  startsAt.value = `${day}T08:00`
  endsAt.value = `${day}T16:30`
}

function openEditor(item?: PayrollTimeOverviewItem) {
  if (item) employmentId.value = item.employment.id
  setDefaultTimes()
  editorOpen.value = true
}

async function saveRecord() {
  if (!selected.value) return
  const common = {
    employment_id: selected.value.employment.id,
    starts_at: payrollWallTimeToIso(startsAt.value, timezone.value),
    ends_at: payrollWallTimeToIso(endsAt.value, timezone.value),
    timezone: timezone.value,
    break_minutes: breakMinutes.value,
    row_version: 0,
    month_row_version: selected.value.month.row_version,
    supersedes_id: null,
  }
  saving.value = true
  try {
    if (recordType.value === 'shift') {
      await payrollApi.saveShift({
        ...common,
        calendar_id: selected.value.calendar?.id ?? null,
        remote_work: remoteWork.value,
        standby_minutes: standbyMinutes.value,
        publish: publish.value,
      })
    } else {
      await payrollApi.saveTimeEntry({ ...common, category: category.value })
    }
    toast.success(t('payroll.time.saved'))
    editorOpen.value = false
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.save_failed'))
  } finally {
    saving.value = false
  }
}

async function createCalendar(item: PayrollTimeOverviewItem) {
  saving.value = true
  try {
    await payrollApi.saveTimeCalendar(item.employment.id, {
      name: t('payroll.time.calendar.default_name'),
      timezone: 'Europe/Prague',
      schedule_type: 'regular',
      valid_from: `${period.value}-01`,
      valid_to: null,
      row_version: item.calendar?.row_version ?? 0,
      month_row_version: item.month.row_version,
      week_pattern: { 1: 480, 2: 480, 3: 480, 4: 480, 5: 480, 6: 0, 7: 0 },
      days: [],
    })
    toast.success(t('payroll.time.calendar.saved'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.calendar.failed'))
  } finally {
    saving.value = false
  }
}

function openApproval(item: PayrollTimeOverviewItem) {
  const suggestions = item.jmhz_work_summary.preview?.suggestions
  approvalItem.value = item
  approvalStandardFund.value = suggestions?.standard_fund_hours ?? ''
  approvalAgreedFund.value = suggestions?.agreed_fund_hours ?? ''
  approvalWeeklyWork.value = suggestions?.weekly_work_hours ?? ''
  approvalWorked.value = suggestions?.worked_hours ?? ''
  approvalUnworkedOccurred.value = null
  approvalObstaclesOccurred.value = null
  clearConditionalValues()
  approvalNote.value = ''
}

function clearConditionalValues() {
  approvalUnworkedTotal.value = ''
  approvalUnworkedPaid.value = ''
  approvalDpnWithoutCompensation.value = ''
  approvalDpnWithCompensation.value = ''
  approvalVacation.value = ''
  approvalCare.value = ''
  approvalEmployeeObstacle.value = ''
  approvalEmployerObstacle.value = ''
}

function setUnworkedOccurred(value: boolean) {
  const previous = approvalUnworkedOccurred.value
  approvalUnworkedOccurred.value = value
  if (!value) {
    approvalObstaclesOccurred.value = false
    clearConditionalValues()
  } else if (previous === false) {
    approvalObstaclesOccurred.value = null
  }
}

function setObstaclesOccurred(value: boolean) {
  approvalObstaclesOccurred.value = value
  if (!value) {
    approvalEmployeeObstacle.value = ''
    approvalEmployerObstacle.value = ''
  }
}

function optionalHours(value: string): string | null {
  const normalized = value.trim()
  return normalized === '' ? null : normalized
}

function closeApproval() {
  approvalItem.value = null
  approvalStandardFund.value = ''
  approvalAgreedFund.value = ''
  approvalWeeklyWork.value = ''
  approvalWorked.value = ''
  approvalUnworkedOccurred.value = null
  approvalObstaclesOccurred.value = null
  clearConditionalValues()
  approvalNote.value = ''
}

async function approve() {
  const item = approvalItem.value
  const preview = item?.jmhz_work_summary.preview
  if (!item || !preview) return
  if (!approvalConditionalComplete.value
    || approvalUnworkedOccurred.value === null
    || approvalObstaclesOccurred.value === null
  ) return
  saving.value = true
  try {
    await payrollApi.approveTimeMonth(period.value, {
      employment_id: item.employment.id,
      row_version: item.month.row_version,
      jmhz_work_summary: {
        source_snapshot_sha256: preview.source_snapshot_sha256,
        standard_fund_hours: approvalStandardFund.value.trim(),
        agreed_fund_hours: approvalAgreedFund.value.trim(),
        weekly_work_hours: approvalWeeklyWork.value.trim(),
        worked_hours: approvalWorked.value.trim(),
        unworked_hours_occurred: approvalUnworkedOccurred.value,
        work_obstacles_occurred: approvalObstaclesOccurred.value,
        unworked_total_hours: optionalHours(approvalUnworkedTotal.value),
        unworked_paid_hours: optionalHours(approvalUnworkedPaid.value),
        dpn_without_employer_compensation_hours:
          optionalHours(approvalDpnWithoutCompensation.value),
        dpn_with_employer_compensation_hours:
          optionalHours(approvalDpnWithCompensation.value),
        vacation_hours: optionalHours(approvalVacation.value),
        care_hours: optionalHours(approvalCare.value),
        employee_obstacle_paid_hours: optionalHours(approvalEmployeeObstacle.value),
        employer_obstacle_hours: optionalHours(approvalEmployerObstacle.value),
        confirmation_note: approvalNote.value.trim(),
      },
    })
    toast.success(t('payroll.time.approved'))
    closeApproval()
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.approve_failed'))
  } finally {
    saving.value = false
  }
}

function toggleSelection(employmentId: number) {
  selectedEmploymentIds.value = selectedEmploymentIds.value.includes(employmentId)
    ? selectedEmploymentIds.value.filter(id => id !== employmentId)
    : [...selectedEmploymentIds.value, employmentId]
}

function toggleAllVisible() {
  selectedEmploymentIds.value = allSelectableSelected.value
    ? []
    : selectableItems.value.map(item => item.employment.id)
}

async function approveSelected() {
  const items = selectableItems.value.filter(item =>
    selectedEmploymentIds.value.includes(item.employment.id),
  )
  if (items.length === 0) return
  saving.value = true
  let approved = 0
  let failed = 0
  for (const item of items) {
    try {
      await payrollApi.approveTimeMonth(period.value, {
        employment_id: item.employment.id,
        row_version: item.month.row_version,
      })
      approved += 1
    } catch {
      failed += 1
    }
  }
  if (approved > 0) toast.success(t('payroll.time.bulk.approved', { count: approved }))
  if (failed > 0) toast.error(t('payroll.time.bulk.failed', { count: failed }))
  await load()
  saving.value = false
}

function openReopen(item: PayrollTimeOverviewItem) {
  reopenItem.value = item
  reopenReason.value = ''
  reopenError.value = ''
}

function closeReopen() {
  reopenItem.value = null
  reopenReason.value = ''
  reopenError.value = ''
}

async function reopen() {
  const item = reopenItem.value
  const reason = reopenReason.value.trim()
  if (!item || !reason) return
  reopenError.value = ''
  saving.value = true
  try {
    await payrollApi.reopenTimeMonth(period.value, {
      employment_id: item.employment.id,
      row_version: item.month.row_version,
      reason,
    })
    toast.success(t('payroll.time.reopened'))
    closeReopen()
    await load()
  } catch (error: any) {
    reopenError.value = error?.response?.data?.error?.message || t('payroll.time.reopen_failed')
  } finally {
    saving.value = false
  }
}

function clearImportSelection() {
  importName.value = ''
  importContent.value = ''
  importPreview.value = null
}

async function loadImportFile(file: File) {
  importFileError.value = ''
  importName.value = file.name
  importFormat.value = file.name.toLowerCase().endsWith('.xlsx') ? 'xlsx' : 'csv'
  importContent.value = ''
  importPreview.value = null
  try {
    if (importFormat.value === 'csv') {
      importContent.value = await file.text()
    } else {
      const bytes = new Uint8Array(await file.arrayBuffer())
      let binary = ''
      for (const byte of bytes) binary += String.fromCharCode(byte)
      importContent.value = btoa(binary)
    }
  } catch {
    clearImportSelection()
    importFileError.value = t('payroll.time.import.read_failed')
    toast.error(importFileError.value)
  }
}

function rejectImportFile(reason: PayrollFileRejectReason) {
  clearImportSelection()
  importFileError.value = t(`payroll.time.import.${reason}`)
  toast.error(importFileError.value)
}

async function previewImport() {
  saving.value = true
  try {
    importPreview.value = await payrollApi.previewTimeImport({
      period: period.value,
      format: importFormat.value,
      original_name: importName.value,
      content: importContent.value,
    })
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.import.preview_failed'))
  } finally {
    saving.value = false
  }
}

async function applyImport() {
  saving.value = true
  try {
    await payrollApi.importTime({
      period: period.value,
      format: importFormat.value,
      original_name: importName.value,
      content: importContent.value,
    })
    toast.success(t('payroll.time.import.saved'))
    importOpen.value = false
    importPreview.value = null
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.import.failed'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.time.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.time.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button v-if="canWrite" :class="btnOutline('neutral')" @click="importOpen = !importOpen">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
          {{ t('payroll.time.import.button') }}
        </button>
        <button v-if="canWrite" :class="btnFilled('primary')" @click="openEditor()">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>
          {{ t('payroll.time.add') }}
        </button>
      </div>
    </header>

    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
      <div class="flex flex-wrap items-end gap-4">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.period') }}</span>
          <input v-model="period" type="month" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm" @change="load">
        </label>
        <label class="inline-flex h-9 items-center gap-2 text-sm text-neutral-700">
          <input v-model="incompleteOnly" type="checkbox" class="rounded border-neutral-300 text-payroll-600" @change="load">
          {{ t('payroll.time.incomplete_only') }}
        </label>
        <button :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.time.reload') }}
        </button>
        <button
          v-if="canApprove && selectedEmploymentIds.length > 0"
          :class="btnFilled('success')"
          :disabled="saving"
          @click="approveSelected"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
          {{ t('payroll.time.bulk.approve', { count: selectedEmploymentIds.length }) }}
        </button>
      </div>
    </section>

    <section v-if="editorOpen" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.time.editor.title') }}</h2>
        <button :class="btnOutline('neutral')" @click="editorOpen = false">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
      </div>
      <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.employment') }}</span>
          <select v-model="employmentId" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option v-for="item in overview?.items" :key="item.employment.id" :value="item.employment.id">
              {{ item.employment.full_name }} · {{ item.employment.code }}
            </option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.type') }}</span>
          <select v-model="recordType" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option value="entry">{{ t('payroll.time.editor.actual') }}</option>
            <option value="shift">{{ t('payroll.time.editor.shift') }}</option>
          </select>
        </label>
        <label v-if="recordType === 'entry'" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.category') }}</span>
          <select v-model="category" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option v-for="item in categories" :key="item" :value="item">{{ t(`payroll.time.category.${item}`) }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.timezone') }}</span>
          <input v-model="timezone" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.starts') }}</span>
          <input v-model="startsAt" type="datetime-local" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.ends') }}</span>
          <input v-model="endsAt" type="datetime-local" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.break') }}</span>
          <input v-model.number="breakMinutes" type="number" min="0" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label v-if="recordType === 'shift'" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.standby') }}</span>
          <input v-model.number="standbyMinutes" type="number" min="0" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
      </div>
      <div v-if="recordType === 'shift'" class="mt-4 flex flex-wrap gap-5">
        <label class="inline-flex items-center gap-2 text-sm"><input v-model="remoteWork" type="checkbox"> {{ t('payroll.time.editor.remote') }}</label>
        <label class="inline-flex items-center gap-2 text-sm"><input v-model="publish" type="checkbox"> {{ t('payroll.time.editor.publish') }}</label>
      </div>
      <div class="mt-5 flex flex-wrap justify-end gap-2">
        <button :class="btnFilled('primary')" :disabled="saving || !selected || !startsAt || !endsAt" @click="saveRecord">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
      </div>
    </section>

    <section v-if="importOpen" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.time.import.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.time.import.hint') }}</p>
      <div class="mt-4 space-y-4">
        <PayrollFileDropzone
          dropzone-test-id="payroll-time-import-dropzone"
          input-test-id="payroll-time-import-file"
          selected-test-id="payroll-time-import-selected"
          :disabled="saving"
          :selected-file-name="importName"
          :error="importFileError"
          :drop-hint="t('payroll.time.import.drop_hint')"
          :drop-active-hint="t('payroll.time.import.drop_active')"
          :file-hint="t('payroll.time.import.file_limit')"
          :choose-file-text="t('payroll.time.import.choose_file')"
          :selected-text="importName ? t('payroll.time.import.selected_file', { name: importName }) : ''"
          @selected="loadImportFile"
          @rejected="rejectImportFile"
        />
        <div class="flex flex-wrap items-center gap-3">
          <button :class="btnOutline('neutral')" :disabled="saving || !importContent" @click="previewImport">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>
            {{ t('payroll.time.import.preview') }}
          </button>
          <button v-if="importPreview" :class="btnFilled('primary')" :disabled="saving || !importPreview.supported" @click="applyImport">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
            {{ t('payroll.time.import.apply') }}
          </button>
        </div>
      </div>
      <div v-if="importPreview" class="mt-4 rounded-lg bg-neutral-50 p-4 text-sm">
        <p>{{ t('payroll.time.import.summary', importPreview) }}</p>
        <p v-if="!importPreview.supported" class="mt-2 text-warning-700">{{ t('payroll.time.import.xlsx_manual') }}</p>
        <ul v-if="importPreview.errors.length" class="mt-3 space-y-1 text-danger-600">
          <li v-for="error in importPreview.errors" :key="`${error.row_number}-${error.error_code}`">
            {{ t('payroll.time.import.row_error', { row: error.row_number, message: error.error_message }) }}
          </li>
        </ul>
      </div>
    </section>

    <div v-if="loading" class="space-y-3">
      <div v-for="index in 4" :key="index" class="h-28 animate-pulse rounded-xl bg-neutral-100" />
    </div>
    <section v-else-if="!overview?.items.length" class="rounded-xl border border-neutral-200 bg-surface p-8 text-center">
      <h2 class="font-semibold text-neutral-900">{{ t('payroll.time.empty') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.time.empty_hint') }}</p>
    </section>
    <section v-else class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div class="hidden overflow-x-auto md:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
            <th class="w-10 px-4 py-3">
              <input
                type="checkbox"
                :checked="allSelectableSelected"
                :aria-label="t('payroll.time.bulk.select_all')"
                @change="toggleAllVisible"
              >
            </th>
            <th class="px-4 py-3">{{ t('payroll.time.columns.employee') }}</th>
            <th class="px-4 py-3">{{ t('payroll.time.columns.fund') }}</th>
            <th class="px-4 py-3">{{ t('payroll.time.columns.plan') }}</th>
            <th class="px-4 py-3">{{ t('payroll.time.columns.actual') }}</th>
            <th class="px-4 py-3">{{ t('payroll.time.columns.difference') }}</th>
            <th class="px-4 py-3">{{ t('payroll.time.columns.status') }}</th>
            <th class="px-4 py-3 text-right">{{ t('payroll.time.columns.actions') }}</th>
          </tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="item in overview.items" :key="item.employment.id">
              <td class="px-4 py-3">
                <input
                  v-if="item.month.status === 'open'"
                  type="checkbox"
                  :checked="selectedEmploymentIds.includes(item.employment.id)"
                  :aria-label="t('payroll.time.bulk.select', { name: item.employment.full_name })"
                  @change="toggleSelection(item.employment.id)"
                >
              </td>
              <td class="px-4 py-3"><p class="font-medium text-neutral-900">{{ item.employment.full_name }}</p><p class="text-xs text-neutral-500">{{ item.employment.code }}</p></td>
              <td class="px-4 py-3">{{ formatPayrollMinutes(item.summary.fund_minutes) }}</td>
              <td class="px-4 py-3">{{ formatPayrollMinutes(item.summary.planned_minutes) }}</td>
              <td class="px-4 py-3">{{ formatPayrollMinutes(item.summary.actual_minutes) }}</td>
              <td class="px-4 py-3" :class="item.summary.difference_minutes === 0 ? 'text-success-600' : 'text-warning-700'">{{ formatPayrollMinutes(item.summary.difference_minutes) }}</td>
              <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="item.month.status === 'approved' ? 'bg-success-50 text-success-600' : item.summary.incomplete ? 'bg-warning-50 text-warning-700' : 'bg-payroll-50 text-payroll-600'">{{ t(`payroll.time.status.${item.month.status === 'approved' ? 'approved' : item.summary.incomplete ? 'incomplete' : 'open'}`) }}</span></td>
              <td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2">
                <button v-if="canWrite && item.month.status === 'open'" :class="btnOutline('neutral')" @click="openEditor(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.time.add') }}</button>
                <button v-if="canWrite && item.month.status === 'open'" :class="btnOutline('neutral')" :disabled="saving" @click="createCalendar(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t(item.calendar ? 'payroll.time.calendar.new_version' : 'payroll.time.calendar.create') }}</button>
                <button v-if="canApprove && item.month.status === 'open'" :class="btnOutline('success')" :disabled="saving" @click="openApproval(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>{{ t('payroll.time.approve') }}</button>
                <button v-if="canReopen && item.month.status === 'approved'" :class="btnOutline('warning')" :disabled="saving" @click="openReopen(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('payroll.time.reopen') }}</button>
              </div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="space-y-3 p-4 md:hidden">
        <article v-for="item in overview.items" :key="item.employment.id" class="rounded-lg border border-neutral-200 p-4">
          <div class="flex flex-wrap items-start justify-between gap-2"><div class="flex items-start gap-3"><input v-if="item.month.status === 'open'" type="checkbox" class="mt-1" :checked="selectedEmploymentIds.includes(item.employment.id)" :aria-label="t('payroll.time.bulk.select', { name: item.employment.full_name })" @change="toggleSelection(item.employment.id)"><div><h2 class="font-semibold text-neutral-900">{{ item.employment.full_name }}</h2><p class="text-xs text-neutral-500">{{ item.employment.code }}</p></div></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="item.month.status === 'approved' ? 'bg-success-50 text-success-600' : item.summary.incomplete ? 'bg-warning-50 text-warning-700' : 'bg-payroll-50 text-payroll-600'">{{ t(`payroll.time.status.${item.month.status === 'approved' ? 'approved' : item.summary.incomplete ? 'incomplete' : 'open'}`) }}</span></div>
          <dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.time.columns.fund') }}</dt><dd>{{ formatPayrollMinutes(item.summary.fund_minutes) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.time.columns.plan') }}</dt><dd>{{ formatPayrollMinutes(item.summary.planned_minutes) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.time.columns.actual') }}</dt><dd>{{ formatPayrollMinutes(item.summary.actual_minutes) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.time.columns.difference') }}</dt><dd>{{ formatPayrollMinutes(item.summary.difference_minutes) }}</dd></div></dl>
          <div class="mt-4 flex flex-wrap gap-2">
            <button v-if="canWrite && item.month.status === 'open'" :class="btnOutline('neutral')" @click="openEditor(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.time.add') }}</button>
            <button v-if="canWrite && item.month.status === 'open'" :class="btnOutline('neutral')" :disabled="saving" @click="createCalendar(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t(item.calendar ? 'payroll.time.calendar.new_version' : 'payroll.time.calendar.create') }}</button>
            <button v-if="canApprove && item.month.status === 'open'" :class="btnOutline('success')" @click="openApproval(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>{{ t('payroll.time.approve') }}</button>
            <button v-if="canReopen && item.month.status === 'approved'" :class="btnOutline('warning')" @click="openReopen(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('payroll.time.reopen') }}</button>
          </div>
        </article>
      </div>
    </section>

    <Modal
      v-if="approvalItem"
      :title="t('payroll.time.jmhz.title')"
      width-class="max-w-2xl"
      @close="closeApproval"
    >
      <form data-test="jmhz-work-summary-form" class="space-y-4" @submit.prevent="approve">
        <p class="text-sm text-neutral-600">
          {{ approvalItem.employment.full_name }} · {{ approvalItem.employment.code }}
        </p>
        <p class="rounded-lg border border-payroll-200 bg-payroll-50 p-3 text-sm text-payroll-800">
          {{ t('payroll.time.jmhz.hint') }}
        </p>
        <ul
          v-if="approvalItem.jmhz_work_summary.preview?.issues.length"
          class="space-y-1 rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700"
        >
          <li v-for="issue in approvalItem.jmhz_work_summary.preview.issues" :key="issue.code">
            {{ issue.message }}
          </li>
        </ul>
        <p
          v-if="approvalItem.jmhz_work_summary.preview?.requires_unworked_hours_followup"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700"
        >
          {{ t('payroll.time.jmhz.unworked_evidence_hint') }}
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.standard_fund') }}</span>
            <input v-model="approvalStandardFund" data-test="jmhz-standard-fund" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.agreed_fund') }}</span>
            <input v-model="approvalAgreedFund" data-test="jmhz-agreed-fund" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.weekly_work') }}</span>
            <input v-model="approvalWeeklyWork" data-test="jmhz-weekly-work" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.worked') }}</span>
            <input v-model="approvalWorked" data-test="jmhz-worked" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
        </div>
        <p class="text-sm text-neutral-600">
          {{ t('payroll.time.jmhz.evidence_days', { count: approvalItem.jmhz_work_summary.preview?.suggestions.evidence_days ?? 0 }) }}
        </p>
        <fieldset class="space-y-2 rounded-lg border border-neutral-200 p-3">
          <legend class="px-1 text-sm font-medium text-neutral-700">
            {{ t('payroll.time.jmhz.unworked_occurred') }}
          </legend>
          <div class="flex flex-wrap gap-5">
            <label class="inline-flex items-center gap-2 text-sm">
              <input data-test="jmhz-unworked-yes" type="radio" name="jmhz-unworked" :checked="approvalUnworkedOccurred === true" @change="setUnworkedOccurred(true)">
              {{ t('common.yes') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm">
              <input data-test="jmhz-unworked-no" type="radio" name="jmhz-unworked" :checked="approvalUnworkedOccurred === false" @change="setUnworkedOccurred(false)">
              {{ t('common.no') }}
            </label>
          </div>
        </fieldset>
        <div v-if="approvalUnworkedOccurred === true" class="grid grid-cols-1 gap-4 rounded-lg border border-neutral-200 p-3 sm:grid-cols-2">
          <label class="block sm:col-span-2">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.unworked_total') }}</span>
            <input v-model="approvalUnworkedTotal" data-test="jmhz-unworked-total" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.unworked_paid') }}</span>
            <input v-model="approvalUnworkedPaid" data-test="jmhz-unworked-paid" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.dpn_without_compensation') }}</span>
            <input v-model="approvalDpnWithoutCompensation" data-test="jmhz-dpn-without-compensation" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.dpn_with_compensation') }}</span>
            <input v-model="approvalDpnWithCompensation" data-test="jmhz-dpn-with-compensation" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.vacation') }}</span>
            <input v-model="approvalVacation" data-test="jmhz-vacation" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.care') }}</span>
            <input v-model="approvalCare" data-test="jmhz-care" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
        </div>
        <fieldset class="space-y-2 rounded-lg border border-neutral-200 p-3">
          <legend class="px-1 text-sm font-medium text-neutral-700">
            {{ t('payroll.time.jmhz.obstacles_occurred') }}
          </legend>
          <div class="flex flex-wrap gap-5">
            <label class="inline-flex items-center gap-2 text-sm">
              <input data-test="jmhz-obstacles-yes" type="radio" name="jmhz-obstacles" :disabled="approvalUnworkedOccurred !== true" :checked="approvalObstaclesOccurred === true" @change="setObstaclesOccurred(true)">
              {{ t('common.yes') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm">
              <input data-test="jmhz-obstacles-no" type="radio" name="jmhz-obstacles" :checked="approvalObstaclesOccurred === false" @change="setObstaclesOccurred(false)">
              {{ t('common.no') }}
            </label>
          </div>
        </fieldset>
        <div v-if="approvalObstaclesOccurred === true" class="grid grid-cols-1 gap-4 rounded-lg border border-neutral-200 p-3 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.employee_obstacle') }}</span>
            <input v-model="approvalEmployeeObstacle" data-test="jmhz-employee-obstacle" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.employer_obstacle') }}</span>
            <input v-model="approvalEmployerObstacle" data-test="jmhz-employer-obstacle" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
        </div>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.note') }}</span>
          <textarea v-model="approvalNote" data-test="jmhz-note" required minlength="5" maxlength="500" rows="3" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeApproval">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button
            type="submit"
            :class="btnFilled('success')"
            :disabled="saving || !approvalNote.trim() || !approvalConditionalComplete || Boolean(approvalItem.jmhz_work_summary.preview?.issues.length)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
            {{ t('payroll.time.jmhz.confirm') }}
          </button>
        </div>
      </form>
    </Modal>

    <Modal
      v-if="reopenItem"
      :title="t('payroll.time.reopen')"
      width-class="max-w-lg"
      @close="closeReopen"
    >
      <div data-test="reopen-modal">
        <p data-test="reopen-employee" class="mb-4 text-sm text-neutral-600">
          {{ reopenItem.employment.full_name }} · {{ reopenItem.employment.code }}
        </p>
        <form data-test="reopen-form" class="space-y-4" @submit.prevent="reopen">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.time.reopen_reason') }}
            </span>
            <textarea
              v-model="reopenReason"
              data-test="reopen-reason"
              required
              maxlength="1000"
              rows="4"
              class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            />
          </label>
          <p
            v-if="reopenError"
            data-test="reopen-error"
            role="alert"
            class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700"
          >
            {{ reopenError }}
          </p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeReopen">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :class="btnFilled('warning')" :disabled="saving || !reopenReason.trim()">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>
              {{ t('payroll.time.reopen') }}
            </button>
          </div>
        </form>
      </div>
    </Modal>
  </div>
</template>
