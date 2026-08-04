<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollEmployment,
  type PayrollEmploymentCreatePayload,
  type PayrollPerson,
  type PayrollPersonListItem,
  type PayrollPersonProfile,
  type PayrollRelationType,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import EmploymentCard from './EmploymentCard.vue'
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
const creatingForId = ref<number | null>(null)
const savingNew = ref(false)
const newEmployment = ref<PayrollEmploymentCreatePayload | null>(null)
const relationTypes: PayrollRelationType[] = [
  'employment',
  'small_scale_employment',
  'dpp',
  'dpc',
  'partner_dependent',
  'statutory_body',
]

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
    return
  }

  expandedId.value = person.id
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
  newEmployment.value = {
    code: '',
    relation_type: 'employment',
    monthly_gross_minor: null,
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
      is_primary: details.value[personId]?.employments.every(item => !item.is_primary) ?? true,
      change_reason: t('payroll.people.initial_terms'),
    },
  }
}

async function saveNew(personId: number) {
  if (!newEmployment.value || savingNew.value) return
  savingNew.value = true
  try {
    const employment = await payrollApi.createEmployment(personId, newEmployment.value)
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
  } catch {
    toast.error(t('payroll.people.mutation_failed'))
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

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.people.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.people.subtitle') }}</p>
      </div>
    </header>

    <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 text-sm text-neutral-700">
      {{ t('payroll.people.shared_recap_hint') }}
    </section>

    <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div v-if="loading" class="space-y-3 p-4 sm:p-6">
        <div v-for="index in 5" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div v-else-if="people.length === 0" class="p-8 text-center">
        <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.empty_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.empty_description') }}</p>
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
              <template v-for="person in people" :key="person.id">
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
                    <button :class="btnOutline('neutral')" :aria-expanded="expandedId === person.id" @click="toggleDetail(person)">
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
                      <form v-if="creatingForId === person.id && newEmployment" class="grid grid-cols-1 gap-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="saveNew(person.id)">
                        <label class="text-xs text-neutral-600">{{ t('payroll.people.code') }}<input v-model="newEmployment.code" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                        <label class="text-xs text-neutral-600">{{ t('payroll.people.relation_type') }}<select v-model="newEmployment.relation_type" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="type in relationTypes" :key="type" :value="type">{{ relationLabel(type) }}</option></select></label>
                        <label class="text-xs text-neutral-600">{{ t('payroll.people.planned_start') }}<input v-model="newEmployment.terms.planned_start_on" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                        <label class="text-xs text-neutral-600">{{ t('payroll.people.weekly_hours') }}<input v-model="newEmployment.terms.weekly_hours" inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                        <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="newEmployment.terms.is_primary" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.primary') }}</label>
                        <div class="flex flex-wrap items-end justify-end gap-2 sm:col-span-2 lg:col-span-3">
                          <button type="button" :class="btnOutline('neutral')" @click="creatingForId = null">{{ t('common.cancel') }}</button>
                          <button type="submit" :class="btnFilled('primary')" :disabled="savingNew">{{ t('common.save') }}</button>
                        </div>
                      </form>
                      <EmploymentCard v-for="employment in details[person.id].employments" :key="employment.id" :employment="employment" :can-write="auth.canWrite('payroll.employment.write')" @updated="updateEmployment(person.id, $event)" />
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div class="space-y-3 p-4 md:hidden">
          <article v-for="person in people" :key="person.id" class="rounded-lg border border-neutral-200 p-4">
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
            <button :class="[btnOutline('neutral'), 'mt-4']" :aria-expanded="expandedId === person.id" @click="toggleDetail(person)">
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
                <form v-if="creatingForId === person.id && newEmployment" class="space-y-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3" @submit.prevent="saveNew(person.id)">
                  <label class="block text-xs text-neutral-600">{{ t('payroll.people.code') }}<input v-model="newEmployment.code" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                  <label class="block text-xs text-neutral-600">{{ t('payroll.people.relation_type') }}<select v-model="newEmployment.relation_type" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="type in relationTypes" :key="type" :value="type">{{ relationLabel(type) }}</option></select></label>
                  <label class="block text-xs text-neutral-600">{{ t('payroll.people.planned_start') }}<input v-model="newEmployment.terms.planned_start_on" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
                  <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="newEmployment.terms.is_primary" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.primary') }}</label>
                  <div class="flex flex-wrap justify-end gap-2"><button type="button" :class="btnOutline('neutral')" @click="creatingForId = null">{{ t('common.cancel') }}</button><button type="submit" :class="btnFilled('primary')" :disabled="savingNew">{{ t('common.save') }}</button></div>
                </form>
                <EmploymentCard v-for="employment in details[person.id].employments" :key="employment.id" :employment="employment" :can-write="auth.canWrite('payroll.employment.write')" @updated="updateEmployment(person.id, $event)" />
              </div>
            </div>
          </article>
        </div>
      </template>
    </section>

    <PayrollPersonProfilePanel
      v-if="expandedId !== null && details[expandedId]"
      :person-id="expandedId"
      :can-write="auth.canWrite('payroll.person.write')"
      @saved="updatePersonProfile"
    />
  </div>
</template>
