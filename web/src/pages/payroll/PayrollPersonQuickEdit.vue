<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollEmployment,
  type PayrollEmploymentTermsPayload,
  type PayrollPerson,
  type PayrollPersonProfile,
  type PayrollPersonProfilePayload,
  type PayrollPersonQuickEditPayload,
  type PayrollPersonQuickEditResponse,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, ICONS } from '@/components/ui/buttonStyles'
import CountrySelect from '@/components/ui/CountrySelect.vue'
import { useToast } from '@/composables/useToast'
import { todayIso } from './employmentLifecycleUi'

const props = defineProps<{
  personId: number
  canWrite: boolean
}>()

const emit = defineEmits<{
  saved: [result: PayrollPersonQuickEditResponse]
}>()

interface QuickEditForm {
  first_name: string
  last_name: string
  birth_number: string
  street_line: string
  city: string
  postal_code: string
  country_code: string
  email: string
  phone: string
  weekly_hours: string
  monthly_gross: string
  employment_effective_from: string
}

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const saveError = ref('')
const profile = ref<PayrollPersonProfile | null>(null)
const person = ref<PayrollPerson | null>(null)
const primaryEmployment = ref<PayrollEmployment | null>(null)
const originalWeeklyHours = ref('')
const originalMonthlyGrossMinor = ref<number | null>(null)

const form = reactive<QuickEditForm>({
  first_name: '',
  last_name: '',
  birth_number: '',
  street_line: '',
  city: '',
  postal_code: '',
  country_code: '',
  email: '',
  phone: '',
  weekly_hours: '',
  monthly_gross: '',
  employment_effective_from: todayIso(),
})

const inputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-100 disabled:text-neutral-500'
const labelClass = 'block text-xs font-medium text-neutral-600'
const writableEmployment = computed(() =>
  primaryEmployment.value !== null
  && ['planned', 'preregistered', 'active', 'suspended'].includes(
    primaryEmployment.value.status,
  ),
)
const employmentChanged = computed(() => {
  if (!writableEmployment.value) return false
  return normalizeHours(form.weekly_hours) !== normalizeHours(originalWeeklyHours.value)
    || amountMinor(form.monthly_gross) !== originalMonthlyGrossMinor.value
})

const currentIdentity = computed(() => {
  const rows = profile.value?.identity_history ?? []
  return rows.find(row => row.effective_to === null) ?? rows[0] ?? null
})
const residenceAddress = computed(() => {
  const rows = profile.value?.addresses.filter(row => row.address_type === 'residence') ?? []
  return rows.find(row => row.effective_to === null) ?? rows[0] ?? null
})
const primaryEmail = computed(() => preferredContact('email'))
const primaryPhone = computed(() => preferredContact('phone'))
const birthNumber = computed(() =>
  profile.value?.identifiers.find(row => row.identifier_type === 'birth_number') ?? null,
)

function preferredContact(type: 'email' | 'phone') {
  const rows = profile.value?.contacts.filter(row =>
    row.contact_type === type && row.is_active,
  ) ?? []
  return rows.find(row => row.is_primary) ?? rows[0] ?? null
}

function splitName(fullName: string): [string, string] {
  const parts = fullName.trim().split(/\s+/u)
  if (parts.length < 2) return [parts[0] ?? '', '']
  return [parts.slice(0, -1).join(' '), parts.at(-1) ?? '']
}

function primaryFrom(value: PayrollPerson): PayrollEmployment | null {
  const primary = value.employments.filter(item => item.is_primary)
  return primary.find(item =>
    ['planned', 'preregistered', 'active', 'suspended'].includes(item.status),
  ) ?? primary[0] ?? null
}

function nextTermsDate(employment: PayrollEmployment | null): string {
  const today = todayIso()
  const latest = employment?.terms[0]?.effective_from
  if (!latest || latest < today) return today
  const date = new Date(`${latest}T12:00:00`)
  date.setDate(date.getDate() + 1)
  return date.toISOString().slice(0, 10)
}

