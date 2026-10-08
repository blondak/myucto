<script setup lang="ts">
/**
 * Výzvy a žádosti v důchodovém pojištění u osoby.
 *
 * Část povinností k důchodovému pojištění vzniká až výzvou nebo žádostí a lhůta
 * běží od jejich doručení: evidenční list na výzvu ČSSZ/ÚSSZ nebo po úmrtí,
 * oprava měsíčního hlášení na výzvu (§ 38a odst. 1), potvrzení o době pojištění
 * (§ 42), o náhradách za ztrátu na výdělku (§ 37 odst. 2) a potvrzení podle
 * znění do 31. 12. 2025. Termín spočítá server a zapsaná výzva se objeví
 * v hlídači termínů. Evidenční list na výzvu se připraví na obrazovce ELDP
 * s údaji výzvy; od té chvíle termín nese povinnost listu.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollEmployment,
  type PayrollPensionRequest,
  type PayrollPensionRequestKind,
  type PayrollPensionRequestLegacyKind,
  type PayrollPensionRequester,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { personalNumberLabel } from './employmentLifecycleUi'
import DateInput from '@/components/ui/DateInput.vue'
import RequiredMark from '@/components/ui/RequiredMark.vue'

const props = defineProps<{
  personId: number
  canWrite: boolean
}>()

const { t } = useI18n()
const toast = useToast()

const KINDS: PayrollPensionRequestKind[] = [
  'eldp',
  'jmh_correction',
  'insurance_period_confirmation',
  'compensation_confirmation',
  'legacy_confirmation',
]
/* Zrcadlí PensionRequestDeadlinePolicy::REQUESTERS; server je autorita. */
const REQUESTERS: Record<PayrollPensionRequestKind, PayrollPensionRequester[]> = {
  eldp: ['cssz', 'ossz', 'survivor'],
  jmh_correction: ['cssz', 'ossz'],
  insurance_period_confirmation: ['employee', 'former_employee', 'ossz'],
  compensation_confirmation: ['employee', 'former_employee'],
  legacy_confirmation: ['employee', 'former_employee'],
}
const LEGACY_KINDS: PayrollPensionRequestLegacyKind[] = ['excluded_periods', 'deep_mining', 'risky_work', 'rescuer']
/* Od roku 2027 lhůtu listu na výzvu neurčuje zákon, ale výzva (EldpDeadlinePolicy). */
const STATED_DUE_REQUIRED_FROM_YEAR = 2027

const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const saveError = ref('')
const requests = ref<PayrollPensionRequest[]>([])
const employments = ref<PayrollEmployment[]>([])
const references = ref<Record<number, string>>({})
const today = appIsoDate()
const form = ref({
  request_kind: 'eldp' as PayrollPensionRequestKind,
  requester: 'ossz' as PayrollPensionRequester,
  legacy_kind: 'risky_work' as PayrollPensionRequestLegacyKind,
  received_on: today,
  employment_id: null as number | null,
  period_year: Number(today.slice(0, 4)),
  period_month: today.slice(0, 7),
  death_on: '',
  stated_due_on: '',
  requester_reference: '',
  note: '',
})

const openRequests = computed(() => requests.value.filter(request => request.status === 'open'))
const requesterOptions = computed(() => REQUESTERS[form.value.request_kind])
const needsEmployment = computed(() =>
  form.value.request_kind === 'eldp' || form.value.request_kind === 'insurance_period_confirmation')
const needsYear = computed(() => needsEmployment.value || form.value.request_kind === 'legacy_confirmation')
const needsMonth = computed(() => form.value.request_kind === 'jmh_correction')
const isSurvivor = computed(() => form.value.request_kind === 'eldp' && form.value.requester === 'survivor')
const showsStatedDue = computed(() => form.value.request_kind === 'eldp' && !isSurvivor.value)
const statedDueRequired = computed(() =>
  showsStatedDue.value && Number(form.value.period_year) >= STATED_DUE_REQUIRED_FROM_YEAR)
const yearOptions = computed(() => {
  const received = Number((form.value.received_on || today).slice(0, 4))
  return Array.from({ length: 8 }, (_, index) => received - index)
})
const employmentOptions = computed(() => employments.value.map(employment => ({
  value: employment.id,
  label: `${personalNumberLabel(t, employment.code) || '—'} (${employment.start_date ?? '?'} – ${employment.end_date ?? '…'})`,
})))
const missing = computed<string[]>(() => {
  const list: string[] = []
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.value.received_on)) list.push(t('payroll.people.pension_requests.missing.received_on'))
  if (needsEmployment.value && form.value.employment_id === null) list.push(t('payroll.people.pension_requests.missing.employment'))
  if (needsMonth.value && !/^\d{4}-\d{2}$/.test(form.value.period_month)) list.push(t('payroll.people.pension_requests.missing.period_month'))
  if (isSurvivor.value && form.value.death_on === '') list.push(t('payroll.people.pension_requests.missing.death_on'))
  if (statedDueRequired.value && form.value.stated_due_on === '') list.push(t('payroll.people.pension_requests.missing.stated_due_on'))
  return list
})

