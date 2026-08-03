<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollInstitutionAccount,
  type PayrollInstitutionAccountCreatePayload,
  type PayrollInstitutionAccountSource,
  type PayrollInstitutionAccountUpdatePayload,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

defineProps<{ canWrite: boolean }>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const loadFailed = ref(false)
const saving = ref(false)
const showCreate = ref(false)
const showValidation = ref(false)
const editingId = ref<number | null>(null)
const conflictId = ref<number | null>(null)
const accounts = ref<PayrollInstitutionAccount[]>([])

const sources: PayrollInstitutionAccountSource[] = [
  'official_registry',
  'official_document',
  'institution_notice',
  'user_verified',
  'imported',
]

function localToday(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function emptyCreate(): PayrollInstitutionAccountCreatePayload {
  return {
    institution_type: 'health_insurer',
    institution_code: '',
    institution_name: '',
    bank_account: '',
    currency_code: 'CZK',
    variable_symbol: null,
    specific_symbol: null,
    constant_symbol: null,
    valid_from: localToday(),
    valid_to: null,
    source_kind: 'official_document',
    source_reference: '',
    verified_on: localToday(),
  }
}

const createForm = reactive(emptyCreate())
const editForm = reactive<PayrollInstitutionAccountUpdatePayload>({
  row_version: 0,
  institution_name: '',
  variable_symbol: null,
  specific_symbol: null,
  constant_symbol: null,
  valid_to: null,
  source_kind: 'official_document',
  source_reference: '',
  verified_on: localToday(),
})

const healthAccounts = computed(() =>
  accounts.value.filter(account => account.institution_type === 'health_insurer'),
)

function nullable(value: string | null): string | null {
  const normalized = value?.trim() ?? ''
  return normalized === '' ? null : normalized
}

function validDate(value: string | null, required = true): boolean {
  if (value === null || value === '') return !required
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const parsed = new Date(`${value}T00:00:00Z`)
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value
}

function symbolValid(value: string | null, maxLength: number, exact = false): boolean {
  const normalized = value?.trim() ?? ''
  if (normalized === '') return true
  return /^\d+$/.test(normalized)
    && normalized.length <= maxLength
    && (!exact || normalized.length === maxLength)
}

function commonValid(form: {
  institution_name: string
  variable_symbol: string | null
  specific_symbol: string | null
  constant_symbol: string | null
  valid_to: string | null
  source_reference: string
  verified_on: string
}): boolean {
  return form.institution_name.trim().length > 0
    && form.institution_name.trim().length <= 190
    && symbolValid(form.variable_symbol, 10)
    && symbolValid(form.specific_symbol, 10)
    && symbolValid(form.constant_symbol, 4, true)
    && validDate(form.valid_to, false)
    && form.source_reference.trim().length > 0
    && form.source_reference.trim().length <= 500
    && validDate(form.verified_on)
    && form.verified_on <= localToday()
}

const createValid = computed(() =>
  /^[A-Z0-9][A-Z0-9._/-]{0,31}$/.test(createForm.institution_code.trim().toUpperCase())
  && createForm.bank_account.trim().length > 0
  && validDate(createForm.valid_from)
  && (createForm.valid_to === null
    || createForm.valid_to === ''
    || (validDate(createForm.valid_to) && createForm.valid_to >= createForm.valid_from))
  && commonValid(createForm),
)

const editValid = computed(() => {
  const original = accounts.value.find(account => account.id === editingId.value)
  return original !== undefined
    && (editForm.valid_to === null
      || editForm.valid_to === ''
      || (validDate(editForm.valid_to) && editForm.valid_to >= original.valid_from))
    && commonValid(editForm)
})

function errorCode(error: unknown): string | null {
  return isAxiosError<{ error?: { code?: string } }>(error)
    ? error.response?.data?.error?.code ?? null
    : null
}

function showSaveError(error: unknown, fallbackKey: string) {
  const code = errorCode(error)
  if (code === 'institution_account_interval_overlap') {
    toast.error(t('payroll.employer.health_accounts.interval_overlap'))
    return
  }
  if (code === 'validation_failed') {
    toast.error(t('payroll.employer.health_accounts.validation_failed'))
    return
  }
  toast.error(t(fallbackKey))
}

async function loadAccounts() {
  loading.value = true
  loadFailed.value = false
  try {
    accounts.value = await payrollApi.institutionAccounts()
    conflictId.value = null
  } catch {
    loadFailed.value = true
    toast.error(t('payroll.employer.health_accounts.load_failed'))
  } finally {
    loading.value = false
  }
}

function openCreate() {
  Object.assign(createForm, emptyCreate())
  showValidation.value = false
  showCreate.value = true
  editingId.value = null
}

function cancelCreate() {
  showCreate.value = false
  showValidation.value = false
}

function startEdit(account: PayrollInstitutionAccount) {
  Object.assign(editForm, {
    row_version: account.row_version,
    institution_name: account.institution_name,
    variable_symbol: account.variable_symbol,
    specific_symbol: account.specific_symbol,
    constant_symbol: account.constant_symbol,
    valid_to: account.valid_to,
    source_kind: account.source_kind,
    source_reference: account.source_reference,
    verified_on: account.verified_on,
  })
  editingId.value = account.id
  conflictId.value = null
  showValidation.value = false
  showCreate.value = false
}

function cancelEdit() {
  editingId.value = null
  conflictId.value = null
  showValidation.value = false
}

async function createAccount() {
  showValidation.value = true
  if (!createValid.value || saving.value) return
  saving.value = true
  try {
    const created = await payrollApi.createInstitutionAccount({
      ...createForm,
      institution_code: createForm.institution_code.trim().toUpperCase(),
      institution_name: createForm.institution_name.trim(),
      bank_account: createForm.bank_account.trim(),
      variable_symbol: nullable(createForm.variable_symbol),
      specific_symbol: nullable(createForm.specific_symbol),
      constant_symbol: nullable(createForm.constant_symbol),
      valid_to: nullable(createForm.valid_to),
      source_reference: createForm.source_reference.trim(),
    })
    accounts.value.push(created)
    showCreate.value = false
    showValidation.value = false
    Object.assign(createForm, emptyCreate())
    toast.success(t('payroll.employer.health_accounts.created'))
  } catch (error: unknown) {
    showSaveError(error, 'payroll.employer.health_accounts.create_failed')
  } finally {
    saving.value = false
  }
}

async function updateAccount() {
  showValidation.value = true
  const id = editingId.value
  if (id === null || !editValid.value || saving.value) return
  saving.value = true
  conflictId.value = null
  try {
    const updated = await payrollApi.updateInstitutionAccount(id, {
      ...editForm,
      institution_name: editForm.institution_name.trim(),
      variable_symbol: nullable(editForm.variable_symbol),
      specific_symbol: nullable(editForm.specific_symbol),
      constant_symbol: nullable(editForm.constant_symbol),
      valid_to: nullable(editForm.valid_to),
      source_reference: editForm.source_reference.trim(),
    })
    const index = accounts.value.findIndex(account => account.id === id)
    if (index !== -1) accounts.value[index] = updated
    editingId.value = null
    showValidation.value = false
    toast.success(t('payroll.employer.health_accounts.updated'))
  } catch (error: unknown) {
    if (errorCode(error) === 'row_version_conflict') {
      conflictId.value = id
    } else {
      showSaveError(error, 'payroll.employer.health_accounts.update_failed')
    }
  } finally {
    saving.value = false
  }
}

function sourceLabel(source: PayrollInstitutionAccountSource): string {
  return t(`payroll.employer.health_accounts.sources.${source}`)
}

onMounted(loadAccounts)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.employer.health_accounts.title') }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.employer.health_accounts.hint') }}</p>
      </div>
      <button v-if="canWrite && !showCreate" type="button" :class="btnOutline('primary')" @click="openCreate">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.plus" />
        </svg>
        {{ t('payroll.employer.health_accounts.add') }}
      </button>
    </div>

    <div v-if="loading" class="h-28 animate-pulse rounded-lg bg-neutral-100" />

    <div v-else-if="loadFailed" class="rounded-lg border border-danger-500/30 bg-danger-50 p-4">
      <p class="text-sm text-danger-700">{{ t('payroll.employer.health_accounts.load_failed') }}</p>
      <button type="button" :class="`${btnOutline('danger')} mt-3`" @click="loadAccounts">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
        {{ t('payroll.employer.retry') }}
      </button>
    </div>

    <template v-else>
      <div v-if="healthAccounts.length === 0 && !showCreate" class="rounded-lg border border-dashed border-neutral-300 px-4 py-8 text-center">
        <p class="text-sm text-neutral-500">{{ t('payroll.employer.health_accounts.empty') }}</p>
      </div>

      <div v-if="healthAccounts.length > 0" class="hidden overflow-x-auto md:block">
        <table class="min-w-[980px] divide-y divide-neutral-200 text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="px-3 py-2">{{ t('payroll.employer.health_accounts.institution') }}</th>
              <th class="px-3 py-2">{{ t('payroll.employer.health_accounts.account') }}</th>
              <th class="px-3 py-2">{{ t('payroll.employer.health_accounts.variable_symbol') }}</th>
              <th class="px-3 py-2">{{ t('payroll.employer.health_accounts.validity') }}</th>
              <th class="px-3 py-2">{{ t('payroll.employer.health_accounts.verification') }}</th>
              <th class="px-3 py-2"><span class="sr-only">{{ t('common.actions') }}</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="account in healthAccounts" :key="account.id">
              <td class="px-3 py-3">
                <p class="font-medium text-neutral-900">{{ account.institution_name }}</p>
                <p class="font-mono text-xs text-neutral-500">{{ account.institution_code }}</p>
              </td>
              <td class="px-3 py-3 font-mono text-neutral-700">{{ account.bank_account_masked }} / {{ account.currency_code }}</td>
              <td class="px-3 py-3 font-mono text-neutral-700">{{ account.variable_symbol || '—' }}</td>
              <td class="px-3 py-3 text-neutral-700">{{ account.valid_from }} – {{ account.valid_to || t('payroll.employer.health_accounts.open_ended') }}</td>
              <td class="px-3 py-3">
                <p class="text-neutral-700">{{ sourceLabel(account.source_kind) }}</p>
                <p class="text-xs text-neutral-500">{{ account.verified_on }} · {{ account.source_reference }}</p>
              </td>
              <td class="px-3 py-3">
                <button v-if="canWrite" type="button" :class="btnOutlineSm('neutral')" @click="startEdit(account)">
                  <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                  {{ t('common.edit') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="healthAccounts.length > 0" class="grid grid-cols-1 gap-3 md:hidden">
        <article v-for="account in healthAccounts" :key="`mobile-${account.id}`" class="rounded-lg border border-neutral-200 p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <h3 class="font-medium text-neutral-900">{{ account.institution_name }}</h3>
              <p class="font-mono text-xs text-neutral-500">{{ account.institution_code }}</p>
            </div>
            <button v-if="canWrite" type="button" :class="btnOutlineSm('neutral')" @click="startEdit(account)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
              {{ t('common.edit') }}
            </button>
          </div>
          <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.account') }}</dt>
              <dd class="font-mono text-neutral-700">{{ account.bank_account_masked }} / {{ account.currency_code }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.variable_symbol') }}</dt>
              <dd class="font-mono text-neutral-700">{{ account.variable_symbol || '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.validity') }}</dt>
              <dd class="text-neutral-700">{{ account.valid_from }} – {{ account.valid_to || t('payroll.employer.health_accounts.open_ended') }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.verification') }}</dt>
              <dd class="text-neutral-700">{{ sourceLabel(account.source_kind) }} · {{ account.verified_on }}</dd>
            </div>
          </dl>
          <p class="mt-2 break-words text-xs text-neutral-500">{{ account.source_reference }}</p>
        </article>
      </div>

      <div v-if="showCreate" data-testid="health-account-create" class="mt-5 rounded-lg border border-payroll-500/30 bg-payroll-50/40 p-4 sm:p-5">
        <div class="mb-4">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll.employer.health_accounts.create_title') }}</h3>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.employer.health_accounts.create_hint') }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.institution_code') }}</span>
            <input v-model="createForm.institution_code" data-testid="health-create-code" type="text" maxlength="32" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.institution_name') }}</span>
            <input v-model="createForm.institution_name" data-testid="health-create-name" type="text" maxlength="190" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.bank_account') }}</span>
            <input v-model="createForm.bank_account" data-testid="health-create-account" type="text" maxlength="191" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.bank_account_hint') }}</span>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.variable_symbol') }}</span>
            <input v-model="createForm.variable_symbol" data-testid="health-create-vs" type="text" inputmode="numeric" maxlength="10" autocomplete="off" :aria-invalid="showValidation && !symbolValid(createForm.variable_symbol, 10)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.specific_symbol') }}</span>
            <input v-model="createForm.specific_symbol" type="text" inputmode="numeric" maxlength="10" autocomplete="off" :aria-invalid="showValidation && !symbolValid(createForm.specific_symbol, 10)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.constant_symbol') }}</span>
            <input v-model="createForm.constant_symbol" type="text" inputmode="numeric" maxlength="4" autocomplete="off" :aria-invalid="showValidation && !symbolValid(createForm.constant_symbol, 4, true)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.valid_from') }}</span>
            <input v-model="createForm.valid_from" type="date" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.valid_to') }}</span>
            <input v-model="createForm.valid_to" type="date" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.source') }}</span>
            <select v-model="createForm.source_kind" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
              <option v-for="source in sources" :key="source" :value="source">{{ sourceLabel(source) }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.source_reference') }}</span>
            <input v-model="createForm.source_reference" data-testid="health-create-source-reference" type="text" maxlength="500" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.verified_on') }}</span>
            <input v-model="createForm.verified_on" type="date" :max="localToday()" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
        </div>
        <p v-if="showValidation && !createValid" class="mt-3 text-sm text-danger-600" role="alert">{{ t('payroll.employer.health_accounts.validation') }}</p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="cancelCreate">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnFilled('success')" :disabled="saving" @click="createAccount">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('payroll.employer.health_accounts.create') }}
          </button>
        </div>
      </div>

      <div v-if="editingId !== null" data-testid="health-account-edit" class="mt-5 rounded-lg border border-neutral-300 bg-neutral-50 p-4 sm:p-5">
        <div class="mb-4">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll.employer.health_accounts.edit_title') }}</h3>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.employer.health_accounts.edit_hint') }}</p>
        </div>
        <div v-if="conflictId === editingId" class="mb-4 rounded-md border border-warning-500/40 bg-warning-50 p-3 text-sm text-warning-700" role="alert">
          <p>{{ t('payroll.employer.health_accounts.conflict') }}</p>
          <button type="button" :class="`${btnOutline('warning')} mt-3`" @click="loadAccounts().then(cancelEdit)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
            {{ t('payroll.employer.reload') }}
          </button>
        </div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.institution_name') }}</span>
            <input v-model="editForm.institution_name" data-testid="health-edit-name" type="text" maxlength="190" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.variable_symbol') }}</span>
            <input v-model="editForm.variable_symbol" data-testid="health-edit-vs" type="text" inputmode="numeric" maxlength="10" autocomplete="off" :aria-invalid="showValidation && !symbolValid(editForm.variable_symbol, 10)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.specific_symbol') }}</span>
            <input v-model="editForm.specific_symbol" type="text" inputmode="numeric" maxlength="10" autocomplete="off" :aria-invalid="showValidation && !symbolValid(editForm.specific_symbol, 10)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.constant_symbol') }}</span>
            <input v-model="editForm.constant_symbol" type="text" inputmode="numeric" maxlength="4" autocomplete="off" :aria-invalid="showValidation && !symbolValid(editForm.constant_symbol, 4, true)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.valid_to') }}</span>
            <input v-model="editForm.valid_to" type="date" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.source') }}</span>
            <select v-model="editForm.source_kind" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
              <option v-for="source in sources" :key="source" :value="source">{{ sourceLabel(source) }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.source_reference') }}</span>
            <input v-model="editForm.source_reference" type="text" maxlength="500" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.verified_on') }}</span>
            <input v-model="editForm.verified_on" type="date" :max="localToday()" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
        </div>
        <p v-if="showValidation && !editValid" class="mt-3 text-sm text-danger-600" role="alert">{{ t('payroll.employer.health_accounts.validation') }}</p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="cancelEdit">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnFilled('success')" :disabled="saving || conflictId === editingId" @click="updateAccount">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </div>
    </template>
  </section>
</template>
