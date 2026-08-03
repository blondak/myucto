<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollEmployerAccounts,
  type PayrollEmployerSettings,
  type PayrollEmployerSettingsPayload,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnIconSm, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const loadFailed = ref(false)
const conflict = ref(false)
const settings = ref<PayrollEmployerSettings | null>(null)

type AccountKey = keyof PayrollEmployerAccounts
type FormOffice = PayrollEmployerSettings['offices'][number] & { is_new?: boolean }
type EmployerSettingsForm = Omit<PayrollEmployerSettingsPayload, 'offices'>

const accountRows: Array<{
  key: string
  debit: AccountKey | null
  credit: AccountKey | null
}> = [
  { key: 'employment_gross', debit: 'employment_gross_debit', credit: 'employment_gross_credit' },
  { key: 'partner_gross', debit: 'partner_gross_debit', credit: 'partner_gross_credit' },
  { key: 'statutory_gross', debit: 'statutory_gross_debit', credit: 'statutory_gross_credit' },
  { key: 'employer_insurance', debit: 'employer_insurance_debit', credit: null },
  { key: 'social_insurance', debit: null, credit: 'social_insurance_credit' },
  { key: 'health_insurance', debit: null, credit: 'health_insurance_credit' },
  { key: 'income_tax', debit: null, credit: 'income_tax_credit' },
  { key: 'other_deductions', debit: null, credit: 'other_deductions_credit' },
]

const defaultAccounts: PayrollEmployerAccounts = {
  employment_gross_debit: '521',
  employment_gross_credit: '331',
  partner_gross_debit: '522',
  partner_gross_credit: '366',
  statutory_gross_debit: '523',
  statutory_gross_credit: '366',
  employer_insurance_debit: '524',
  social_insurance_credit: '336',
  health_insurance_credit: '336',
  income_tax_credit: '342',
  other_deductions_credit: '379',
}

const form = reactive<EmployerSettingsForm>({
  row_version: 0,
  default_office_code: '',
  employer_registration_number: null,
  social_security_office_code: null,
  health_insurance_payer_number: null,
  default_health_insurer_code: null,
  payroll_contact_name: null,
  payroll_contact_email: null,
  payroll_contact_phone: null,
  accounts: { ...defaultAccounts },
})
const formOffices = ref<FormOffice[]>([])

const canWrite = computed(() => auth.canWrite('payroll.settings'))
const activeOffices = computed(() => formOffices.value.filter(office => office.is_active))
const officeCodes = computed(() => formOffices.value.map(office => office.code.trim().toUpperCase()))
const duplicateOfficeCodes = computed(() => new Set(
  officeCodes.value.filter((code, index, all) => code !== '' && all.indexOf(code) !== index),
))
const hasInvalidOffice = computed(() => formOffices.value.some((_office, index) =>
  officeCodeError(index) !== null || officeNameError(index) !== null,
))
const defaultOfficeValid = computed(() =>
  activeOffices.value.some(office => office.code.trim().toUpperCase() === form.default_office_code),
)
const emailValid = computed(() => {
  const email = form.payroll_contact_email?.trim() ?? ''
  return email === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
})
const accountsValid = computed(() =>
  Object.values(form.accounts).every(code => /^[0-9]{3}[.A-Z0-9]{0,7}$/.test(code.trim().toUpperCase())),
)
const officesValid = computed(() =>
  formOffices.value.length > 0
  && activeOffices.value.length > 0
  && !hasInvalidOffice.value
  && duplicateOfficeCodes.value.size === 0
  && defaultOfficeValid.value,
)
const isValid = computed(() => emailValid.value && accountsValid.value && officesValid.value)
const showValidation = ref(false)

function nullable(value: string | null): string | null {
  const normalized = value?.trim() ?? ''
  return normalized === '' ? null : normalized
}

function normalizedAccounts(): PayrollEmployerAccounts {
  const accounts = { ...form.accounts }
  for (const key of Object.keys(accounts) as AccountKey[]) {
    accounts[key] = accounts[key].trim().toUpperCase()
  }
  return accounts
}

