<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollEmployment,
  type PayrollEmploymentCreatePayload,
  type PayrollPerson,
  type PayrollPersonCreatePayload,
  type PayrollPersonListItem,
  type PayrollPersonProfile,
  type PayrollPersonQuickEditResponse,
  type PayrollRelationType,
} from '@/api/payroll'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import EmploymentCard from './EmploymentCard.vue'
import PayrollPersonQuickEdit from './PayrollPersonQuickEdit.vue'
import PayrollPersonProfilePanel from './PayrollPersonProfilePanel.vue'
import { todayIso } from './employmentLifecycleUi'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const loading = ref(true)
const people = ref<PayrollPersonListItem[]>([])
const expandedId = ref<number | null>(null)
const details = ref<Record<number, PayrollPerson>>({})
const loadingDetailId = ref<number | null>(null)
const searchQuery = ref('')
const peopleFilter = ref<'active' | 'all' | 'needs_setup'>('active')
const showEmployeeForm = ref(false)
const savingEmployee = ref(false)
const employeeError = ref('')
const createdEmployeeId = ref<number | null>(null)
const creatingForId = ref<number | null>(null)
const savingNew = ref(false)
const newEmployment = ref<PayrollEmploymentCreatePayload | null>(null)
const newEmploymentMonthlyGross = ref<number | null>(null)
const newEmploymentError = ref('')
const advancedProfileOpen = ref(false)
const canCreatePerson = computed(() => auth.canWrite('payroll.person.write'))
const canQuickEditPerson = computed(() =>
  auth.canWrite('payroll.person.write')
  && auth.canWrite('payroll.employment.write'),
)
const relationTypes: PayrollRelationType[] = [
  'employment',
  'small_scale_employment',
  'dpp',
  'dpc',
  'partner_dependent',
  'statutory_body',
]
const filterOptions = computed(() => [
  { value: 'active' as const, label: t('payroll.people.filters.active') },
  { value: 'all' as const, label: t('payroll.people.filters.all') },
  { value: 'needs_setup' as const, label: t('payroll.people.filters.needs_setup') },
])
const relationOptions = computed(() => relationTypes.map(type => ({
  value: type,
  label: relationLabel(type),
})))
const employeeForm = reactive({
  full_name: '',
  birth_date: '',
  birth_number: '',
  relation_type: 'employment' as PayrollRelationType,
  planned_start_on: todayIso(),
  monthly_gross: null as number | null,
})
const filteredPeople = computed(() => {
  const query = normalizeSearch(searchQuery.value)
  return people.value.filter((person) => {
    const matchesFilter = peopleFilter.value === 'all'
      || (peopleFilter.value === 'active' && person.is_active)
      || (peopleFilter.value === 'needs_setup' && person.needs_setup)
    return matchesFilter
      && (!query || normalizeSearch(person.full_name).includes(query))
  })
})

function normalizeSearch(value: string): string {
  return value
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .trim()
    .toLocaleLowerCase()
}

function resetEmployeeForm() {
  employeeForm.full_name = ''
  employeeForm.birth_date = ''
  employeeForm.birth_number = ''
  employeeForm.relation_type = 'employment'
  employeeForm.planned_start_on = todayIso()
  employeeForm.monthly_gross = null
  employeeError.value = ''
}

function openEmployeeForm() {
  if (!canCreatePerson.value) return
  resetEmployeeForm()
  createdEmployeeId.value = null
  showEmployeeForm.value = true
}

function closeEmployeeForm() {
  showEmployeeForm.value = false
  employeeError.value = ''
}

function relationLabel(type: PayrollRelationType): string {
  return t(`payroll.people.relations.${type}`)
}

function statusLabel(isActive: boolean): string {
  return t(isActive ? 'payroll.people.status.active' : 'payroll.people.status.inactive')
}

async function load() {
  loading.value = true
  try {
    people.value = await payrollApi.people()
  } catch {
    toast.error(t('payroll.people.load_failed'))
  } finally {
    loading.value = false
  }
}