function minorToInput(value: number | null): string {
  if (value === null) return ''
  const whole = Math.trunc(value / 100)
  const fraction = Math.abs(value % 100)
  return fraction === 0 ? String(whole) : `${whole}.${String(fraction).padStart(2, '0')}`
}

function amountMinor(value: string): number | null {
  const normalized = value.trim().replace(',', '.')
  if (normalized === '') return null
  const match = /^(\d{1,10})(?:\.(\d{1,2}))?$/.exec(normalized)
  if (!match) return Number.NaN
  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
}

function normalizeHours(value: string): string {
  const normalized = value.trim().replace(',', '.')
  if (normalized === '') return ''
  const number = Number(normalized)
  return Number.isFinite(number) ? number.toFixed(2) : normalized
}

function hydrate(
  profileValue: PayrollPersonProfile,
  personValue?: PayrollPerson,
  employmentValue?: PayrollEmployment | null,
) {
  profile.value = profileValue
  if (personValue) person.value = personValue
  const employment = employmentValue === undefined
    ? (person.value ? primaryFrom(person.value) : null)
    : employmentValue
  primaryEmployment.value = employment

  const identity = profileValue.identity_history.find(row => row.effective_to === null)
    ?? profileValue.identity_history[0]
  const [fallbackFirst, fallbackLast] = splitName(profileValue.full_name)
  form.first_name = identity?.first_name ?? fallbackFirst
  form.last_name = identity?.last_name ?? fallbackLast
  form.birth_number = ''
  form.street_line = ''
  form.city = ''
  form.postal_code = ''
  form.country_code = ''
  form.email = ''
  form.phone = ''
  form.weekly_hours = employment?.terms[0]?.weekly_hours ?? ''
  form.monthly_gross = minorToInput(employment?.monthly_gross_minor ?? null)
  form.employment_effective_from = nextTermsDate(employment)
  originalWeeklyHours.value = form.weekly_hours
  originalMonthlyGrossMinor.value = employment?.monthly_gross_minor ?? null
}

async function load() {
  loading.value = true
  loadError.value = ''
  saveError.value = ''
  try {
    const [personValue, profileValue] = await Promise.all([
      payrollApi.person(props.personId),
      payrollApi.personProfile(props.personId),
    ])
    hydrate(profileValue, personValue)
  } catch (error) {
    loadError.value = apiErrorMessage(error, t('payroll.people.quick_edit.load_failed'))
  } finally {
    loading.value = false
  }
}

function optionalText(value: string): string | undefined {
  const trimmed = value.trim()
  return trimmed === '' ? undefined : trimmed
}