onMounted(load)
watch(() => props.personId, load)
watch(() => form.value.request_kind, kind => {
  if (!REQUESTERS[kind].includes(form.value.requester)) form.value.requester = REQUESTERS[kind][0]!
})

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const [list, person] = await Promise.all([
      payrollApi.pensionRequests(props.personId),
      payrollApi.person(props.personId),
    ])
    requests.value = list
    employments.value = person.employments
    if (form.value.employment_id === null && employments.value.length === 1) {
      form.value.employment_id = employments.value[0]!.id
    }
  } catch (error) {
    loadError.value = apiErrorMessage(error, t('payroll.people.pension_requests.load_failed'))
  } finally {
    loading.value = false
  }
}

async function run(action: () => Promise<PayrollPensionRequest[]>, success?: string): Promise<void> {
  if (!props.canWrite || saving.value) return
  saving.value = true
  saveError.value = ''
  try {
    requests.value = await action()
    if (success) toast.success(success)
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.people.pension_requests.save_failed'))
  } finally {
    saving.value = false
  }
}

function save(): Promise<void> {
  const value = form.value
  return run(() => payrollApi.createPensionRequest(props.personId, {
    request_kind: value.request_kind,
    legacy_kind: value.request_kind === 'legacy_confirmation' ? value.legacy_kind : null,
    requester: value.requester,
    requester_reference: value.requester_reference.trim() || null,
    received_on: value.received_on,
    employment_id: needsEmployment.value ? value.employment_id : null,
    period_year: needsYear.value ? Number(value.period_year) : null,
    period_from: needsMonth.value ? `${value.period_month}-01` : null,
    death_on: isSurvivor.value ? value.death_on : null,
    stated_due_on: showsStatedDue.value && value.stated_due_on !== '' ? value.stated_due_on : null,
    note: value.note.trim() || null,
  }), t('payroll.people.pension_requests.saved')).then(() => {
    if (saveError.value === '') {
      form.value.requester_reference = ''
      form.value.note = ''
    }
  })
}

function complete(request: PayrollPensionRequest): Promise<void> {
  const reference = (references.value[request.id] ?? '').trim()
  return run(() => payrollApi.completePensionRequest(props.personId, request.id, appIsoDate(), reference || null))
}

function recordCopy(request: PayrollPensionRequest): Promise<void> {
  return run(() => payrollApi.recordPensionRequestCopy(props.personId, request.id, appIsoDate()))
}

function remove(request: PayrollPensionRequest): Promise<void> {
  return run(() => payrollApi.deletePensionRequest(props.personId, request.id))
}

async function downloadCertificate(request: PayrollPensionRequest): Promise<void> {
  saveError.value = ''
  try {
    await payrollApi.downloadPensionInsuranceCertificate(props.personId, request.id)
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.people.pension_requests.certificate_failed'))
  }
}

function eldpLink(request: PayrollPensionRequest): { path: string, query: Record<string, string> } {
  return {
    path: '/payroll/submissions/eldp',
    query: {
      person: String(props.personId),
      employment: String(request.employment_id ?? ''),
      year: String(request.period_year ?? ''),
      pension_request: String(request.id),
    },
  }
}

/* Stejnopis dostává zaměstnanec u listu a potvrzení, u hornictví ho dostává ČSSZ. */
function tracksCopy(request: PayrollPensionRequest): boolean {
  return request.request_kind === 'eldp'
    || request.request_kind === 'insurance_period_confirmation'
    || (request.request_kind === 'legacy_confirmation'
      && (request.legacy_kind === 'deep_mining' || request.legacy_kind === 'risky_work' || request.legacy_kind === 'rescuer'))
}

function kindLabel(request: PayrollPensionRequest): string {
  return request.legacy_kind
    ? t(`payroll.people.pension_requests.legacy.${request.legacy_kind}`)
    : t(`payroll.people.pension_requests.kind.${request.request_kind}`)
}

function periodLabel(request: PayrollPensionRequest): string {
  if (request.period_year !== null) return String(request.period_year)
  if (request.period_from !== null) return request.period_from.slice(0, 7)
  return ''
}