async function toggleDetail(person: PayrollPersonListItem) {
  if (expandedId.value === person.id) {
    expandedId.value = null
    advancedProfileOpen.value = false
    return
  }

  expandedId.value = person.id
  advancedProfileOpen.value = false
  if (details.value[person.id]) return

  loadingDetailId.value = person.id
  try {
    details.value[person.id] = await payrollApi.person(person.id)
  } catch {
    expandedId.value = null
    toast.error(t('payroll.people.detail_load_failed'))
  } finally {
    loadingDetailId.value = null
  }
}

function startCreate(personId: number) {
  const start = todayIso()
  creatingForId.value = personId
  newEmploymentMonthlyGross.value = null
  newEmploymentError.value = ''
  newEmployment.value = employmentDraft(
    '',
    'employment',
    start,
    null,
    details.value[personId]?.employments.every(item => !item.is_primary) ?? true,
  )
}

function employmentDraft(
  code: string,
  relationType: PayrollRelationType,
  start: string,
  monthlyGrossMinor: number | null,
  isPrimary: boolean,
): PayrollEmploymentCreatePayload {
  return {
    code,
    relation_type: relationType,
    monthly_gross_minor: monthlyGrossMinor,
    terms: {
      office_id: null,
      effective_from: start,
      contract_signed_on: null,
      planned_start_on: start,
      actual_start_on: null,
      fixed_term_end_on: null,
      weekly_hours: '40.00',
      workload_basis_points: 10000,
      work_place: null,
      regular_workplace: null,
      cz_isco_code: null,
      activity_code: null,
      social_insurance_participation: 'automatic',
      health_insurance_participation: 'automatic',
      tax_regime: 'advance',
      foreign_legislation_country_code: null,
      a1_certificate_until: null,
      risky_work: false,
      tax_declaration_signed: false,
      is_primary: isPrimary,
      change_reason: t('payroll.people.initial_terms'),
    },
  }
}

async function saveNew(personId: number) {
  if (!newEmployment.value || savingNew.value) return
  savingNew.value = true
  newEmploymentError.value = ''
  try {
    const employment = await payrollApi.createEmployment(personId, {
      ...newEmployment.value,
      monthly_gross_minor: Number(newEmploymentMonthlyGross.value) > 0
        ? Number(newEmploymentMonthlyGross.value) * 100
        : null,
    })
    const person = details.value[personId]
    if (person) person.employments.push(employment)
    const listItem = people.value.find(item => item.id === personId)
    if (listItem) {
      listItem.employment_count += 1
      if (!listItem.relation_types.includes(employment.relation_type)) {
        listItem.relation_types.push(employment.relation_type)
      }
      listItem.needs_setup = listItem.profile_status !== 'ready'
    }
    creatingForId.value = null
    newEmployment.value = null
    toast.success(t('payroll.people.employment_created'))
  } catch (error) {
    newEmploymentError.value = apiErrorMessage(error, t('payroll.people.mutation_failed'))
    toast.error(newEmploymentError.value)
  } finally {
    savingNew.value = false
  }
}

function updateEmployment(personId: number, updated: PayrollEmployment) {
  const employments = details.value[personId]?.employments
  if (!employments) return
  const index = employments.findIndex(item => item.id === updated.id)
  if (index >= 0) employments[index] = updated
}

