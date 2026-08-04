<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollComponent,
  type PayrollComponentFrequency,
  type PayrollComponentInclusion,
  type PayrollComponentKind,
  type PayrollComponentPayload,
  type PayrollComponentTaxTreatment,
  type PayrollComponentValueKind,
  type PayrollInput,
  type PayrollInputImportPayload,
  type PayrollInputImportPreview,
  type PayrollInputImportResult,
  type PayrollInputPayload,
  type PayrollInputPreview,
  type PayrollRecurringAllocationRule,
  type PayrollRecurringCalculationKind,
  type PayrollRecurringComponent,
  type PayrollRecurringComponentPayload,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import PayrollFileDropzone, {
  type PayrollFileRejectReason,
} from '@/components/payroll/PayrollFileDropzone.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import {
  canApplyPayrollImport,
  formatPayrollMinor,
  localPayrollPeriod,
  monthStart,
  parsePayrollAmountToMinor,
  payrollEmploymentOptions,
  payrollImportFingerprint,
  payrollImportIssues,
  payrollMinorToInput,
  type PayrollEmploymentOption,
} from '@/pages/payroll/payrollComponentsUi'

type Tab = 'catalog' | 'recurring' | 'inputs' | 'import'

interface ComponentForm extends Omit<PayrollComponentPayload, 'annual_limit_minor'> {
  annual_limit: string
}

interface RecurringForm {
  employment_id: number | null
  component_id: number | null
  calculation_kind: PayrollRecurringCalculationKind
  amount: string
  rate_basis_points: number | null
  valid_from: string
  valid_to: string
  allocation_rule: PayrollRecurringAllocationRule
  maximum_amount: string
  note: string
  is_active: boolean
}

interface InputForm {
  employee_id: number | null
  employment_id: number | null
  component_id: number | null
  source_period: string
  amount: string
  quantity_milliunits: number | null
  external_id: string
}

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const activeTab = ref<Tab>('inputs')
const period = ref(localPayrollPeriod())
const loading = ref(false)
const saving = ref(false)
const components = ref<PayrollComponent[]>([])
const recurring = ref<PayrollRecurringComponent[]>([])
const inputs = ref<PayrollInput[]>([])
const employments = ref<PayrollEmploymentOption[]>([])

const componentEditorOpen = ref(false)
const editingComponent = ref<PayrollComponent | null>(null)
const componentForm = ref<ComponentForm>(newComponentForm())
const recurringEditorOpen = ref(false)
const editingRecurring = ref<PayrollRecurringComponent | null>(null)
const recurringForm = ref<RecurringForm>(newRecurringForm())
const inputEditorOpen = ref(false)
const editingInput = ref<PayrollInput | null>(null)
const inputForm = ref<InputForm>(newInputForm())
const inputPreview = ref<PayrollInputPreview | null>(null)
const inputPreviewFingerprint = ref<string | null>(null)

const importName = ref('')
const importFormat = ref<'csv' | 'xlsx'>('csv')
const importContent = ref('')
const importFileError = ref('')
const importPreview = ref<PayrollInputImportPreview | null>(null)
const importPreviewFingerprint = ref<string | null>(null)
const importResult = ref<PayrollInputImportResult | null>(null)

const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))
const canApprove = computed(() => auth.canWrite('payroll.approve'))
const activeRegularComponents = computed(() =>
  components.value.filter(component => component.is_active && component.frequency_kind === 'regular'),
)
const activeOneOffComponents = computed(() =>
  components.value.filter(component => component.is_active && component.frequency_kind === 'one_off'),
)
const importPayload = computed<PayrollInputImportPayload>(() => ({
  period: period.value,
  format: importFormat.value,
  source_name: importName.value,
  content_base64: importContent.value,
}))
const importFingerprint = computed(() => payrollImportFingerprint(importPayload.value))
const importCanApply = computed(() =>
  importResult.value === null
  && canApplyPayrollImport(importPreview.value, importPreviewFingerprint.value, importFingerprint.value),
)
const importIssues = computed(() => payrollImportIssues(importPreview.value))
const manualInputPayload = computed<PayrollInputPayload | null>(() => {
  const amountMinor = parsePayrollAmountToMinor(inputForm.value.amount)
  if (
    inputForm.value.employee_id === null
    || inputForm.value.employment_id === null
    || inputForm.value.component_id === null
    || amountMinor === null
  ) return null
  return {
    employee_id: inputForm.value.employee_id,
    employment_id: inputForm.value.employment_id,
    component_id: inputForm.value.component_id,
    period: period.value,
    source_period: inputForm.value.source_period || null,
    amount_minor: amountMinor,
    quantity_milliunits: inputForm.value.quantity_milliunits,
    source_kind: 'manual',
    external_id: inputForm.value.external_id.trim() || null,
  }
})
const manualInputFingerprint = computed(() => JSON.stringify(manualInputPayload.value))
const canSaveInput = computed(() =>
  manualInputPayload.value !== null
  && inputPreview.value !== null
  && inputPreviewFingerprint.value === manualInputFingerprint.value
  && inputPreview.value.support_status === 'supported'
  && !inputPreview.value.annual_limit_exceeded,
)

