<script setup lang="ts">
/**
 * Žádosti o potvrzení o zdanitelných příjmech (§ 38j odst. 3 ZDP).
 *
 * Zaměstnanec o potvrzení může požádat kdykoli, nejen při skončení vztahu, a
 * plátce ho vystaví do deseti dnů. Žádost zapsaná tady dostane termín
 * v hlídači termínů; vyřídí se sama vystavením potvrzení na stránce Dokumenty,
 * nebo ji účetní označí jako vyřízenou (potvrzení předané mimo aplikaci).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { payrollApi, type PayrollTaxableIncomeRequest } from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'
import RequiredMark from '@/components/ui/RequiredMark.vue'

const props = defineProps<{
  personId: number
  canWrite: boolean
  employmentId?: number | null
}>()

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const saveError = ref('')
const requests = ref<PayrollTaxableIncomeRequest[]>([])
const today = appIsoDate()
const form = ref({
  requested_on: today,
  income_year: Number(today.slice(0, 4)) - 1,
  note: '',
})

const openRequests = computed(() => requests.value.filter(request => request.status === 'open'))
const yearOptions = computed(() => {
  const requestedYear = Number((form.value.requested_on || today).slice(0, 4))
  return [requestedYear, requestedYear - 1, requestedYear - 2, requestedYear - 3]
})

onMounted(load)
watch(() => props.personId, load)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    requests.value = await payrollApi.taxableIncomeRequests(props.personId)
  } catch (error) {
    loadError.value = apiErrorMessage(error, t('payroll.people.taxable_income_requests.load_failed'))
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (!props.canWrite || saving.value) return
  saving.value = true
  saveError.value = ''
  try {
    requests.value = await payrollApi.createTaxableIncomeRequest(props.personId, {
      requested_on: form.value.requested_on,
      income_year: Number(form.value.income_year),
      employment_id: props.employmentId ?? null,
      note: form.value.note.trim() === '' ? null : form.value.note.trim(),
    })
    form.value.note = ''
    toast.success(t('payroll.people.taxable_income_requests.saved'))
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.people.taxable_income_requests.save_failed'))
  } finally {
    saving.value = false
  }
}

async function complete(request: PayrollTaxableIncomeRequest): Promise<void> {
  if (!props.canWrite || saving.value) return
  saving.value = true
  saveError.value = ''
  try {
    requests.value = await payrollApi.completeTaxableIncomeRequest(props.personId, request.id, appIsoDate())
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.people.taxable_income_requests.save_failed'))
  } finally {
    saving.value = false
  }
}

async function remove(request: PayrollTaxableIncomeRequest): Promise<void> {
  if (!props.canWrite || saving.value) return
  saving.value = true
  saveError.value = ''
  try {
    requests.value = await payrollApi.deleteTaxableIncomeRequest(props.personId, request.id)
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.people.taxable_income_requests.save_failed'))
  } finally {
    saving.value = false
  }
}

function statusClass(status: PayrollTaxableIncomeRequest['status']): string {
  if (status === 'open') return 'bg-warning-50 text-warning-700'
  return 'bg-success-50 text-success-700'
}
</script>

<template>
  <details class="group rounded-lg border border-payroll-500/30 bg-surface" data-test="taxable-income-requests">
    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
      <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-neutral-900">{{ t('payroll.people.taxable_income_requests.title') }}</span>
        <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll.people.taxable_income_requests.subtitle') }}</span>
      </span>
      <span v-if="openRequests.length" class="shrink-0 rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800" data-test="taxable-income-requests-open-count">
        {{ t('payroll.people.taxable_income_requests.open_count', { count: openRequests.length }, openRequests.length) }}
      </span>
    </summary>

    <div class="space-y-3 border-t border-neutral-200 p-3">
      <div v-if="loading" class="h-16 animate-pulse rounded-md bg-neutral-100" />
      <p v-else-if="loadError" class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700" role="alert">{{ loadError }}</p>
      <template v-else>
        <p v-if="requests.length === 0" class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600" data-test="taxable-income-requests-empty">
          {{ t('payroll.people.taxable_income_requests.empty') }}
        </p>
        <div v-else class="space-y-2" data-test="taxable-income-requests-list">
          <article v-for="request in requests" :key="request.id" class="rounded-md border border-neutral-200 p-2 text-xs" :data-test="`taxable-income-request-${request.id}`">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-medium text-neutral-900">
                {{ t('payroll.people.taxable_income_requests.row', { year: request.income_year, requested: request.requested_on, due: request.due_on }) }}
              </span>
              <span :class="`rounded-full px-2 py-0.5 font-medium ${statusClass(request.status)}`">
                {{ t(`payroll.people.taxable_income_requests.status.${request.status}`) }}
              </span>
            </div>
            <p class="mt-1 text-neutral-500">{{ request.deadline_source }}</p>
            <p v-if="request.note" class="mt-1 text-neutral-600">{{ request.note }}</p>
            <div v-if="canWrite" class="mt-2 flex flex-wrap items-center gap-2">
              <RouterLink
                v-if="request.status === 'open'"
                :to="{ name: 'payroll-documents', query: { person: String(personId) } }"
                :class="[btnOutline('primary'), '!px-2 !py-1 !text-xs whitespace-nowrap']"
                data-test="taxable-income-request-issue"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
                {{ t('payroll.people.taxable_income_requests.issue') }}
              </RouterLink>
              <button
                v-if="request.status === 'open'"
                type="button"
                :class="[btnOutline('success'), '!px-2 !py-1 !text-xs whitespace-nowrap']"
                :disabled="saving"
                data-test="taxable-income-request-complete"
                @click="complete(request)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                {{ t('payroll.people.taxable_income_requests.complete') }}
              </button>
              <button
                type="button"
                :class="[btnOutline('danger'), '!px-2 !py-1 !text-xs whitespace-nowrap']"
                :disabled="saving"
                data-test="taxable-income-request-delete"
                @click="remove(request)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                {{ t('common.delete') }}
              </button>
            </div>
          </article>
        </div>

        <form v-if="canWrite" class="grid grid-cols-1 gap-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3 sm:grid-cols-3" data-test="taxable-income-request-form" @submit.prevent="save">
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.taxable_income_requests.requested_on') }} <RequiredMark />
            <DateInput v-model="form.requested_on" required :max="today" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="taxable-income-request-date" />
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.taxable_income_requests.income_year') }} <RequiredMark />
            <select v-model.number="form.income_year" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="taxable-income-request-year">
              <option v-for="year in yearOptions" :key="year" :value="year">{{ year }}</option>
            </select>
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.taxable_income_requests.note') }}
            <input v-model="form.note" maxlength="500" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="taxable-income-request-note">
          </label>
          <p class="text-xs text-neutral-500 sm:col-span-3">{{ t('payroll.people.taxable_income_requests.hint') }}</p>
          <p v-if="saveError" class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700 sm:col-span-3" role="alert" data-test="taxable-income-request-error">{{ saveError }}</p>
          <div class="flex flex-wrap justify-end gap-2 sm:col-span-3">
            <button type="submit" :class="[btnFilled('primary'), 'whitespace-nowrap']" :disabled="saving" data-test="taxable-income-request-save">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ saving ? t('common.saving') : t('payroll.people.taxable_income_requests.save') }}
            </button>
          </div>
        </form>
      </template>
    </div>
  </details>
</template>