function profilePayload(): PayrollPersonProfilePayload {
  const value = profile.value
  if (!value) throw new Error('profile_missing')
  const fullName = `${form.first_name.trim()} ${form.last_name.trim()}`.trim()
  const identity = currentIdentity.value
  const address = residenceAddress.value
  const email = primaryEmail.value
  const phone = primaryPhone.value
  const identifier = birthNumber.value
  const hasAddressReplacement = [
    form.street_line,
    form.city,
    form.postal_code,
    form.country_code,
  ].some(item => item.trim() !== '')

  return {
    row_version: value.row_version,
    profile_status: value.profile_status === 'missing' ? 'setup' : value.profile_status,
    payout_method: value.payout_method,
    cash_allocation_basis_points: value.cash_allocation_basis_points,
    payout_effective_on: value.payout_effective_on ?? todayIso(),
    secure_delivery_channel: value.secure_delivery_channel,
    identity_history: value.identity_history.length > 0
      ? value.identity_history.map(row => ({
          id: row.id,
          full_name: row.id === identity?.id ? fullName : row.full_name,
          first_name: row.id === identity?.id
            ? form.first_name.trim()
            : (row.first_name ?? splitName(row.full_name)[0]),
          last_name: row.id === identity?.id
            ? form.last_name.trim()
            : (row.last_name ?? splitName(row.full_name)[1]),
          effective_from: row.effective_from,
          effective_to: row.effective_to,
        }))
      : [{
          full_name: fullName,
          first_name: form.first_name.trim(),
          last_name: form.last_name.trim(),
          effective_from: todayIso(),
          effective_to: null,
        }],
    addresses: [
      ...value.addresses.map(row => ({
        id: row.id,
        address_type: row.address_type,
        ...(row.id === address?.id && hasAddressReplacement
          ? {
              street_line: form.street_line.trim(),
              city: form.city.trim(),
              postal_code: form.postal_code.trim(),
              country_code: form.country_code.trim().toUpperCase(),
            }
          : {}),
        effective_from: row.effective_from,
        effective_to: row.effective_to,
      })),
      ...(!address && hasAddressReplacement
        ? [{
            address_type: 'residence' as const,
            street_line: form.street_line.trim(),
            city: form.city.trim(),
            postal_code: form.postal_code.trim(),
            country_code: form.country_code.trim().toUpperCase(),
            effective_from: todayIso(),
            effective_to: null,
          }]
        : []),
    ],
    contacts: [
      ...value.contacts.map(row => ({
        id: row.id,
        contact_type: row.contact_type,
        ...(row.id === email?.id && optionalText(form.email) !== undefined
          ? { value: form.email.trim() }
          : {}),
        ...(row.id === phone?.id && optionalText(form.phone) !== undefined
          ? { value: form.phone.trim() }
          : {}),
        is_primary: row.is_primary,
        is_active: row.is_active,
      })),
      ...(!email && optionalText(form.email) !== undefined
        ? [{
            contact_type: 'email' as const,
            value: form.email.trim(),
            is_primary: true,
            is_active: true,
          }]
        : []),
      ...(!phone && optionalText(form.phone) !== undefined
        ? [{
            contact_type: 'phone' as const,
            value: form.phone.trim(),
            is_primary: true,
            is_active: true,
          }]
        : []),
    ],
    identifiers: [
      ...value.identifiers.map(row => ({
        id: row.id,
        identifier_type: row.identifier_type,
        ...(row.id === identifier?.id && optionalText(form.birth_number) !== undefined
          ? { value: form.birth_number.trim() }
          : {}),
      })),
      ...(!identifier && optionalText(form.birth_number) !== undefined
        ? [{
            identifier_type: 'birth_number' as const,
            value: form.birth_number.trim(),
          }]
        : []),
    ],
    accounts: value.accounts.map(row => ({
      id: row.id,
      label: row.label,
      allocation_basis_points: row.allocation_basis_points,
      effective_from: row.effective_from,
      effective_to: row.effective_to,
      is_active: row.is_active,
    })),
  }
}

function termsPayload(employment: PayrollEmployment): PayrollEmploymentTermsPayload {
  const terms = employment.terms[0]
  if (!terms) throw new Error('employment_terms_missing')
  return {
    office_id: terms.office_id,
    effective_from: form.employment_effective_from,
    contract_signed_on: terms.contract_signed_on,
    planned_start_on: terms.planned_start_on,
    actual_start_on: terms.actual_start_on,
    fixed_term_end_on: terms.fixed_term_end_on,
    weekly_hours: optionalText(form.weekly_hours) ?? null,
    workload_basis_points: terms.workload_basis_points,
    work_place: terms.work_place,
    regular_workplace: terms.regular_workplace,
    cz_isco_code: terms.cz_isco_code,
    activity_code: terms.activity_code,
    social_insurance_participation: terms.social_insurance_participation,
    health_insurance_participation: terms.health_insurance_participation,
    tax_regime: terms.tax_regime,
    foreign_legislation_country_code: terms.foreign_legislation_country_code,
    a1_certificate_until: terms.a1_certificate_until,
    risky_work: terms.risky_work,
    tax_declaration_signed: terms.tax_declaration_signed,
    is_primary: terms.is_primary,
    change_reason: t('payroll.people.quick_edit.change_reason_default'),
  }
}