const componentKinds: PayrollComponentKind[] = [
  'base_wage', 'hourly_wage', 'task_wage', 'bonus', 'premium', 'commission',
  'allowance', 'compensation', 'severance', 'competitive_clause', 'backpay',
  'non_cash', 'benefit_meal', 'benefit_vehicle', 'benefit_pension', 'benefit_care',
  'benefit_education', 'benefit_recreation', 'benefit_health', 'risky_savings',
  'travel_reimbursement', 'other',
]
const valueKinds: PayrollComponentValueKind[] = ['monetary', 'non_monetary']
const frequencies: PayrollComponentFrequency[] = ['regular', 'one_off']
const taxTreatments: PayrollComponentTaxTreatment[] = ['included', 'exempt', 'withholding_candidate', 'manual_review']
const inclusionTreatments: PayrollComponentInclusion[] = ['included', 'excluded', 'manual_review']
const calculationKinds: PayrollRecurringCalculationKind[] = ['fixed_amount', 'employment_gross_basis_points', 'manual_review']
const allocationRules: PayrollRecurringAllocationRule[] = ['full_month', 'calendar_days', 'working_days', 'hours', 'manual_review']

function newComponentForm(): ComponentForm {
  return {
    code: '',
    name: '',
    component_kind: 'bonus',
    value_kind: 'monetary',
    frequency_kind: 'one_off',
    tax_treatment: 'included',
    social_participation_treatment: 'included',
    social_treatment: 'included',
    health_participation_treatment: 'included',
    health_treatment: 'included',
    average_earning_treatment: 'included',
    enforcement_treatment: 'included',
    jmhz_treatment: 'included',
    statistics_treatment: 'included',
    accounting_debit_code: null,
    accounting_credit_code: null,
    annual_limit: '',
    valid_from: monthStart(period.value),
    valid_to: null,
    is_active: true,
  }
}

function newRecurringForm(): RecurringForm {
  return {
    employment_id: employments.value[0]?.employment_id ?? null,
    component_id: components.value.find(component =>
      component.is_active && component.frequency_kind === 'regular')?.id ?? null,
    calculation_kind: 'fixed_amount',
    amount: '',
    rate_basis_points: null,
    valid_from: monthStart(period.value),
    valid_to: '',
    allocation_rule: 'full_month',
    maximum_amount: '',
    note: '',
    is_active: true,
  }
}

function newInputForm(): InputForm {
  const employment = employments.value[0]
  return {
    employee_id: employment?.employee_id ?? null,
    employment_id: employment?.employment_id ?? null,
    component_id: components.value.find(component =>
      component.is_active && component.frequency_kind === 'one_off')?.id ?? null,
    source_period: '',
    amount: '',
    quantity_milliunits: null,
    external_id: '',
  }
}

function formatMoney(value: number | null): string {
  return formatPayrollMinor(value, String(locale.value))
}

function employmentLabel(option: PayrollEmploymentOption): string {
  return `${option.full_name} · ${option.code}`
}

function selectedEmploymentChanged(target: InputForm | RecurringForm) {
  if (!('employee_id' in target)) return
  const selected = employments.value.find(item => item.employment_id === target.employment_id)
  target.employee_id = selected?.employee_id ?? null
}

async function loadEmploymentOptions() {
  const people = await payrollApi.people()
  const details = await Promise.all(people.map(person => payrollApi.person(person.id)))
  employments.value = payrollEmploymentOptions(details)
}

async function load() {
  loading.value = true
  try {
    const [catalog, recurringItems, periodInputs] = await Promise.all([
      payrollApi.components(),
      payrollApi.recurringComponents(),
      payrollApi.inputs(period.value),
      loadEmploymentOptions(),
    ])
    components.value = catalog
    recurring.value = recurringItems
    inputs.value = periodInputs
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
  } finally {
    loading.value = false
  }
}

async function reloadPeriod() {
  loading.value = true
  resetImport()
  inputPreview.value = null
  try {
    inputs.value = await payrollApi.inputs(period.value)
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
  } finally {
    loading.value = false
  }
}

function openNewComponent() {
  editingComponent.value = null
  componentForm.value = newComponentForm()
  componentEditorOpen.value = true
}

function editComponent(component: PayrollComponent) {
  editingComponent.value = component
  componentForm.value = {
    code: component.code,
    name: component.name,
    component_kind: component.component_kind,
    value_kind: component.value_kind,
    frequency_kind: component.frequency_kind,
    tax_treatment: component.tax_treatment,
    social_participation_treatment: component.social_participation_treatment,
    social_treatment: component.social_treatment,
    health_participation_treatment: component.health_participation_treatment,
    health_treatment: component.health_treatment,
    average_earning_treatment: component.average_earning_treatment,
    enforcement_treatment: component.enforcement_treatment,
    jmhz_treatment: component.jmhz_treatment,
    statistics_treatment: component.statistics_treatment,
    accounting_debit_code: component.accounting_debit_code,
    accounting_credit_code: component.accounting_credit_code,
    annual_limit: payrollMinorToInput(component.annual_limit_minor),
    valid_from: component.valid_from,
    valid_to: component.valid_to,
    is_active: component.is_active,
  }
  componentEditorOpen.value = true
}