function fillForm(value: PayrollEmployerSettings) {
  form.row_version = value.row_version
  form.default_office_code = value.default_office_code ?? ''
  form.employer_registration_number = value.employer_registration_number
  form.social_security_office_code = value.social_security_office_code
  form.health_insurance_payer_number = value.health_insurance_payer_number
  form.default_health_insurer_code = value.default_health_insurer_code
  form.payroll_contact_name = value.payroll_contact_name
  form.payroll_contact_email = value.payroll_contact_email
  form.payroll_contact_phone = value.payroll_contact_phone
  form.accounts = { ...value.accounts }
  formOffices.value = value.offices.map(office => ({ ...office }))
  showValidation.value = false
}

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    const value = await payrollApi.employerSettings()
    settings.value = value
    fillForm(value)
    conflict.value = false
  } catch {
    loadFailed.value = true
    toast.error(t('payroll.employer.load_failed'))
  } finally {
    loading.value = false
  }
}

async function save() {
  showValidation.value = true
  if (!canWrite.value || !isValid.value) return
  saving.value = true
  conflict.value = false
  try {
    const value = await payrollApi.saveEmployerSettings({
      row_version: form.row_version,
      default_office_code: form.default_office_code,
      employer_registration_number: nullable(form.employer_registration_number),
      social_security_office_code: nullable(form.social_security_office_code),
      health_insurance_payer_number: nullable(form.health_insurance_payer_number),
      default_health_insurer_code: nullable(form.default_health_insurer_code),
      payroll_contact_name: nullable(form.payroll_contact_name),
      payroll_contact_email: nullable(form.payroll_contact_email),
      payroll_contact_phone: nullable(form.payroll_contact_phone),
      accounts: normalizedAccounts(),
      offices: formOffices.value.map(office => ({
        code: office.code.trim().toUpperCase(),
        name: office.name.trim(),
        is_active: office.is_active,
      })),
    })
    settings.value = value
    fillForm(value)
    toast.success(t('payroll.employer.saved'))
  } catch (error: unknown) {
    if (isAxiosError(error) && error.response?.status === 409) {
      conflict.value = true
    } else {
      const message = isAxiosError<{ error?: { message?: string } }>(error)
        ? error.response?.data?.error?.message
        : null
      toast.error(message || t('payroll.employer.save_failed'))
    }
  } finally {
    saving.value = false
  }
}

function addOffice() {
  formOffices.value.push({
    id: 0,
    code: '',
    name: '',
    is_active: true,
    row_version: 0,
    is_new: true,
  })
}

function removeNewOffice(index: number) {
  const removed = formOffices.value[index]
  if (!removed?.is_new) return
  const removedCode = removed.code.trim().toUpperCase()
  formOffices.value.splice(index, 1)
  if (form.default_office_code === removedCode) {
    form.default_office_code = activeOffices.value[0]?.code.trim().toUpperCase() ?? ''
  }
}

function normalizeOfficeCode(index: number) {
  const office = formOffices.value[index]
  if (!office) return
  office.code = office.code.trim().toUpperCase()
  if (!defaultOfficeValid.value && office.is_active && office.code !== '') {
    form.default_office_code = office.code
  }
}

function updateOfficeActivity(index: number) {
  const office = formOffices.value[index]
  if (!office) return
  const code = office.code.trim().toUpperCase()
  if (office.is_active && form.default_office_code === '' && code !== '') {
    form.default_office_code = code
  } else if (!office.is_active && form.default_office_code === code) {
    form.default_office_code = activeOffices.value
      .find(candidate => candidate.code.trim().toUpperCase() !== code)
      ?.code.trim().toUpperCase() ?? ''
  }
}

function officeCodeError(index: number): string | null {
  const code = formOffices.value[index]?.code.trim().toUpperCase() ?? ''
  if (!/^[A-Z0-9][A-Z0-9_-]{0,31}$/.test(code)) return t('payroll.employer.validation.office_code')
  if (duplicateOfficeCodes.value.has(code)) return t('payroll.employer.validation.office_code_duplicate')
  return null
}