function validate(): boolean {
  if (form.first_name.trim() === '' || form.last_name.trim() === '') {
    saveError.value = t('payroll.people.quick_edit.name_required')
    return false
  }
  const addressParts = [
    form.street_line,
    form.city,
    form.postal_code,
    form.country_code,
  ].filter(item => item.trim() !== '').length
  if (addressParts !== 0 && addressParts !== 4) {
    saveError.value = t('payroll.people.quick_edit.address_complete_required')
    return false
  }
  const gross = amountMinor(form.monthly_gross)
  if (Number.isNaN(gross)) {
    saveError.value = t('payroll.people.quick_edit.gross_invalid')
    return false
  }
  if (employmentChanged.value
    && (!writableEmployment.value
      || !primaryEmployment.value?.terms[0]
      || form.employment_effective_from === '')
  ) {
    saveError.value = t('payroll.people.quick_edit.employment_unavailable')
    return false
  }

  return true
}

async function save() {
  if (saving.value || !profile.value || !validate()) return
  saveError.value = ''
  saving.value = true
  try {
    const employment = primaryEmployment.value
    const payload: PayrollPersonQuickEditPayload = {
      profile: profilePayload(),
      employment: employmentChanged.value && employment
        ? {
            id: employment.id,
            row_version: employment.row_version,
            monthly_gross_minor: amountMinor(form.monthly_gross),
            terms: termsPayload(employment),
          }
        : null,
    }
    const result = await payrollApi.savePersonQuickEdit(props.personId, payload)
    hydrate(result.profile, undefined, result.employment)
    emit('saved', result)
    toast.success(t('payroll.people.quick_edit.saved'))
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.people.quick_edit.save_failed'))
  } finally {
    saving.value = false
  }
}

