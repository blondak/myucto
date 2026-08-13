<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollChecklistStatus,
  type PayrollEmployment,
  type PayrollEmploymentStatus,
  type PayrollEmploymentJmhzEvidenceOptions,
  type PayrollEmploymentTermsPayload,
} from '@/api/payroll'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnOutlineSm } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'
import EmploymentDimensionsPanel from './EmploymentDimensionsPanel.vue'
import EmploymentExitDocumentsPanel from './EmploymentExitDocumentsPanel.vue'
import { todayIso, transitionPresentation } from './employmentLifecycleUi'

const props = defineProps<{
  employment: PayrollEmployment
  canWrite: boolean
  canReadDocuments?: boolean
  canWriteDocuments?: boolean
}>()
const emit = defineEmits<{
  updated: [employment: PayrollEmployment]
}>()

const { t } = useI18n()
const toast = useToast()
const busy = ref(false)
const editingTerms = ref(false)
const transitionDate = ref(todayIso())
const termsForm = ref<PayrollEmploymentTermsPayload | null>(null)
const jmhzOptions = ref<PayrollEmploymentJmhzEvidenceOptions | null>(null)
const jmhzOptionsFailed = ref(false)

const currentTerms = computed(() => props.employment.terms[0] ?? null)
const openChecklist = computed(() =>
  props.employment.checklist.filter(item => item.status === 'pending'),
)

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' })
    .format(new Date(`${value}T00:00:00`))
}

function relationLabel(): string {
  return t(`payroll.people.relations.${props.employment.relation_type}`)
}

function statusLabel(status: PayrollEmploymentStatus): string {
  return t(`payroll.people.employment_status.${status}`)
}

async function startTermsEdit() {
  const terms = currentTerms.value
  if (!terms) return
  termsForm.value = {
    office_id: terms.office_id,
    effective_from: todayIso(),
    contract_signed_on: terms.contract_signed_on,
    planned_start_on: terms.planned_start_on,
    actual_start_on: terms.actual_start_on,
    fixed_term_end_on: terms.fixed_term_end_on,
    weekly_hours: terms.weekly_hours,
    workload_basis_points: terms.workload_basis_points,
    work_place: terms.work_place,
    regular_workplace: terms.regular_workplace,
    jmhz_workplace_municipality_code: terms.jmhz_workplace_municipality_code,
    jmhz_workplace_country_code: terms.jmhz_workplace_country_code,
    jmhz_apz_contribution_status: terms.jmhz_apz_contribution_status,
    jmhz_apz_instrument_code: terms.jmhz_apz_instrument_code,
    jmhz_functional_benefits_status: terms.jmhz_functional_benefits_status,
    jmhz_temporary_assignment_status: terms.jmhz_temporary_assignment_status,
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
    change_reason: null,
  }
  editingTerms.value = true
  if (jmhzOptions.value === null && !jmhzOptionsFailed.value) {
    try {
      jmhzOptions.value = await payrollApi.employmentJmhzEvidenceOptions()
    } catch {
      jmhzOptionsFailed.value = true
    }
  }
}

function onApzStatusChange() {
  if (termsForm.value?.jmhz_apz_contribution_status !== 'yes' && termsForm.value) {
    termsForm.value.jmhz_apz_instrument_code = null
  }
}

async function transition(target: PayrollEmploymentStatus) {
  if (!transitionDate.value || busy.value) return
  if (['ended', 'archived', 'no_show'].includes(target)
      && !window.confirm(t(`payroll.people.transition_confirm.${target}`))) return

  busy.value = true
  try {
    const updated = await payrollApi.transitionEmployment(props.employment.id, target, {
      row_version: props.employment.row_version,
      effective_on: transitionDate.value,
    })
    emit('updated', updated)
    toast.success(t('payroll.people.transition_saved'))
  } catch {
    toast.error(t('payroll.people.mutation_failed'))
  } finally {
    busy.value = false
  }
}