function componentPayload(): PayrollComponentPayload | null {
  const limit = componentForm.value.annual_limit === ''
    ? null
    : parsePayrollAmountToMinor(componentForm.value.annual_limit)
  if (
    !componentForm.value.code.trim()
    || !componentForm.value.name.trim()
    || componentForm.value.annual_limit !== '' && (limit === null || limit <= 0)
  ) {
    return null
  }
  return {
    code: componentForm.value.code.trim().toUpperCase(),
    name: componentForm.value.name.trim(),
    component_kind: componentForm.value.component_kind,
    value_kind: componentForm.value.value_kind,
    frequency_kind: componentForm.value.frequency_kind,
    tax_treatment: componentForm.value.tax_treatment,
    social_participation_treatment:
      componentForm.value.social_participation_treatment,
    social_treatment: componentForm.value.social_treatment,
    health_participation_treatment:
      componentForm.value.health_participation_treatment,
    health_treatment: componentForm.value.health_treatment,
    average_earning_treatment: componentForm.value.average_earning_treatment,
    enforcement_treatment: componentForm.value.enforcement_treatment,
    jmhz_treatment: componentForm.value.jmhz_treatment,
    statistics_treatment: componentForm.value.statistics_treatment,
    accounting_debit_code: componentForm.value.accounting_debit_code?.trim() || null,
    accounting_credit_code: componentForm.value.accounting_credit_code?.trim() || null,
    annual_limit_minor: limit,
    valid_from: componentForm.value.valid_from,
    valid_to: componentForm.value.valid_to || null,
    is_active: componentForm.value.is_active,
  }
}

async function saveComponent() {
  const payload = componentPayload()
  if (!payload) {
    toast.error(t('payroll.components.validation_failed'))
    return
  }
  saving.value = true
  try {
    if (editingComponent.value) {
      await payrollApi.updateComponent(editingComponent.value.id, editingComponent.value.row_version, payload)
    } else {
      await payrollApi.createComponent(payload)
    }
    components.value = await payrollApi.components()
    componentEditorOpen.value = false
    toast.success(t('payroll.components.catalog.saved'))
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.save_failed')))
  } finally {
    saving.value = false
  }
}

function openNewRecurring() {
  editingRecurring.value = null
  recurringForm.value = newRecurringForm()
  recurringEditorOpen.value = true
}

function editRecurring(item: PayrollRecurringComponent) {
  editingRecurring.value = item
  recurringForm.value = {
    employment_id: item.employment_id,
    component_id: item.component_id,
    calculation_kind: item.calculation_kind,
    amount: payrollMinorToInput(item.amount_minor),
    rate_basis_points: item.rate_basis_points,
    valid_from: item.valid_from,
    valid_to: item.valid_to ?? '',
    allocation_rule: item.allocation_rule,
    maximum_amount: payrollMinorToInput(item.maximum_amount_minor),
    note: item.note ?? '',
    is_active: item.is_active,
  }
  recurringEditorOpen.value = true
}

function recurringPayload(): PayrollRecurringComponentPayload | null {
  const form = recurringForm.value
  if (form.employment_id === null || form.component_id === null) return null
  const amount = form.calculation_kind === 'fixed_amount'
    ? parsePayrollAmountToMinor(form.amount)
    : null
  const maximum = form.maximum_amount === '' ? null : parsePayrollAmountToMinor(form.maximum_amount)
  if (
    form.calculation_kind === 'fixed_amount' && amount === null
    || form.calculation_kind === 'employment_gross_basis_points' && (!form.rate_basis_points || form.rate_basis_points < 1 || form.rate_basis_points > 10000)
    || maximum !== null && maximum <= 0
    || form.maximum_amount !== '' && maximum === null
  ) return null
  return {
    employment_id: form.employment_id,
    component_id: form.component_id,
    calculation_kind: form.calculation_kind,
    amount_minor: amount,
    rate_basis_points: form.calculation_kind === 'employment_gross_basis_points' ? form.rate_basis_points : null,
    valid_from: form.valid_from,
    valid_to: form.valid_to || null,
    allocation_rule: form.allocation_rule,
    maximum_amount_minor: maximum,
    note: form.note.trim() || null,
    is_active: form.is_active,
  }
}

async function saveRecurring() {
  const payload = recurringPayload()
  if (!payload) {
    toast.error(t('payroll.components.validation_failed'))
    return
  }
  saving.value = true
  try {
    if (editingRecurring.value) {
      await payrollApi.updateRecurringComponent(editingRecurring.value.id, editingRecurring.value.row_version, payload)
    } else {
      await payrollApi.createRecurringComponent(payload)
    }
    recurring.value = await payrollApi.recurringComponents()
    recurringEditorOpen.value = false
    toast.success(t('payroll.components.recurring.saved'))
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.save_failed')))
  } finally {
    saving.value = false
  }
}

async function materializeRecurring() {
  saving.value = true
  try {
    const result = await payrollApi.materializeRecurringComponents(period.value)
    toast.success(t('payroll.components.recurring.materialized', {
      created_count: result.created_count,
      replayed_count: result.replayed_count,
      manual_review_count: result.manual_review_count,
    }))
    inputs.value = await payrollApi.inputs(period.value)
    activeTab.value = 'inputs'
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.recurring.materialize_failed')))
  } finally {
    saving.value = false
  }
}