function officeNameError(index: number): string | null {
  const name = formOffices.value[index]?.name.trim() ?? ''
  return name === '' || name.length > 190 ? t('payroll.employer.validation.office_name') : null
}

function accountLabel(key: string): string {
  return t(`payroll.employer.accounting.${key}`)
}

function accountValid(key: AccountKey): boolean {
  return /^[0-9]{3}[.A-Z0-9]{0,7}$/.test(form.accounts[key].trim().toUpperCase())
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.employer.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.employer.subtitle') }}</p>
      </div>
      <button
        v-if="settings && canWrite"
        type="button"
        :class="btnFilled('primary')"
        :disabled="saving"
        @click="save"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.check" />
        </svg>
        {{ saving ? t('common.saving') : t('common.save') }}
      </button>
    </header>

    <div v-if="loading" class="space-y-4">
      <div class="h-32 animate-pulse rounded-xl bg-neutral-100" />
      <div class="h-72 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <section v-else-if="loadFailed" class="rounded-xl border border-danger-500/30 bg-danger-50 p-5 sm:p-6">
      <h2 class="font-semibold text-neutral-900">{{ t('payroll.employer.error_title') }}</h2>
      <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.employer.load_failed') }}</p>
      <div class="mt-4 flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('danger')" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.employer.retry') }}
        </button>
      </div>
    </section>

    <template v-else-if="settings">
      <section v-if="conflict" class="rounded-xl border border-warning-500/40 bg-warning-50 p-4 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold text-neutral-900">{{ t('payroll.employer.conflict_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.employer.conflict_description') }}</p>
          </div>
          <button type="button" :class="btnOutline('warning')" :disabled="loading" @click="load">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.cycle" />
            </svg>
            {{ t('payroll.employer.reload') }}
          </button>
        </div>
      </section>

      <section
        v-if="showValidation && !isValid"
        class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
      >
        <p class="font-medium">{{ t('payroll.employer.validation.title') }}</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
          <li v-if="!officesValid">{{ t('payroll.employer.validation.offices') }}</li>
          <li v-if="!emailValid">{{ t('payroll.employer.validation.email') }}</li>
          <li v-if="!accountsValid">{{ t('payroll.employer.validation.accounts') }}</li>
        </ul>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <div class="mb-5">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.employer.registration_title') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.employer.registration_hint') }}</p>
        </div>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.registration_number') }}</span>
            <input v-model="form.employer_registration_number" type="text" maxlength="32" autocomplete="off" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.registration_number_hint') }}</span>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.social_security_office_code') }}</span>
            <input v-model="form.social_security_office_code" type="text" maxlength="16" autocomplete="off" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_insurance_payer_number') }}</span>
            <input v-model="form.health_insurance_payer_number" type="text" maxlength="32" autocomplete="off" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.default_health_insurer_code') }}</span>
            <input v-model="form.default_health_insurer_code" type="text" maxlength="8" autocomplete="off" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
          </label>

          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.default_office') }}</span>
            <select v-model="form.default_office_code" :disabled="!canWrite || activeOffices.length === 0" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
              <option value="">{{ t('payroll.employer.select_default_office') }}</option>
              <option v-for="office in activeOffices" :key="office.id || office.code" :value="office.code.trim().toUpperCase()">
                {{ office.code || '—' }} — {{ office.name || t('payroll.employer.unnamed_office') }}
              </option>
            </select>
            <span v-if="showValidation && !defaultOfficeValid" class="mt-1 block text-xs text-danger-600">{{ t('payroll.employer.validation.default_office') }}</span>
          </label>
        </div>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <div class="mb-5">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.employer.contact_title') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.employer.contact_hint') }}</p>
        </div>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.contact_name') }}</span>
            <input v-model="form.payroll_contact_name" type="text" maxlength="190" autocomplete="name" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.contact_email') }}</span>
            <input v-model="form.payroll_contact_email" type="email" maxlength="190" autocomplete="email" :disabled="!canWrite" :aria-invalid="!emailValid" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
            <span v-if="showValidation && !emailValid" class="mt-1 block text-xs text-danger-600">{{ t('payroll.employer.validation.email') }}</span>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.contact_phone') }}</span>
            <input v-model="form.payroll_contact_phone" type="tel" maxlength="40" autocomplete="tel" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
          </label>
        </div>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.employer.offices_title') }}</h2>
            <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.employer.offices_hint') }}</p>
          </div>
          <button v-if="canWrite" type="button" :class="btnOutline('primary')" @click="addOffice">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.plus" />
            </svg>
            {{ t('payroll.employer.add_office') }}
          </button>
        </div>

        <div v-if="formOffices.length === 0" class="rounded-lg border border-dashed border-neutral-300 px-4 py-8 text-center">
          <p class="text-sm text-neutral-500">{{ t('payroll.employer.offices_empty') }}</p>
          <button v-if="canWrite" type="button" :class="`${btnOutline('primary')} mt-4`" @click="addOffice">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            {{ t('payroll.employer.add_first_office') }}
          </button>
        </div>

        <div v-else class="hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-3 py-2">{{ t('payroll.employer.office_code') }}</th>
                <th class="px-3 py-2">{{ t('payroll.employer.office_name') }}</th>
                <th class="px-3 py-2">{{ t('payroll.employer.office_status') }}</th>
                <th class="w-10 px-3 py-2"><span class="sr-only">{{ t('common.actions') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(office, index) in formOffices" :key="office.id || `new-${index}`">
                <td class="px-3 py-3 align-top">
                  <input v-model="office.code" type="text" maxlength="32" :disabled="!canWrite || !office.is_new" :aria-invalid="showValidation && officeCodeError(index) !== null" class="h-9 w-36 rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500" @blur="normalizeOfficeCode(index)">
                  <span v-if="showValidation && officeCodeError(index)" class="mt-1 block max-w-48 text-xs text-danger-600">{{ officeCodeError(index) }}</span>
                </td>
                <td class="px-3 py-3 align-top">
                  <input v-model="office.name" type="text" maxlength="190" :disabled="!canWrite" :aria-invalid="showValidation && officeNameError(index) !== null" class="h-9 min-w-64 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
                  <span v-if="showValidation && officeNameError(index)" class="mt-1 block text-xs text-danger-600">{{ officeNameError(index) }}</span>
                </td>
                <td class="px-3 py-3 align-top">
                  <label class="inline-flex min-h-9 cursor-pointer items-center gap-2">
                    <input v-model="office.is_active" type="checkbox" :disabled="!canWrite" class="h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500" @change="updateOfficeActivity(index)">
                    <span :class="office.is_active ? 'text-success-700' : 'text-neutral-500'">{{ office.is_active ? t('payroll.employer.active') : t('payroll.employer.inactive') }}</span>
                  </label>
                </td>
                <td class="px-3 py-3 align-top">
                  <button v-if="canWrite && office.is_new" type="button" :class="btnIconSm('danger')" :title="t('payroll.employer.remove_office')" :aria-label="t('payroll.employer.remove_office')" @click="removeNewOffice(index)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="formOffices.length > 0" class="grid grid-cols-1 gap-3 md:hidden">
          <article v-for="(office, index) in formOffices" :key="office.id || `mobile-new-${index}`" class="rounded-lg border border-neutral-200 p-4">
            <div class="flex items-start justify-between gap-3">
              <span :class="['inline-flex rounded-full px-2 py-0.5 text-xs font-medium', office.is_active ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-600']">
                {{ office.is_active ? t('payroll.employer.active') : t('payroll.employer.inactive') }}
              </span>
              <button v-if="canWrite && office.is_new" type="button" :class="btnIconSm('danger')" :title="t('payroll.employer.remove_office')" :aria-label="t('payroll.employer.remove_office')" @click="removeNewOffice(index)">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
              </button>
            </div>
            <div class="mt-3 grid grid-cols-1 gap-3">
              <label class="block">
                <span class="mb-1 block text-xs text-neutral-500">{{ t('payroll.employer.office_code') }}</span>
                <input v-model="office.code" type="text" maxlength="32" :disabled="!canWrite || !office.is_new" :aria-invalid="showValidation && officeCodeError(index) !== null" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500" @blur="normalizeOfficeCode(index)">
                <span v-if="showValidation && officeCodeError(index)" class="mt-1 block text-xs text-danger-600">{{ officeCodeError(index) }}</span>
              </label>
              <label class="block">
                <span class="mb-1 block text-xs text-neutral-500">{{ t('payroll.employer.office_name') }}</span>
                <input v-model="office.name" type="text" maxlength="190" :disabled="!canWrite" :aria-invalid="showValidation && officeNameError(index) !== null" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
                <span v-if="showValidation && officeNameError(index)" class="mt-1 block text-xs text-danger-600">{{ officeNameError(index) }}</span>
              </label>
              <label class="inline-flex min-h-10 cursor-pointer items-center gap-2">
                <input v-model="office.is_active" type="checkbox" :disabled="!canWrite" class="h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500" @change="updateOfficeActivity(index)">
                <span class="text-sm text-neutral-700">{{ t('payroll.employer.office_active') }}</span>
              </label>
            </div>
          </article>
        </div>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <div class="mb-5">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.employer.accounting_title') }}</h2>
          <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.employer.accounting_hint') }}</p>
        </div>

        <div class="hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-3 py-2">{{ t('payroll.employer.accounting_relation') }}</th>
                <th class="px-3 py-2">{{ t('payroll.employer.debit') }}</th>
                <th class="px-3 py-2">{{ t('payroll.employer.credit') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in accountRows" :key="row.key">
                <th class="px-3 py-3 text-left font-medium text-neutral-900">{{ accountLabel(row.key) }}</th>
                <td class="px-3 py-3">
                  <input v-if="row.debit" v-model="form.accounts[row.debit]" type="text" required maxlength="10" :disabled="!canWrite" :aria-invalid="showValidation && !accountValid(row.debit)" :class="{ 'border-danger-500': showValidation && !accountValid(row.debit) }" class="h-9 w-32 rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-3">
                  <input v-if="row.credit" v-model="form.accounts[row.credit]" type="text" required maxlength="10" :disabled="!canWrite" :aria-invalid="showValidation && !accountValid(row.credit)" :class="{ 'border-danger-500': showValidation && !accountValid(row.credit) }" class="h-9 w-32 rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
                  <span v-else class="text-neutral-400">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="grid grid-cols-1 gap-3 md:hidden">
          <article v-for="row in accountRows" :key="row.key" class="rounded-lg border border-neutral-200 p-4">
            <h3 class="font-medium text-neutral-900">{{ accountLabel(row.key) }}</h3>
            <div class="mt-3 grid grid-cols-2 gap-3">
              <label v-if="row.debit" class="block">
                <span class="mb-1 block text-xs text-neutral-500">{{ t('payroll.employer.debit') }}</span>
                <input v-model="form.accounts[row.debit]" type="text" required maxlength="10" :disabled="!canWrite" :aria-invalid="showValidation && !accountValid(row.debit)" :class="{ 'border-danger-500': showValidation && !accountValid(row.debit) }" class="h-10 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
              </label>
              <label v-if="row.credit" class="block">
                <span class="mb-1 block text-xs text-neutral-500">{{ t('payroll.employer.credit') }}</span>
                <input v-model="form.accounts[row.credit]" type="text" required maxlength="10" :disabled="!canWrite" :aria-invalid="showValidation && !accountValid(row.credit)" :class="{ 'border-danger-500': showValidation && !accountValid(row.credit) }" class="h-10 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-50 disabled:text-neutral-500">
              </label>
            </div>
          </article>
        </div>
      </section>

      <div v-if="!canWrite" class="rounded-xl border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-600">
        {{ t('payroll.employer.read_only') }}
      </div>

      <div v-else class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.employer.discard') }}
        </button>
        <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="save">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </template>
  </div>
</template>