async function saveTerms() {
  if (!termsForm.value || busy.value) return
  busy.value = true
  try {
    const updated = await payrollApi.addEmploymentTerms(
      props.employment.id,
      props.employment.row_version,
      termsForm.value,
    )
    emit('updated', updated)
    editingTerms.value = false
    toast.success(t('payroll.people.terms_saved'))
  } catch {
    toast.error(t('payroll.people.mutation_failed'))
  } finally {
    busy.value = false
  }
}

async function setChecklist(itemKey: string, rowVersion: number, status: PayrollChecklistStatus) {
  if (busy.value) return
  busy.value = true
  try {
    const updated = await payrollApi.updateEmploymentChecklist(props.employment.id, itemKey, {
      row_version: rowVersion,
      status,
    })
    emit('updated', updated)
  } catch {
    toast.error(t('payroll.people.mutation_failed'))
  } finally {
    busy.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  ...transitionPresentation(props.employment.allowed_transitions).map(presentation => ({
    key: `transition-${presentation.target}`,
    label: t(`payroll.people.transition.${presentation.target}`),
    icon: presentation.icon,
    tier: presentation.tier,
    variant: presentation.variant,
    disabled: busy.value || !transitionDate.value,
    show: props.canWrite,
    run: () => void transition(presentation.target),
  } satisfies ActionItem)),
  {
    key: 'new-terms',
    label: t('payroll.people.new_terms'),
    icon: 'edit',
    tier: 'secondary',
    variant: 'neutral',
    disabled: busy.value || currentTerms.value === null,
    show: props.canWrite
      && ['planned', 'preregistered', 'active', 'suspended'].includes(props.employment.status),
    run: () => void startTermsEdit(),
  },
])
</script>