function statusClass(status: PayrollPensionRequest['status']): string {
  if (status === 'open') return 'bg-warning-50 text-warning-700'
  if (status === 'statement_prepared') return 'bg-primary-50 text-primary-700'
  return 'bg-success-50 text-success-700'
}
</script>

<template>
  <details class="group rounded-lg border border-payroll-500/30 bg-surface" data-test="pension-requests">
    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
      <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-neutral-900">{{ t('payroll.people.pension_requests.title') }}</span>
        <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll.people.pension_requests.subtitle') }}</span>
      </span>
      <span v-if="openRequests.length" class="shrink-0 rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800" data-test="pension-requests-open-count">
        {{ t('payroll.people.pension_requests.open_count', { count: openRequests.length }, openRequests.length) }}
      </span>
    </summary>

    <div class="space-y-3 border-t border-neutral-200 p-3">
      <div v-if="loading" class="h-16 animate-pulse rounded-md bg-neutral-100" />
      <p v-else-if="loadError" class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700" role="alert">{{ loadError }}</p>
      <template v-else>
        <p v-if="requests.length === 0" class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600" data-test="pension-requests-empty">
          {{ t('payroll.people.pension_requests.empty') }}
        </p>
        <div v-else class="space-y-2" data-test="pension-requests-list">
          <article v-for="request in requests" :key="request.id" class="rounded-md border border-neutral-200 p-2 text-xs" :data-test="`pension-request-${request.id}`">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-medium text-neutral-900">
                {{ kindLabel(request) }}<template v-if="periodLabel(request)"> · {{ periodLabel(request) }}</template>
              </span>
              <span :class="`rounded-full px-2 py-0.5 font-medium ${statusClass(request.status)}`" :data-test="`pension-request-status-${request.id}`">
                {{ t(`payroll.people.pension_requests.status.${request.status}`) }}
              </span>
            </div>
            <p class="mt-1 text-neutral-700">
              {{ t('payroll.people.pension_requests.row', {
                requester: t(`payroll.people.pension_requests.requester.${request.requester}`),
                received: formatDate(request.received_on),
                due: formatDate(request.due_on),
              }) }}
              <template v-if="request.requester_reference"> · {{ request.requester_reference }}</template>
            </p>
            <p class="mt-1 text-neutral-500">{{ request.deadline_source }}</p>
            <p v-if="request.copy_delivered_on" class="mt-1 text-success-700" :data-test="`pension-request-copy-${request.id}`">
              {{ t('payroll.people.pension_requests.copy_delivered', { date: formatDate(request.copy_delivered_on) }) }}
            </p>
            <p v-if="request.completed_on" class="mt-1 text-neutral-600">
              {{ t('payroll.people.pension_requests.completed_on', { date: formatDate(request.completed_on) }) }}
              <template v-if="request.completion_reference"> · {{ request.completion_reference }}</template>
            </p>
            <p v-if="request.note" class="mt-1 text-neutral-600">{{ request.note }}</p>
            <div v-if="canWrite" class="mt-2 flex flex-wrap items-center gap-2">
              <RouterLink
                v-if="request.request_kind === 'eldp' && request.status !== 'completed'"
                :to="eldpLink(request)"
                :class="[request.status === 'open' ? btnFilled('primary') : btnOutline('primary'), '!px-2 !py-1 !text-xs whitespace-nowrap']"
                :data-test="`pension-request-eldp-${request.id}`"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
                {{ request.status === 'open' ? t('payroll.people.pension_requests.prepare_eldp') : t('payroll.people.pension_requests.open_eldp') }}
              </RouterLink>
              <button
                v-if="request.request_kind === 'insurance_period_confirmation'"
                type="button"
                :class="[request.status === 'open' ? btnFilled('primary') : btnOutline('primary'), '!px-2 !py-1 !text-xs whitespace-nowrap']"
                :data-test="`pension-request-certificate-${request.id}`"
                @click="downloadCertificate(request)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
                {{ t('payroll.people.pension_requests.download_certificate') }}
              </button>
              <button
                v-if="tracksCopy(request) && request.copy_delivered_on === null"
                type="button"
                :class="[btnOutline('neutral'), '!px-2 !py-1 !text-xs whitespace-nowrap']"
                :disabled="saving"
                :data-test="`pension-request-record-copy-${request.id}`"
                @click="recordCopy(request)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.send" /></svg>
                {{ t(request.legacy_kind === 'deep_mining' ? 'payroll.people.pension_requests.record_copy_cssz' : 'payroll.people.pension_requests.record_copy') }}
              </button>
              <template v-if="request.status !== 'completed'">
                <input
                  v-model="references[request.id]"
                  maxlength="190"
                  :placeholder="t('payroll.people.pension_requests.completion_reference')"
                  class="min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-2 py-1 text-xs sm:max-w-56"
                  :data-test="`pension-request-reference-${request.id}`"
                >
                <button
                  type="button"
                  :class="[btnOutline('success'), '!px-2 !py-1 !text-xs whitespace-nowrap']"
                  :disabled="saving"
                  :data-test="`pension-request-complete-${request.id}`"
                  @click="complete(request)"
                >
                  <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                  {{ t('payroll.people.pension_requests.complete') }}
                </button>
              </template>
              <button
                v-if="request.eldp_statement_id === null"
                type="button"
                :class="[btnOutline('danger'), '!px-2 !py-1 !text-xs whitespace-nowrap']"
                :disabled="saving"
                :data-test="`pension-request-delete-${request.id}`"
                @click="remove(request)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                {{ t('common.delete') }}
              </button>
            </div>
          </article>
        </div>

        <p v-if="saveError" class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700" role="alert" data-test="pension-request-error">{{ saveError }}</p>

        <form v-if="canWrite" class="grid grid-cols-1 gap-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3 sm:grid-cols-3" data-test="pension-request-form" @submit.prevent="save">
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.kind') }} <RequiredMark />
            <select v-model="form.request_kind" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-kind">
              <option v-for="kind in KINDS" :key="kind" :value="kind">{{ t(`payroll.people.pension_requests.kind.${kind}`) }}</option>
            </select>
          </label>
          <label v-if="form.request_kind === 'legacy_confirmation'" class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.legacy_kind') }} <RequiredMark />
            <select v-model="form.legacy_kind" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-legacy-kind">
              <option v-for="legacy in LEGACY_KINDS" :key="legacy" :value="legacy">{{ t(`payroll.people.pension_requests.legacy.${legacy}`) }}</option>
            </select>
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.requester') }} <RequiredMark />
            <select v-model="form.requester" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-requester">
              <option v-for="requester in requesterOptions" :key="requester" :value="requester">{{ t(`payroll.people.pension_requests.requester.${requester}`) }}</option>
            </select>
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.received_on') }} <RequiredMark />
            <DateInput v-model="form.received_on" required :max="today" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-received" />
          </label>
          <label v-if="needsEmployment" class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.employment') }} <RequiredMark />
            <select v-model.number="form.employment_id" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-employment">
              <option :value="null" disabled>{{ t('payroll.people.pension_requests.fields.employment_choose') }}</option>
              <option v-for="option in employmentOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>
          <label v-if="needsYear" class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.period_year') }} <RequiredMark />
            <select v-model.number="form.period_year" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-year">
              <option v-for="year in yearOptions" :key="year" :value="year">{{ year }}</option>
            </select>
          </label>
          <label v-if="needsMonth" class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.period_month') }} <RequiredMark />
            <input v-model="form.period_month" type="month" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-month">
          </label>
          <label v-if="isSurvivor" class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.death_on') }} <RequiredMark />
            <DateInput v-model="form.death_on" :max="today" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-death" />
          </label>
          <label v-if="showsStatedDue" class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.stated_due_on') }} <RequiredMark v-if="statedDueRequired" />
            <DateInput v-model="form.stated_due_on" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-stated-due" />
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.pension_requests.fields.requester_reference') }}
            <input v-model="form.requester_reference" maxlength="190" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-reference">
          </label>
          <label class="text-xs text-neutral-600 sm:col-span-2">
            {{ t('payroll.people.pension_requests.fields.note') }}
            <input v-model="form.note" maxlength="500" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="pension-request-note">
          </label>
          <p class="text-xs text-neutral-500 sm:col-span-3" data-test="pension-request-hint">{{ t(`payroll.people.pension_requests.hint.${form.request_kind}`) }}</p>
          <ul v-if="missing.length" class="list-inside list-disc text-xs text-warning-800 sm:col-span-3" data-test="pension-request-missing">
            <li v-for="item in missing" :key="item">{{ item }}</li>
          </ul>
          <div class="flex flex-wrap justify-end gap-2 sm:col-span-3">
            <button type="submit" :class="[btnFilled('primary'), 'whitespace-nowrap']" :disabled="saving || missing.length > 0" data-test="pension-request-save">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ saving ? t('common.saving') : t('payroll.people.pension_requests.save') }}
            </button>
          </div>
        </form>
      </template>
    </div>
  </details>
</template>