async function createEmployee() {
  if (savingEmployee.value) return
  const fullName = employeeForm.full_name.trim()
  if (!fullName) {
    employeeError.value = t('payroll.people.create.name_required')
    toast.error(employeeError.value)
    return
  }
  savingEmployee.value = true
  employeeError.value = ''
  const payload: PayrollPersonCreatePayload = {
    full_name: fullName,
    birth_date: employeeForm.birth_date || null,
    birth_number: employeeForm.birth_number.trim() || null,
    relation_type: employeeForm.relation_type,
    planned_start_on: employeeForm.planned_start_on,
    monthly_gross: Number(employeeForm.monthly_gross) > 0
      ? Number(employeeForm.monthly_gross)
      : null,
  }
  try {
    const created = await payrollApi.createPerson(payload)
    showEmployeeForm.value = false
    await load()
    createdEmployeeId.value = created.id
    peopleFilter.value = 'all'
    searchQuery.value = ''
    details.value[created.id] = created
    expandedId.value = created.id
    toast.success(t('payroll.people.create.created'))
  } catch (error) {
    employeeError.value = apiErrorMessage(
      error,
      t('payroll.people.create.failed'),
    )
    toast.error(employeeError.value)
  } finally {
    savingEmployee.value = false
  }
}

function updatePersonProfile(updated: PayrollPersonProfile) {
  const person = people.value.find(item => item.id === updated.employee_id)
  if (!person) return
  person.full_name = updated.full_name
  person.profile_status = updated.profile_status
  person.needs_setup = updated.profile_status !== 'ready'
  const detail = details.value[updated.employee_id]
  if (detail) {
    detail.full_name = updated.full_name
    detail.profile_status = updated.profile_status
    detail.needs_setup = updated.profile_status !== 'ready'
  }
}

function updateQuickEdit(result: PayrollPersonQuickEditResponse) {
  updatePersonProfile(result.profile)
  if (result.employment) {
    updateEmployment(result.profile.employee_id, result.employment)
  }
}