function openNewInput() {
  editingInput.value = null
  inputForm.value = newInputForm()
  inputPreview.value = null
  inputEditorOpen.value = true
}

function editInput(input: PayrollInput) {
  editingInput.value = input
  inputForm.value = {
    employee_id: input.employee_id,
    employment_id: input.employment_id,
    component_id: input.component_id,
    source_period: input.source_period_start?.slice(0, 7) ?? '',
    amount: payrollMinorToInput(input.amount_minor),
    quantity_milliunits: input.quantity_milliunits,
    external_id: input.external_id ?? '',
  }
  inputPreview.value = null
  inputEditorOpen.value = true
}

async function previewManualInput() {
  if (!manualInputPayload.value) {
    toast.error(t('payroll.components.validation_failed'))
    return
  }
  saving.value = true
  try {
    inputPreview.value = await payrollApi.previewInput(manualInputPayload.value)
    inputPreviewFingerprint.value = manualInputFingerprint.value
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.inputs.preview_failed')))
  } finally {
    saving.value = false
  }
}

async function saveInput() {
  if (!manualInputPayload.value || !canSaveInput.value) return
  saving.value = true
  try {
    if (editingInput.value) {
      await payrollApi.updateInput(editingInput.value.id, editingInput.value.row_version, manualInputPayload.value)
    } else {
      await payrollApi.createInput(manualInputPayload.value)
    }
    inputs.value = await payrollApi.inputs(period.value)
    inputEditorOpen.value = false
    toast.success(t('payroll.components.inputs.saved'))
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.save_failed')))
  } finally {
    saving.value = false
  }
}

async function approveInput(input: PayrollInput) {
  saving.value = true
  try {
    await payrollApi.approveInput(input.id, input.row_version)
    inputs.value = await payrollApi.inputs(period.value)
    toast.success(t('payroll.components.inputs.approved'))
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.inputs.approve_failed')))
  } finally {
    saving.value = false
  }
}

function resetImport() {
  importPreview.value = null
  importPreviewFingerprint.value = null
  importResult.value = null
}

function clearImportSelection() {
  importName.value = ''
  importContent.value = ''
  resetImport()
}

async function fileAsBase64(file: File): Promise<string> {
  return await new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(reader.error ?? new Error('file_read_failed'))
    reader.onload = () => {
      const result = String(reader.result ?? '')
      const separator = result.indexOf(',')
      resolve(separator >= 0 ? result.slice(separator + 1) : result)
    }
    reader.readAsDataURL(file)
  })
}

async function loadImportFile(file: File) {
  const fileName = file.name.toLowerCase()
  importFileError.value = ''
  importName.value = file.name
  importFormat.value = fileName.endsWith('.xlsx') ? 'xlsx' : 'csv'
  importContent.value = ''
  resetImport()
  try {
    importContent.value = await fileAsBase64(file)
  } catch {
    clearImportSelection()
    importFileError.value = t('payroll.components.import.read_failed')
    toast.error(importFileError.value)
  }
}

function rejectImportFile(reason: PayrollFileRejectReason) {
  clearImportSelection()
  importFileError.value = t(`payroll.components.import.${reason}`)
  toast.error(importFileError.value)
}

async function previewImport() {
  saving.value = true
  try {
    const fingerprint = importFingerprint.value
    importPreview.value = await payrollApi.previewInputImport(importPayload.value)
    importPreviewFingerprint.value = fingerprint
    importResult.value = null
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.import.preview_failed')))
  } finally {
    saving.value = false
  }
}

async function applyImport() {
  if (!importCanApply.value) return
  saving.value = true
  try {
    importResult.value = await payrollApi.applyInputImport(importPayload.value)
    toast.success(t('payroll.components.import.applied', importResult.value))
    inputs.value = await payrollApi.inputs(period.value)
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.import.apply_failed')))
  } finally {
    saving.value = false
  }
}