<template>
  <article class="rounded-lg border border-neutral-200 bg-surface p-3 sm:p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="font-semibold text-neutral-900">{{ relationLabel() }}</h3>
          <span class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-700">
            {{ statusLabel(employment.status) }}
          </span>
          <span v-if="employment.is_primary" class="rounded-full bg-success-50 px-2 py-1 text-xs font-medium text-success-700">
            {{ t('payroll.people.primary') }}
          </span>
          <span v-if="employment.is_legacy_projection" class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-600">
            {{ t('payroll.people.legacy_projection') }}
          </span>
        </div>
        <p class="mt-1 text-xs text-neutral-500">{{ employment.code }}<template v-if="employment.office_name"> · {{ employment.office_name }}</template></p>
      </div>
      <div v-if="canWrite && employment.allowed_transitions.length" class="flex items-center gap-2">
        <label class="text-xs text-neutral-500">
          <span class="sr-only">{{ t('payroll.people.transition_date') }}</span>
          <input v-model="transitionDate" type="date" class="h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
        </label>
      </div>
    </div>

    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs lg:grid-cols-4">
      <div><dt class="text-neutral-500">{{ t('payroll.people.start_date') }}</dt><dd class="mt-0.5 text-neutral-800">{{ formatDate(employment.start_date) }}</dd></div>
      <div><dt class="text-neutral-500">{{ t('payroll.people.actual_start') }}</dt><dd class="mt-0.5 text-neutral-800">{{ formatDate(employment.actual_start_date) }}</dd></div>
      <div><dt class="text-neutral-500">{{ t('payroll.people.end_date') }}</dt><dd class="mt-0.5 text-neutral-800">{{ formatDate(employment.end_date) }}</dd></div>
      <div><dt class="text-neutral-500">{{ t('payroll.people.accounting') }}</dt><dd class="mt-0.5 text-neutral-800">{{ employment.accounting.gross_debit }}/{{ employment.accounting.gross_credit }} · {{ employment.accounting.employer_insurance_debit }}/{{ employment.accounting.employer_insurance_credit }}</dd></div>
    </dl>

    <ActionBar v-if="actions.some(action => action.show)" :actions="actions" class="mt-4" />

    <form v-if="editingTerms && termsForm" class="mt-4 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3 sm:p-4" @submit.prevent="saveTerms">
      <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.new_terms') }}</h4>
      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-xs text-neutral-600">{{ t('payroll.people.effective_from') }}<input v-model="termsForm.effective_from" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.contract_signed') }}<input v-model="termsForm.contract_signed_on" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.planned_start') }}<input v-model="termsForm.planned_start_on" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.actual_start') }}<input v-model="termsForm.actual_start_on" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.fixed_end') }}<input v-model="termsForm.fixed_term_end_on" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.weekly_hours') }}<input v-model="termsForm.weekly_hours" inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.workload_bps') }}<input v-model.number="termsForm.workload_basis_points" type="number" min="1" max="10000" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600 sm:col-span-2">{{ t('payroll.people.regular_workplace') }}<input v-model="termsForm.regular_workplace" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <fieldset data-test="jmhz-evidence" class="grid grid-cols-1 gap-3 rounded-md border border-payroll-200 bg-surface p-3 sm:col-span-2 sm:grid-cols-2 lg:col-span-4 lg:grid-cols-4">
          <legend class="px-1 text-xs font-semibold text-payroll-800">{{ t('payroll.people.jmhz_evidence.title') }}</legend>
          <label class="text-xs text-neutral-600 lg:col-span-2">{{ t('payroll.people.jmhz_evidence.municipality_name') }}<input v-model="termsForm.work_place" maxlength="255" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.municipality_code') }}<input v-model="termsForm.jmhz_workplace_municipality_code" maxlength="6" inputmode="numeric" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm font-mono"></label>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.country_code') }}<input v-model="termsForm.jmhz_workplace_country_code" maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm font-mono uppercase"></label>
          <p class="text-xs text-warning-700 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.jmhz_evidence.external_codebook_warning') }}</p>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.apz_status') }}<select v-model="termsForm.jmhz_apz_contribution_status" data-test="jmhz-apz-status" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" @change="onApzStatusChange"><option v-for="state in ['unverified','no','yes']" :key="state" :value="state">{{ t(`payroll.people.jmhz_evidence.state.${state}`) }}</option></select></label>
          <label v-if="termsForm.jmhz_apz_contribution_status === 'yes'" class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.apz_instrument') }}<select v-model="termsForm.jmhz_apz_instrument_code" data-test="jmhz-apz-instrument" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option :value="null" disabled>{{ t('payroll.people.jmhz_evidence.select_apz') }}</option><option v-for="option in jmhzOptions?.apz_instruments ?? []" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option></select></label>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.functional_benefits') }}<select v-model="termsForm.jmhz_functional_benefits_status" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="state in ['unverified','no','yes']" :key="state" :value="state">{{ t(`payroll.people.jmhz_evidence.state.${state}`) }}</option></select></label>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.temporary_assignment') }}<select v-model="termsForm.jmhz_temporary_assignment_status" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="state in ['unverified','no','yes']" :key="state" :value="state">{{ t(`payroll.people.jmhz_evidence.state.${state}`) }}</option></select></label>
          <p v-if="jmhzOptionsFailed" class="text-xs text-danger-700 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.jmhz_evidence.options_failed') }}</p>
          <p v-if="termsForm.jmhz_temporary_assignment_status === 'yes'" class="text-xs text-warning-700 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.jmhz_evidence.temporary_assignment_blocker') }}</p>
        </fieldset>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.cz_isco_code') }}<input v-model="termsForm.cz_isco_code" maxlength="16" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.activity_code') }}<input v-model="termsForm.activity_code" maxlength="32" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.social_mode') }}<select v-model="termsForm.social_insurance_participation" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="mode in ['automatic','included','excluded','foreign']" :key="mode" :value="mode">{{ t(`payroll.people.insurance_mode.${mode}`) }}</option></select></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.health_mode') }}<select v-model="termsForm.health_insurance_participation" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="mode in ['automatic','included','excluded','foreign']" :key="mode" :value="mode">{{ t(`payroll.people.insurance_mode.${mode}`) }}</option></select></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.tax_regime_label') }}<select v-model="termsForm.tax_regime" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="mode in ['advance','withholding','foreign','manual_review']" :key="mode" :value="mode">{{ t(`payroll.people.tax_regime.${mode}`) }}</option></select></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.foreign_country') }}<input v-model="termsForm.foreign_legislation_country_code" maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm uppercase"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.a1_certificate_until') }}<input v-model="termsForm.a1_certificate_until" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="termsForm.is_primary" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.primary') }}</label>
        <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="termsForm.tax_declaration_signed" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.tax_declaration') }}</label>
        <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="termsForm.risky_work" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.risky_work') }}</label>
        <label class="text-xs text-neutral-600 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.change_reason') }}<textarea v-model="termsForm.change_reason" rows="2" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></textarea></label>
      </div>
      <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutlineSm('neutral')" @click="editingTerms = false">{{ t('common.cancel') }}</button>
        <button type="submit" :class="btnOutlineSm('accent')" :disabled="busy">{{ t('common.save') }}</button>
      </div>
    </form>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
      <section>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.checklist_title') }}</h4>
          <span class="text-xs text-neutral-500">{{ t('payroll.people.checklist_open', { count: openChecklist.length }) }}</span>
        </div>
        <div class="mt-2 space-y-2">
          <div v-for="item in employment.checklist" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-neutral-50 px-3 py-2 text-xs">
            <div>
              <p class="font-medium text-neutral-800">{{ t(`payroll.people.checklist.${item.item_key}`) }}</p>
              <p class="text-neutral-500">{{ formatDate(item.due_date) }} · {{ t(`payroll.people.checklist_status.${item.status}`) }}</p>
            </div>
            <div v-if="canWrite" class="flex flex-wrap gap-1">
              <button v-if="item.status !== 'completed'" type="button" :class="btnOutlineSm('success')" :disabled="busy" @click="setChecklist(item.item_key, item.row_version, 'completed')">{{ t('payroll.people.complete') }}</button>
              <button v-else type="button" :class="btnOutlineSm('neutral')" :disabled="busy" @click="setChecklist(item.item_key, item.row_version, 'pending')">{{ t('payroll.people.reopen') }}</button>
            </div>
          </div>
        </div>
      </section>

      <section>
        <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.timeline_title') }}</h4>
        <ol class="mt-2 space-y-3 border-l border-payroll-500/30 pl-4">
          <li v-for="event in employment.timeline" :key="event.id" class="relative text-xs">
            <span class="absolute -left-[1.18rem] top-1 h-2 w-2 rounded-full bg-payroll-500"></span>
            <p class="font-medium text-neutral-800">{{ t(`payroll.people.event.${event.event_type}`) }}</p>
            <p class="text-neutral-500">{{ formatDate(event.effective_on) }}<template v-if="event.from_status && event.to_status"> · {{ statusLabel(event.from_status) }} → {{ statusLabel(event.to_status) }}</template></p>
            <ul v-if="event.diff" class="mt-1 space-y-0.5 text-neutral-600">
              <li v-for="(change, key) in event.diff" :key="key">{{ t(`payroll.people.term_field.${key}`) }}: {{ String(change.from ?? '—') }} → {{ String(change.to ?? '—') }}</li>
            </ul>
            <p v-if="event.note" class="mt-1 text-neutral-600">{{ event.note }}</p>
          </li>
        </ol>
      </section>
    </div>

    <EmploymentDimensionsPanel
      :employment-id="employment.id"
      :can-write="canWrite"
    />

    <EmploymentExitDocumentsPanel
      v-if="employment.end_date && canReadDocuments"
      :employment="employment"
      :can-write="canWriteDocuments === true"
    />
  </article>
</template>