function toggleAdvancedProfile(event: Event) {
  advancedProfileOpen.value = (event.currentTarget as HTMLDetailsElement).open
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.people.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.people.subtitle') }}</p>
      </div>
      <button
        type="button"
        :class="btnFilled('primary')"
        :disabled="!canCreatePerson"
        :title="canCreatePerson ? undefined : t('payroll.people.create.permission_required')"
        data-test="add-employee"
        @click="openEmployeeForm"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
        {{ t('payroll.people.create.action') }}
      </button>
    </header>

    <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 text-sm text-neutral-700">
      {{ t('payroll.people.shared_recap_hint') }}
    </section>

    <form
      v-if="showEmployeeForm"
      class="rounded-xl border border-payroll-500/30 bg-surface p-4 shadow-sm sm:p-5"
      data-test="new-employee-form"
      @submit.prevent="createEmployee"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.create.title') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.create.subtitle') }}</p>
        </div>
        <button type="button" :class="btnOutline('neutral')" @click="closeEmployeeForm">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
      </div>
      <div class="mt-4 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label class="min-w-0 text-xs text-neutral-600 sm:col-span-2">
          {{ t('payroll.people.create.full_name') }} *
          <input v-model="employeeForm.full_name" required class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="new-employee-name">
        </label>
        <label class="min-w-0 text-xs text-neutral-600">
          {{ t('payroll.people.create.birth_number') }}
          <input v-model="employeeForm.birth_number" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" inputmode="numeric" autocomplete="off" data-test="new-employee-birth-number">
          <span class="mt-1 block text-xs text-neutral-500">
            {{ t('payroll.people.quick_edit.sensitive_replace_hint') }}
          </span>
        </label>
        <label class="min-w-0 text-xs text-neutral-600">
          {{ t('payroll.people.create.birth_date') }}
          <input v-model="employeeForm.birth_date" type="date" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
        </label>
        <label class="min-w-0 text-xs text-neutral-600">
          {{ t('payroll.people.create.relation_type') }} *
          <SearchableSelect
            v-model="employeeForm.relation_type"
            class="mt-1"
            :options="relationOptions"
            :clearable="false"
            accent="payroll"
            data-test="new-employee-relation"
          />
        </label>
        <label class="min-w-0 text-xs text-neutral-600">
          {{ t('payroll.people.create.planned_start') }} *
          <input v-model="employeeForm.planned_start_on" required type="date" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="new-employee-planned-start">
        </label>
        <label class="min-w-0 text-xs text-neutral-600">
          {{ t('payroll.people.create.monthly_gross') }}
          <input v-model.number="employeeForm.monthly_gross" type="number" min="0" step="1" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
        </label>
      </div>
      <p v-if="employeeError" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert" data-test="new-employee-error">
        {{ employeeError }}
      </p>
      <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="closeEmployeeForm">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="submit" :class="btnFilled('primary')" :disabled="savingEmployee">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ t(savingEmployee ? 'common.saving' : 'common.save') }}
        </button>
      </div>
    </form>

    <p
      v-if="employeeError && !showEmployeeForm"
      class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="new-employee-error"
    >
      {{ employeeError }}
    </p>

    <section
      v-if="createdEmployeeId !== null"
      class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-800"
      data-test="employee-created-next"
    >
      <p class="font-medium">{{ t('payroll.people.create.next_steps') }}</p>
      <p class="mt-1 text-xs">{{ t('payroll.people.create.next_steps_hint') }}</p>
    </section>

    <section class="rounded-xl border border-neutral-200 bg-surface p-3 shadow-sm sm:p-4">
      <div class="flex min-w-0 flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div class="grid min-w-0 flex-1 grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_14rem]">
          <label class="min-w-0 text-xs font-medium text-neutral-600">
            {{ t('payroll.people.search') }}
            <div class="relative mt-1">
              <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
              <input v-model="searchQuery" type="search" class="h-9 w-full min-w-0 rounded-md border border-neutral-300 bg-surface pl-9 pr-3 text-sm" :placeholder="t('payroll.people.search_placeholder')" data-test="people-search">
            </div>
          </label>
          <label class="min-w-0 text-xs font-medium text-neutral-600">
            {{ t('payroll.people.filter') }}
            <SearchableSelect
              v-model="peopleFilter"
              class="mt-1"
              :options="filterOptions"
              :clearable="false"
              accent="payroll"
              data-test="people-filter"
            />
          </label>
        </div>
        <RouterLink
          :to="{ name: 'payroll-quick-inputs' }"
          :class="btnOutline('primary')"
          data-test="quick-inputs-link"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.coin" /></svg>
          {{ t('payroll.people.quick_inputs') }}
        </RouterLink>
      </div>
    </section>

    <div
      v-if="expandedId !== null && details[expandedId]"
      class="space-y-4"
      data-test="selected-person-editor"
    >
      <PayrollPersonQuickEdit
        :person-id="expandedId"
        :can-write="canQuickEditPerson"
        @saved="updateQuickEdit"
      />

      <details
        class="group overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
        data-test="advanced-person-profile"
        @toggle="toggleAdvancedProfile"
      >
        <summary class="cursor-pointer list-none px-4 py-4 sm:px-6">
          <span class="flex min-w-0 items-start justify-between gap-3">
            <span class="min-w-0">
              <span class="block text-sm font-semibold text-neutral-900">
                {{ t('payroll.people.quick_edit.advanced_title') }}
              </span>
              <span class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll.people.quick_edit.advanced_hint') }}
              </span>
            </span>
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="m6 9 6 6 6-6" />
            </svg>
          </span>
        </summary>
        <div v-if="advancedProfileOpen" class="border-t border-neutral-200 p-3 sm:p-4">
          <PayrollPersonProfilePanel
            :person-id="expandedId"
            :can-write="auth.canWrite('payroll.person.write')"
            @saved="updatePersonProfile"
          />
        </div>
      </details>
    </div>

    <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div v-if="loading" class="space-y-3 p-4 sm:p-6">
        <div v-for="index in 5" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div v-else-if="people.length === 0" class="p-8 text-center">
        <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.empty_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.empty_description') }}</p>
        <button
          type="button"
          :class="[btnFilled('primary'), 'mt-4']"
          :disabled="!canCreatePerson"
          :title="canCreatePerson ? undefined : t('payroll.people.create.permission_required')"
          data-test="empty-add-employee"
          @click="openEmployeeForm"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
          {{ t('payroll.people.create.action') }}
        </button>
      </div>

      <div v-else-if="filteredPeople.length === 0" class="p-8 text-center">
        <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.no_results_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.no_results_description') }}</p>
      </div>

      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-4 py-3">{{ t('payroll.people.columns.person') }}</th>
                <th class="px-4 py-3">{{ t('payroll.people.columns.status') }}</th>
                <th class="px-4 py-3">{{ t('payroll.people.columns.relations') }}</th>
                <th class="px-4 py-3 text-right">{{ t('payroll.people.columns.count') }}</th>
                <th class="px-4 py-3"><span class="sr-only">{{ t('payroll.people.columns.detail') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="person in filteredPeople" :key="person.id">
                <tr class="align-top">
                  <td class="px-4 py-3 font-medium text-neutral-900">{{ person.full_name }}</td>
                  <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1.5">
                      <span class="rounded-full px-2 py-1 text-xs font-medium" :class="person.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ statusLabel(person.is_active) }}</span>
                      <span v-if="person.needs_setup" class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700">{{ t('payroll.people.needs_setup') }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-neutral-600">{{ person.relation_types.map(relationLabel).join(', ') }}</td>
                  <td class="px-4 py-3 text-right text-neutral-700">{{ person.employment_count }}</td>
                  <td class="px-4 py-3 text-right">
                    <button :class="btnOutline('neutral')" :aria-expanded="expandedId === person.id" :data-test="`edit-employee-${person.id}`" @click="toggleDetail(person)">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.user" />
                      </svg>
                      {{ t(expandedId === person.id ? 'payroll.people.hide_detail' : 'payroll.people.show_detail') }}
                    </button>
                  </td>
                </tr>
                <tr v-if="expandedId === person.id">
                  <td colspan="5" class="bg-neutral-50 px-4 py-4">
                    <div v-if="loadingDetailId === person.id" class="h-24 animate-pulse rounded-lg bg-neutral-100" />
                    <div v-else-if="details[person.id]" class="space-y-3">
                      <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-neutral-500">{{ t('payroll.people.detail_hint') }}</p>
                        <button v-if="auth.canWrite('payroll.employment.write')" :class="btnFilled('primary')" @click="startCreate(person.id)">
                          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                          {{ t('payroll.people.add_employment') }}
                        </button>
                      </div>
                      <form v-if="creatingForId === person.id && newEmployment" class="grid grid-cols-1 gap-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-4 sm:grid-cols-2 lg:grid-cols-4" data-test="new-employment-form" @submit.prevent="saveNew(person.id)">
                        <label class="text-xs text-neutral-600">{{ t('payroll.people.code') }}<input v-model="newEmployment.code" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                        <label class="text-xs text-neutral-600">
                          {{ t('payroll.people.relation_type') }}
                          <SearchableSelect v-model="newEmployment.relation_type" class="mt-1" :options="relationOptions" :clearable="false" accent="payroll" />
                        </label>
                        <label class="text-xs text-neutral-600">{{ t('payroll.people.planned_start') }}<input v-model="newEmployment.terms.planned_start_on" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                         <label class="text-xs text-neutral-600">{{ t('payroll.people.weekly_hours') }}<input v-model="newEmployment.terms.weekly_hours" inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                         <label class="text-xs text-neutral-600">{{ t('payroll.people.create.monthly_gross') }}<input v-model.number="newEmploymentMonthlyGross" type="number" min="0" step="1" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                         <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="newEmployment.terms.is_primary" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.primary') }}</label>
                        <p v-if="newEmploymentError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700 sm:col-span-2 lg:col-span-4" role="alert">{{ newEmploymentError }}</p>
                        <div class="flex flex-wrap items-end justify-end gap-2 sm:col-span-2 lg:col-span-4">
                          <button type="button" :class="btnOutline('neutral')" @click="creatingForId = null"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
                          <button type="submit" :class="btnFilled('primary')" :disabled="savingNew"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
                        </div>
                      </form>
                      <EmploymentCard v-for="employment in details[person.id].employments" :key="employment.id" :employment="employment" :can-write="auth.canWrite('payroll.employment.write')" :can-read-documents="auth.canRead('payroll.documents')" :can-write-documents="auth.canWrite('payroll.documents')" @updated="updateEmployment(person.id, $event)" />
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div class="space-y-3 p-4 md:hidden">
          <article v-for="person in filteredPeople" :key="person.id" class="min-w-0 overflow-hidden rounded-lg border border-neutral-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <h2 class="font-semibold text-neutral-900">{{ person.full_name }}</h2>
              <div class="flex flex-wrap gap-1.5">
                <span class="rounded-full px-2 py-1 text-xs font-medium" :class="person.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ statusLabel(person.is_active) }}</span>
                <span v-if="person.needs_setup" class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700">{{ t('payroll.people.needs_setup') }}</span>
              </div>
            </div>
            <dl class="mt-3 space-y-2 text-sm">
              <div><dt class="text-xs text-neutral-500">{{ t('payroll.people.columns.relations') }}</dt><dd class="mt-0.5 text-neutral-800">{{ person.relation_types.map(relationLabel).join(', ') }}</dd></div>
              <div><dt class="text-xs text-neutral-500">{{ t('payroll.people.columns.count') }}</dt><dd class="mt-0.5 text-neutral-800">{{ person.employment_count }}</dd></div>
            </dl>
            <button :class="[btnOutline('neutral'), 'mt-4']" :aria-expanded="expandedId === person.id" :data-test="`edit-employee-${person.id}`" @click="toggleDetail(person)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.user" />
              </svg>
              {{ t(expandedId === person.id ? 'payroll.people.hide_detail' : 'payroll.people.show_detail') }}
            </button>
            <div v-if="expandedId === person.id" class="mt-4 border-t border-neutral-200 pt-4">
              <div v-if="loadingDetailId === person.id" class="h-24 animate-pulse rounded-lg bg-neutral-100" />
              <div v-else-if="details[person.id]" class="space-y-3">
                <p class="text-xs text-neutral-500">{{ t('payroll.people.detail_hint') }}</p>
                <button v-if="auth.canWrite('payroll.employment.write')" :class="btnFilled('primary')" @click="startCreate(person.id)">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                  {{ t('payroll.people.add_employment') }}
                </button>
                <form v-if="creatingForId === person.id && newEmployment" class="space-y-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3" data-test="new-employment-form" @submit.prevent="saveNew(person.id)">
                  <label class="block text-xs text-neutral-600">{{ t('payroll.people.code') }}<input v-model="newEmployment.code" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                  <label class="block text-xs text-neutral-600">
                    {{ t('payroll.people.relation_type') }}
                    <SearchableSelect v-model="newEmployment.relation_type" class="mt-1" :options="relationOptions" :clearable="false" accent="payroll" />
                  </label>
                  <label class="block text-xs text-neutral-600">{{ t('payroll.people.planned_start') }}<input v-model="newEmployment.terms.planned_start_on" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                  <label class="block text-xs text-neutral-600">{{ t('payroll.people.create.monthly_gross') }}<input v-model.number="newEmploymentMonthlyGross" type="number" min="0" step="1" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                  <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="newEmployment.terms.is_primary" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.primary') }}</label>
                  <p v-if="newEmploymentError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">{{ newEmploymentError }}</p>
                  <div class="flex flex-wrap justify-end gap-2"><button type="button" :class="btnOutline('neutral')" @click="creatingForId = null"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button><button type="submit" :class="btnFilled('primary')" :disabled="savingNew"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
                </form>
                <EmploymentCard v-for="employment in details[person.id].employments" :key="employment.id" :employment="employment" :can-write="auth.canWrite('payroll.employment.write')" :can-read-documents="auth.canRead('payroll.documents')" :can-write-documents="auth.canWrite('payroll.documents')" @updated="updateEmployment(person.id, $event)" />
              </div>
            </div>
          </article>
        </div>
      </template>
    </section>

  </div>
</template>