watch(manualInputFingerprint, () => {
  if (inputPreviewFingerprint.value !== manualInputFingerprint.value) inputPreview.value = null
})

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.components.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.components.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.components.period') }}</span>
          <input v-model="period" type="month" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm" @change="reloadPeriod">
        </label>
        <button :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.components.reload') }}
        </button>
      </div>
    </header>

    <nav
      class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200"
      :aria-label="t('payroll.components.tabs.label')"
    >
      <button
        v-for="tab in (['catalog', 'recurring', 'inputs', 'import'] as Tab[])"
        :key="tab"
        type="button"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab
          ? 'border-payroll-600 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="activeTab = tab"
      >
        {{ t(`payroll.components.tabs.${tab}`) }}
      </button>
    </nav>

    <div v-if="loading" class="space-y-3">
      <div v-for="index in 4" :key="index" class="h-24 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else>
      <section v-if="activeTab === 'catalog'" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.components.catalog.title') }}</h2>
            <p class="text-sm text-neutral-500">{{ t('payroll.components.catalog.hint') }}</p>
          </div>
          <button v-if="canWrite" :class="btnFilled('primary')" @click="openNewComponent">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>
            {{ t('payroll.components.catalog.add') }}
          </button>
        </div>

        <section v-if="componentEditorOpen" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <h3 class="font-semibold text-neutral-900">{{ t(editingComponent ? 'payroll.components.catalog.edit' : 'payroll.components.catalog.new') }}</h3>
            <button :class="btnOutline('neutral')" @click="componentEditorOpen = false">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
          </div>
          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CodeNameFields
              :code="componentForm.code"
              :name="componentForm.name"
              :code-label="t('payroll.components.fields.code')"
              :name-label="t('payroll.components.fields.name')"
              :editing="!!editingComponent"
              :code-disabled="!!editingComponent"
              :code-maxlength="64"
              :name-maxlength="255"
              name-container-class="sm:col-span-2"
              code-testid="payroll-component-code"
              name-testid="payroll-component-name"
              @update:code="componentForm.code = $event.toUpperCase()"
              @update:name="componentForm.name = $event"
            />
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.kind') }}</span><select v-model="componentForm.component_kind" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="kind in componentKinds" :key="kind" :value="kind">{{ t(`payroll.components.kind.${kind}`) }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.value_kind') }}</span><select v-model="componentForm.value_kind" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="kind in valueKinds" :key="kind" :value="kind">{{ t(`payroll.components.value_kind.${kind}`) }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.frequency') }}</span><select v-model="componentForm.frequency_kind" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="kind in frequencies" :key="kind" :value="kind">{{ t(`payroll.components.frequency.${kind}`) }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.tax') }}</span><select v-model="componentForm.tax_treatment" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="item in taxTreatments" :key="item" :value="item">{{ t(`payroll.components.tax.${item}`) }}</option></select></label>
            <label v-for="field in (['social_participation_treatment','social_treatment','health_participation_treatment','health_treatment','average_earning_treatment','enforcement_treatment','jmhz_treatment','statistics_treatment'] as const)" :key="field" class="block">
              <span class="mb-1 block text-xs text-neutral-600">{{ t(`payroll.components.fields.${field}`) }}</span>
              <select v-model="componentForm[field]" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="item in inclusionTreatments" :key="item" :value="item">{{ t(`payroll.components.inclusion.${item}`) }}</option></select>
            </label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.debit') }}</span><input v-model="componentForm.accounting_debit_code" inputmode="numeric" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.credit') }}</span><input v-model="componentForm.accounting_credit_code" inputmode="numeric" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.annual_limit') }}</span><input v-model="componentForm.annual_limit" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_from') }}</span><input v-model="componentForm.valid_from" type="date" :disabled="!!editingComponent" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm disabled:bg-neutral-100"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_to') }}</span><input v-model="componentForm.valid_to" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="inline-flex items-center gap-2 self-end text-sm text-neutral-700"><input v-model="componentForm.is_active" type="checkbox" class="rounded border-neutral-300 text-payroll-600"> {{ t('payroll.components.fields.active') }}</label>
          </div>
          <div class="mt-5 flex flex-wrap justify-end gap-2">
            <button :class="btnFilled('primary')" :disabled="saving" @click="saveComponent"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
          </div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
          <div data-layout="desktop" class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="px-4 py-3">{{ t('payroll.components.fields.code') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.name') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.kind') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.frequency') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.validity') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.status') }}</th><th class="px-4 py-3 text-right">{{ t('payroll.components.fields.actions') }}</th></tr></thead>
              <tbody class="divide-y divide-neutral-100"><tr v-for="component in components" :key="component.id"><td class="px-4 py-3 font-mono text-xs font-semibold text-neutral-900">{{ component.code }}</td><td class="px-4 py-3">{{ component.name }}</td><td class="px-4 py-3">{{ t(`payroll.components.kind.${component.component_kind}`) }}</td><td class="px-4 py-3">{{ t(`payroll.components.frequency.${component.frequency_kind}`) }}</td><td class="px-4 py-3 text-xs">{{ component.valid_from }} – {{ component.valid_to ?? t('payroll.components.open_ended') }}</td><td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="component.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(component.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></td><td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><button v-if="canWrite" :class="btnOutlineSm('neutral')" @click="editComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button></div></td></tr></tbody>
            </table>
          </div>
          <div data-layout="mobile" class="space-y-3 p-4 md:hidden">
            <article v-for="component in components" :key="component.id" class="rounded-lg border border-neutral-200 p-4">
              <div class="flex flex-wrap items-start justify-between gap-2"><div><p class="font-mono text-xs font-semibold text-payroll-700">{{ component.code }}</p><h3 class="mt-1 font-semibold text-neutral-900">{{ component.name }}</h3></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="component.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(component.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></div>
              <dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.kind') }}</dt><dd>{{ t(`payroll.components.kind.${component.component_kind}`) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.frequency') }}</dt><dd>{{ t(`payroll.components.frequency.${component.frequency_kind}`) }}</dd></div><div class="col-span-2"><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.validity') }}</dt><dd>{{ component.valid_from }} – {{ component.valid_to ?? t('payroll.components.open_ended') }}</dd></div></dl>
              <div v-if="canWrite" class="mt-4 flex flex-wrap gap-2"><button :class="btnOutlineSm('neutral')" @click="editComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button></div>
            </article>
          </div>
        </section>
      </section>

      <section v-if="activeTab === 'recurring'" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div><h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.components.recurring.title') }}</h2><p class="text-sm text-neutral-500">{{ t('payroll.components.recurring.hint') }}</p></div>
          <div class="flex flex-wrap gap-2">
            <button v-if="canWrite" :class="btnOutline('success')" :disabled="saving" @click="materializeRecurring"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.play" /></svg>{{ t('payroll.components.recurring.materialize') }}</button>
            <button v-if="canWrite" :class="btnFilled('primary')" @click="openNewRecurring"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.components.recurring.add') }}</button>
          </div>
        </div>

        <section v-if="recurringEditorOpen" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3"><h3 class="font-semibold text-neutral-900">{{ t(editingRecurring ? 'payroll.components.recurring.edit' : 'payroll.components.recurring.new') }}</h3><button :class="btnOutline('neutral')" @click="recurringEditorOpen = false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button></div>
          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.employment') }}</span><select v-model="recurringForm.employment_id" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="item in employments" :key="item.employment_id" :value="item.employment_id">{{ employmentLabel(item) }}</option></select></label>
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.component') }}</span><select v-model="recurringForm.component_id" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="item in activeRegularComponents" :key="item.id" :value="item.id">{{ item.code }} · {{ item.name }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.calculation') }}</span><select v-model="recurringForm.calculation_kind" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="item in calculationKinds" :key="item" :value="item">{{ t(`payroll.components.calculation.${item}`) }}</option></select></label>
            <label v-if="recurringForm.calculation_kind === 'fixed_amount'" class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.amount') }}</span><input v-model="recurringForm.amount" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label v-if="recurringForm.calculation_kind === 'employment_gross_basis_points'" class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.rate_basis_points') }}</span><input v-model.number="recurringForm.rate_basis_points" type="number" min="1" max="10000" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.allocation') }}</span><select v-model="recurringForm.allocation_rule" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="item in allocationRules" :key="item" :value="item">{{ t(`payroll.components.allocation.${item}`) }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.maximum') }}</span><input v-model="recurringForm.maximum_amount" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_from') }}</span><input v-model="recurringForm.valid_from" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_to') }}</span><input v-model="recurringForm.valid_to" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.note') }}</span><input v-model="recurringForm.note" maxlength="500" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="inline-flex items-center gap-2 self-end text-sm text-neutral-700"><input v-model="recurringForm.is_active" type="checkbox" class="rounded border-neutral-300 text-payroll-600"> {{ t('payroll.components.fields.active') }}</label>
          </div>
          <div class="mt-5 flex flex-wrap justify-end gap-2"><button :class="btnFilled('primary')" :disabled="saving" @click="saveRecurring"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
          <div data-layout="desktop" class="hidden overflow-x-auto md:block"><table class="min-w-full divide-y divide-neutral-200 text-sm"><thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="px-4 py-3">{{ t('payroll.components.fields.employment') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.component') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.calculation') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.validity') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.status') }}</th><th class="px-4 py-3 text-right">{{ t('payroll.components.fields.actions') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="item in recurring" :key="item.id"><td class="px-4 py-3"><p class="font-medium text-neutral-900">{{ item.employee_name }}</p><p class="text-xs text-neutral-500">{{ item.employment_code }}</p></td><td class="px-4 py-3"><p>{{ item.component_name }}</p><p class="font-mono text-xs text-neutral-500">{{ item.component_code }}</p></td><td class="px-4 py-3"><p>{{ t(`payroll.components.calculation.${item.calculation_kind}`) }}</p><p class="text-xs text-neutral-500">{{ item.amount_minor !== null ? formatMoney(item.amount_minor) : item.rate_basis_points !== null ? `${item.rate_basis_points / 100} %` : '—' }}</p></td><td class="px-4 py-3 text-xs">{{ item.valid_from }} – {{ item.valid_to ?? t('payroll.components.open_ended') }}</td><td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="item.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(item.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></td><td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><button v-if="canWrite" :class="btnOutlineSm('neutral')" @click="editRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button></div></td></tr></tbody></table></div>
          <div data-layout="mobile" class="space-y-3 p-4 md:hidden"><article v-for="item in recurring" :key="item.id" class="rounded-lg border border-neutral-200 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-semibold text-neutral-900">{{ item.employee_name }}</h3><p class="text-xs text-neutral-500">{{ item.employment_code }} · {{ item.component_code }}</p></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="item.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(item.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></div><dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.component') }}</dt><dd>{{ item.component_name }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.amount') }}</dt><dd>{{ item.amount_minor !== null ? formatMoney(item.amount_minor) : item.rate_basis_points !== null ? `${item.rate_basis_points / 100} %` : '—' }}</dd></div><div class="col-span-2"><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.validity') }}</dt><dd>{{ item.valid_from }} – {{ item.valid_to ?? t('payroll.components.open_ended') }}</dd></div></dl><div v-if="canWrite" class="mt-4 flex flex-wrap gap-2"><button :class="btnOutlineSm('neutral')" @click="editRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button></div></article></div>
        </section>
      </section>

      <section v-if="activeTab === 'inputs'" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.components.inputs.title') }}</h2><p class="text-sm text-neutral-500">{{ t('payroll.components.inputs.hint') }}</p></div><button v-if="canWrite" :class="btnFilled('primary')" @click="openNewInput"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.components.inputs.add') }}</button></div>

        <section v-if="inputEditorOpen" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-semibold text-neutral-900">{{ t(editingInput ? 'payroll.components.inputs.edit' : 'payroll.components.inputs.new') }}</h3><p class="mt-1 text-xs text-neutral-600">{{ t('payroll.components.inputs.preview_hint') }}</p></div><button :class="btnOutline('neutral')" @click="inputEditorOpen = false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button></div>
          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.employment') }}</span><select v-model="inputForm.employment_id" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" @change="selectedEmploymentChanged(inputForm)"><option v-for="item in employments" :key="item.employment_id" :value="item.employment_id">{{ employmentLabel(item) }}</option></select></label>
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.component') }}</span><select v-model="inputForm.component_id" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><option v-for="item in activeOneOffComponents" :key="item.id" :value="item.id">{{ item.code }} · {{ item.name }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.amount') }}</span><input v-model="inputForm.amount" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.quantity') }}</span><input v-model.number="inputForm.quantity_milliunits" type="number" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.source_period') }}</span><input v-model="inputForm.source_period" type="month" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.external_id') }}</span><input v-model="inputForm.external_id" maxlength="190" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm"></label>
          </div>
          <div v-if="inputPreview" class="mt-4 rounded-lg border p-4 text-sm" :class="inputPreview.support_status === 'supported' && !inputPreview.annual_limit_exceeded ? 'border-success-500/30 bg-success-50 text-success-700' : 'border-warning-500/40 bg-warning-50 text-warning-700'"><p class="font-medium">{{ t(`payroll.components.inputs.preview_status.${inputPreview.support_status}`) }}</p><p v-if="inputPreview.blocker" class="mt-1">{{ inputPreview.blocker }}</p><p v-if="inputPreview.annual_limit_minor !== null" class="mt-1">{{ t('payroll.components.inputs.annual_limit', { used: formatMoney(inputPreview.annual_used_minor), after: formatMoney(inputPreview.annual_after_minor), limit: formatMoney(inputPreview.annual_limit_minor) }) }}</p></div>
          <div class="mt-5 flex flex-wrap justify-end gap-2"><button :class="btnOutline('neutral')" :disabled="saving || !manualInputPayload" @click="previewManualInput"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t('payroll.components.inputs.preview') }}</button><button :class="btnFilled('primary')" :disabled="saving || !canSaveInput" @click="saveInput"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
          <div v-if="inputs.length === 0" class="p-8 text-center"><h3 class="font-semibold text-neutral-900">{{ t('payroll.components.inputs.empty') }}</h3><p class="mt-1 text-sm text-neutral-500">{{ t('payroll.components.inputs.empty_hint') }}</p></div>
          <template v-else>
            <div data-layout="desktop" class="hidden overflow-x-auto md:block"><table class="min-w-full divide-y divide-neutral-200 text-sm"><thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="px-4 py-3">{{ t('payroll.components.fields.employment') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.component') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.amount') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.source') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.status') }}</th><th class="px-4 py-3 text-right">{{ t('payroll.components.fields.actions') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="input in inputs" :key="input.id"><td class="px-4 py-3"><p class="font-medium text-neutral-900">{{ input.employee_name }}</p><p class="text-xs text-neutral-500">{{ input.employment_code }}</p></td><td class="px-4 py-3"><p>{{ input.component_name }}</p><p class="font-mono text-xs text-neutral-500">{{ input.component_code }}</p></td><td class="px-4 py-3 font-medium">{{ formatMoney(input.amount_minor) }}</td><td class="px-4 py-3">{{ t(`payroll.components.source.${input.source_kind}`) }}</td><td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="input.status === 'approved' || input.status === 'locked' ? 'bg-success-50 text-success-600' : input.status === 'cancelled' ? 'bg-neutral-100 text-neutral-500' : 'bg-payroll-50 text-payroll-700'">{{ t(`payroll.components.input_status.${input.status}`) }}</span></td><td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><button v-if="canWrite && input.status === 'draft' && input.source_kind === 'manual'" :class="btnOutlineSm('neutral')" @click="editInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button v-if="canApprove && input.status === 'draft'" :class="btnOutlineSm('success')" :disabled="saving" @click="approveInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>{{ t('payroll.components.inputs.approve') }}</button></div></td></tr></tbody></table></div>
            <div data-layout="mobile" class="space-y-3 p-4 md:hidden"><article v-for="input in inputs" :key="input.id" class="rounded-lg border border-neutral-200 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-semibold text-neutral-900">{{ input.employee_name }}</h3><p class="text-xs text-neutral-500">{{ input.employment_code }} · {{ input.component_code }}</p></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="input.status === 'approved' || input.status === 'locked' ? 'bg-success-50 text-success-600' : input.status === 'cancelled' ? 'bg-neutral-100 text-neutral-500' : 'bg-payroll-50 text-payroll-700'">{{ t(`payroll.components.input_status.${input.status}`) }}</span></div><dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.component') }}</dt><dd>{{ input.component_name }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.amount') }}</dt><dd class="font-semibold">{{ formatMoney(input.amount_minor) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.source') }}</dt><dd>{{ t(`payroll.components.source.${input.source_kind}`) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.external_id') }}</dt><dd class="break-all font-mono text-xs">{{ input.external_id ?? '—' }}</dd></div></dl><div class="mt-4 flex flex-wrap gap-2"><button v-if="canWrite && input.status === 'draft' && input.source_kind === 'manual'" :class="btnOutlineSm('neutral')" @click="editInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button v-if="canApprove && input.status === 'draft'" :class="btnOutlineSm('success')" :disabled="saving" @click="approveInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>{{ t('payroll.components.inputs.approve') }}</button></div></article></div>
          </template>
        </section>
      </section>

      <section v-if="activeTab === 'import'" class="space-y-4">
        <div><h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.components.import.title') }}</h2><p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.components.import.hint') }}</p></div>
        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
          <div v-if="canWrite" class="space-y-4">
            <PayrollFileDropzone
              dropzone-test-id="payroll-import-dropzone"
              input-test-id="payroll-import-file"
              selected-test-id="payroll-import-selected"
              :disabled="saving"
              :selected-file-name="importName"
              :error="importFileError"
              :drop-hint="t('payroll.components.import.drop_hint')"
              :drop-active-hint="t('payroll.components.import.drop_active')"
              :file-hint="t('payroll.components.import.file_limit')"
              :selected-text="importName ? t('payroll.components.import.selected_file', { name: importName }) : ''"
              @selected="loadImportFile"
              @rejected="rejectImportFile"
            />
            <div class="flex flex-wrap items-center gap-3">
            <button :class="btnOutline('neutral')" :disabled="saving || !importContent" @click="previewImport"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t('payroll.components.import.preview') }}</button>
            <button data-testid="payroll-import-apply" :class="btnFilled('primary')" :disabled="saving || !importCanApply" @click="applyImport"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>{{ t('payroll.components.import.apply') }}</button>
            </div>
          </div>
          <p class="mt-3 text-xs text-neutral-500">{{ t('payroll.components.import.columns_hint') }}</p>
          <div v-if="importPreview" class="mt-4 space-y-4">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <article class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t('payroll.components.import.rows') }}</p><p class="mt-1 text-lg font-semibold">{{ importPreview.row_count }}</p></article>
              <article class="rounded-lg bg-success-50 p-3"><p class="text-xs text-success-700">{{ t('payroll.components.import.accepted') }}</p><p class="mt-1 text-lg font-semibold text-success-700">{{ importPreview.accepted_count }}</p></article>
              <article class="rounded-lg bg-danger-50 p-3"><p class="text-xs text-danger-600">{{ t('payroll.components.import.rejected') }}</p><p class="mt-1 text-lg font-semibold text-danger-600">{{ importPreview.rejected_count }}</p></article>
              <article class="rounded-lg bg-warning-50 p-3"><p class="text-xs text-warning-700">{{ t('payroll.components.import.duplicates') }}</p><p class="mt-1 text-lg font-semibold text-warning-700">{{ importPreview.duplicate_count }}</p></article>
            </div>
            <div v-if="importIssues.length" class="overflow-hidden rounded-lg border border-neutral-200">
              <div data-layout="desktop" class="hidden overflow-x-auto md:block"><table class="min-w-full divide-y divide-neutral-200 text-sm"><thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="px-3 py-2">{{ t('payroll.components.import.row') }}</th><th class="px-3 py-2">{{ t('payroll.components.import.issue_type') }}</th><th class="px-3 py-2">{{ t('payroll.components.import.field') }}</th><th class="px-3 py-2">{{ t('payroll.components.import.message') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="issue in importIssues" :key="`${issue.kind}-${issue.row_number}-${issue.error_code}`"><td class="px-3 py-2">{{ issue.row_number }}</td><td class="px-3 py-2"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="issue.kind === 'duplicate' ? 'bg-warning-50 text-warning-700' : 'bg-danger-50 text-danger-600'">{{ t(`payroll.components.import.issue.${issue.kind}`) }}</span></td><td class="px-3 py-2 font-mono text-xs">{{ issue.field_name ?? '—' }}</td><td class="px-3 py-2">{{ issue.error_message }}</td></tr></tbody></table></div>
              <div data-layout="mobile" class="space-y-2 p-3 md:hidden"><article v-for="issue in importIssues" :key="`${issue.kind}-${issue.row_number}-${issue.error_code}`" class="rounded-md bg-neutral-50 p-3 text-sm"><div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ t('payroll.components.import.row_number', { row: issue.row_number }) }}</strong><span class="rounded-full px-2 py-1 text-xs font-medium" :class="issue.kind === 'duplicate' ? 'bg-warning-50 text-warning-700' : 'bg-danger-50 text-danger-600'">{{ t(`payroll.components.import.issue.${issue.kind}`) }}</span></div><p class="mt-2">{{ issue.error_message }}</p><p v-if="issue.field_name" class="mt-1 font-mono text-xs text-neutral-500">{{ issue.field_name }}</p></article></div>
            </div>
          </div>
          <div v-if="importResult" class="mt-4 rounded-lg border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"><p class="font-medium">{{ t('payroll.components.import.result_title') }}</p><p class="mt-1">{{ t('payroll.components.import.result_summary', importResult) }}</p><p v-if="importResult.replayed" class="mt-1">{{ t('payroll.components.import.replayed') }}</p></div>
        </section>
      </section>
    </template>
  </div>
</template>