watch(() => props.personId, load)
onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-test="person-quick-edit">
    <header class="border-b border-neutral-200 px-4 py-4 sm:px-6">
      <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.quick_edit.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.quick_edit.subtitle') }}</p>
    </header>

    <div v-if="loading" class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-4">
      <div v-for="index in 8" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
    </div>

    <div v-else-if="loadError || !profile" class="p-4 sm:p-6">
      <div class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
        {{ loadError || t('payroll.people.quick_edit.load_failed') }}
      </div>
    </div>

    <form v-else class="space-y-6 p-4 sm:p-6" @submit.prevent="save">
      <div
        v-if="saveError"
        class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700"
        data-test="quick-edit-error"
        role="alert"
      >
        {{ saveError }}
      </div>

      <fieldset :disabled="!canWrite || saving" class="space-y-4">
        <legend class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.quick_edit.personal_title') }}</legend>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label :class="labelClass">
            {{ t('payroll.people.quick_edit.first_name') }}
            <input
              v-model="form.first_name"
              required
              autocomplete="given-name"
              :class="inputClass"
              data-test="first-name"
            >
          </label>
          <label :class="labelClass">
            {{ t('payroll.people.quick_edit.last_name') }}
            <input
              v-model="form.last_name"
              required
              autocomplete="family-name"
              :class="inputClass"
              data-test="last-name"
            >
          </label>
          <label :class="[labelClass, 'sm:col-span-2']">
            {{ t('payroll.people.quick_edit.birth_number') }}
            <input
              v-model="form.birth_number"
              autocomplete="off"
              inputmode="numeric"
              :placeholder="birthNumber?.value_masked || t('payroll.people.quick_edit.not_set')"
              :class="inputClass"
              data-test="birth-number"
            >
            <span class="mt-1 block text-xs font-normal text-neutral-500">
              {{ t('payroll.people.quick_edit.sensitive_replace_hint') }}
            </span>
          </label>
        </div>
      </fieldset>

      <fieldset :disabled="!canWrite || saving" class="space-y-4">
        <div>
          <legend class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.quick_edit.contact_title') }}</legend>
          <p v-if="residenceAddress" class="mt-1 text-xs text-neutral-500">
            {{ t('payroll.people.quick_edit.current_address') }}: {{ residenceAddress.address_masked }}
          </p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6">
          <label :class="[labelClass, 'sm:col-span-2 lg:col-span-2']">
            {{ t('payroll.people.quick_edit.street_line') }}
            <input
              v-model="form.street_line"
              autocomplete="street-address"
              :class="inputClass"
              :placeholder="t('payroll.people.quick_edit.keep_masked')"
              data-test="street-line"
            >
          </label>
          <label :class="[labelClass, 'lg:col-span-2']">
            {{ t('payroll.people.quick_edit.city') }}
            <input v-model="form.city" autocomplete="address-level2" :class="inputClass" data-test="city">
          </label>
          <label :class="labelClass">
            {{ t('payroll.people.quick_edit.postal_code') }}
            <input v-model="form.postal_code" autocomplete="postal-code" :class="inputClass" data-test="postal-code">
          </label>
          <label :class="labelClass">
            {{ t('payroll.people.quick_edit.country_code') }}
            <CountrySelect
              v-model="form.country_code"
              class="mt-1"
              accent="payroll"
              data-test="country-code"
            />
          </label>
          <label :class="[labelClass, 'sm:col-span-1 lg:col-span-3']">
            {{ t('payroll.people.quick_edit.email') }}
            <input
              v-model="form.email"
              type="email"
              autocomplete="email"
              :placeholder="primaryEmail?.value_masked || t('payroll.people.quick_edit.not_set')"
              :class="inputClass"
              data-test="email"
            >
          </label>
          <label :class="[labelClass, 'sm:col-span-1 lg:col-span-3']">
            {{ t('payroll.people.quick_edit.phone') }}
            <input
              v-model="form.phone"
              type="tel"
              autocomplete="tel"
              :placeholder="primaryPhone?.value_masked || t('payroll.people.quick_edit.not_set')"
              :class="inputClass"
              data-test="phone"
            >
          </label>
        </div>
        <p class="text-xs text-neutral-500">{{ t('payroll.people.quick_edit.contact_replace_hint') }}</p>
      </fieldset>

      <fieldset :disabled="!canWrite || saving || !writableEmployment" class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <legend class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.quick_edit.employment_title') }}</legend>
            <p v-if="primaryEmployment" class="mt-1 text-xs text-neutral-500">
              {{ t(`payroll.people.relations.${primaryEmployment.relation_type}`) }} · {{ primaryEmployment.code }}
            </p>
          </div>
          <span v-if="primaryEmployment" class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-700">
            {{ t(`payroll.people.employment_status.${primaryEmployment.status}`) }}
          </span>
        </div>
        <div
          v-if="!writableEmployment"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700"
        >
          {{ t('payroll.people.quick_edit.employment_unavailable') }}
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <label :class="labelClass">
            {{ t('payroll.people.quick_edit.weekly_hours') }}
            <input
              v-model="form.weekly_hours"
              inputmode="decimal"
              :class="inputClass"
              data-test="weekly-hours"
            >
          </label>
          <label :class="labelClass">
            {{ t('payroll.people.quick_edit.monthly_gross') }}
            <div class="relative">
              <input
                v-model="form.monthly_gross"
                inputmode="decimal"
                min="0"
                :class="[inputClass, 'pr-10']"
                data-test="monthly-gross"
              >
              <span class="pointer-events-none absolute right-3 top-1/2 mt-0.5 -translate-y-1/2 text-sm text-neutral-500">Kč</span>
            </div>
          </label>
          <label :class="labelClass">
            {{ t('payroll.people.quick_edit.effective_from') }}
            <input
              v-model="form.employment_effective_from"
              required
              type="date"
              :class="inputClass"
              data-test="employment-effective-from"
            >
          </label>
        </div>
        <p class="text-xs text-neutral-500">{{ t('payroll.people.quick_edit.employment_history_hint') }}</p>
      </fieldset>

      <div v-if="canWrite" class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4">
        <button
          type="submit"
          :class="btnFilled('primary')"
          :disabled="saving"
          data-test="save-quick-edit"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>
  </section>
</template>
