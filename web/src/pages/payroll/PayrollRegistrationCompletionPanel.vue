<script setup lang="ts">
/*
 * Dohlášení údajů zaměstnanců přihlášených dřív přes ONZ (REGZEC akce 3).
 *
 * ONZ nevedla postavení v zaměstnání, režim, místo výkonu, profesi, pozici,
 * vzdělání ani stát rezidence. Zákon je ukládá doplnit akcí A3 — a dokud
 * ČSSZ dohlášení nepřijme, aplikace nemá čím doložit ručně zapsané OIČ
 * a ID PPV, takže neprojde ani odhláška A2. Panel ukáže, u koho dohlášení
 * chybí, co mu brání (profil A1) a vybraným ho hromadně připraví. Odesílá
 * se jako vždy až ve frontě podání.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollRegistrationCompletionCandidate,
  type PayrollRegistrationCompletionResult,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})

const loading = ref(true)
const busy = ref(false)
const items = ref<PayrollRegistrationCompletionCandidate[]>([])
const today = ref('')
const selected = ref<number[]>([])
const mode = ref<'full' | 'minimal'>('full')
const effectiveOn = ref('')
const results = ref<Record<number, PayrollRegistrationCompletionResult['results'][number]>>({})
const error = ref('')
const success = ref('')

const canWrite = computed(() => auth.canWrite('payroll.submissions'))

/** Podání, které už běží nebo prošlo — znovu se nenabízí. */
const IN_FLIGHT = ['ready', 'submitted', 'processing', 'accepted', 'partially_accepted']

function isDone(item: PayrollRegistrationCompletionCandidate): boolean {
  return item.completion_submission_status !== null
    && IN_FLIGHT.includes(item.completion_submission_status)
}

function isSelectable(item: PayrollRegistrationCompletionCandidate): boolean {
  return item.profile_status === 'verified' && !isDone(item)
}

/**
 * Dohlášení vyřídil předchozí program (registrace v převzaté historii, nebo
 * lhůta uplynula před začátkem vedení mezd v MyÚčtu). Vztah nic nepotřebuje,
 * ruční dohlášení ale zůstává možné.
 */
function handledByPredecessor(item: PayrollRegistrationCompletionCandidate): boolean {
  return !!item.predecessor_reason && !isDone(item)
}

function needsAction(item: PayrollRegistrationCompletionCandidate): boolean {
  return !isDone(item) && !item.predecessor_reason
}

const showAll = ref(false)
const pendingCount = computed(() => items.value.filter(needsAction).length)
const predecessorCount = computed(() => items.value.filter(handledByPredecessor).length)
const visibleItems = computed(() => showAll.value ? items.value : items.value.filter(needsAction))

function predecessorTarget(item: PayrollRegistrationCompletionCandidate) {
  return {
    name: 'payroll-submissions-tab',
    params: { tab: 'jmhz' },
    query: item.predecessor_submission_id ? { external: String(item.predecessor_submission_id) } : {},
    hash: '#external-submissions',
  }
}

const selectable = computed(() => visibleItems.value.filter(isSelectable))
const allSelected = computed(
  () => selectable.value.length > 0
    && selectable.value.every(item => selected.value.includes(item.employment_id)),
)
const canSubmit = computed(
  () => canWrite.value && !busy.value && selected.value.length > 0 && effectiveOn.value !== '',
)

function registrationTarget(item: PayrollRegistrationCompletionCandidate) {
  return {
    name: 'payroll-people',
    query: {
      person: String(item.employee_id),
      employment: String(item.employment_id),
      panel: 'registration',
    },
  }
}

function profileState(item: PayrollRegistrationCompletionCandidate): 'verified' | 'draft' | 'missing' {
  return item.profile_status ?? 'missing'
}

function profileBadge(item: PayrollRegistrationCompletionCandidate): string {
  const state = profileState(item)
  if (state !== 'verified' && handledByPredecessor(item)) return 'bg-neutral-100 text-neutral-600 ring-neutral-300'
  if (state === 'verified') return 'bg-success-50 text-success-700 ring-success-500/30'
  if (state === 'draft') return 'bg-warning-50 text-warning-800 ring-warning-500/30'
  return 'bg-danger-50 text-danger-700 ring-danger-500/30'
}

const KNOWN_STATES = [
  'none', 'approved', 'ready', 'submitted', 'processing', 'accepted',
  'partially_accepted', 'rejected',
]

function completionState(item: PayrollRegistrationCompletionCandidate): string {
  if (item.completion_submission_status === null) {
    return item.completion_event_id === null ? 'none' : 'approved'
  }
  return KNOWN_STATES.includes(item.completion_submission_status)
    ? item.completion_submission_status
    : 'other'
}

function toggle(id: number): void {
  selected.value = selected.value.includes(id)
    ? selected.value.filter(value => value !== id)
    : [...selected.value, id]
}

function toggleAll(): void {
  selected.value = allSelected.value
    ? []
    : selectable.value.map(item => item.employment_id)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const data = await payrollApi.registrationCompletionCandidates(environment.value)
    items.value = data.items
    today.value = data.today
    if (effectiveOn.value === '') effectiveOn.value = data.today
    selected.value = selected.value.filter(id =>
      data.items.some(item => item.employment_id === id && isSelectable(item)))
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.registrationCompletion.load_failed'))
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    const response = await payrollApi.completeRegistrationProfiles({
      environment: environment.value,
      employment_ids: [...selected.value],
      completion: mode.value,
      effective_on: effectiveOn.value,
    })
    const byEmployment: typeof results.value = {}
    for (const result of response.results) byEmployment[result.employment_id] = result
    results.value = byEmployment
    const prepared = response.results.filter(result => result.status === 'prepared').length
    const failed = response.results.length - prepared
    success.value = t('payroll.registrationCompletion.done', { prepared, failed })
    selected.value = []
    await load()
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.registrationCompletion.submit_failed'))
  } finally {
    busy.value = false
  }
}

watch(environment, () => {
  results.value = {}
  selected.value = []
  void load()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="space-y-4" data-test="registration-completion-panel">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-700">
      <h3 class="text-base font-semibold text-neutral-900">
        {{ t('payroll.registrationCompletion.title') }}
      </h3>
      <p class="mt-1 max-w-prose">
        {{ t('payroll.registrationCompletion.intro') }}
      </p>
      <p class="mt-2 max-w-prose text-xs text-neutral-500">
        {{ t('payroll.registrationCompletion.legal') }}
      </p>
    </div>

    <div
      v-if="error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="registration-completion-error"
    >
      {{ error }}
    </div>
    <div
      v-if="success"
      class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
      role="status"
      data-test="registration-completion-success"
    >
      {{ success }}
      <RouterLink
        :to="{ name: 'payroll-submissions-tab', params: { tab: 'queue' } }"
        class="ml-1 font-medium underline underline-offset-2"
        data-test="registration-completion-queue-link"
      >
        {{ t('payroll.registrationCompletion.open_queue') }}
      </RouterLink>
    </div>

    <div class="rounded-xl border border-neutral-200 bg-surface p-4">
      <div class="flex flex-wrap items-end gap-3">
        <label class="text-xs font-medium text-neutral-700">
          {{ t('payroll.registrationCompletion.mode') }}
          <select
            v-model="mode"
            class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:w-auto"
            data-test="registration-completion-mode"
          >
            <option value="full">{{ t('payroll.people.registration.completion.scope_full') }}</option>
            <option value="minimal">{{ t('payroll.people.registration.completion.scope_minimal') }}</option>
          </select>
        </label>
        <label class="text-xs font-medium text-neutral-700">
          {{ t('payroll.registrationCompletion.effective_on') }}
          <DateInput
            v-model="effectiveOn"
            class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:w-44"
            data-test="registration-completion-effective-on"
          />
        </label>
        <button
          type="button"
          :class="btnFilled('primary')"
          class="whitespace-nowrap"
          :disabled="!canSubmit"
          data-test="registration-completion-submit"
          @click="submit"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ busy
            ? t('common.loading')
            : t('payroll.registrationCompletion.submit', { count: selected.length }) }}
        </button>
        <button
          type="button"
          :class="btnOutline('neutral')"
          class="whitespace-nowrap"
          :disabled="loading || busy"
          data-test="registration-completion-reload"
          @click="load"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.registrationCompletion.reload') }}
        </button>
      </div>
      <p class="mt-2 max-w-prose text-xs text-neutral-500">
        {{ t('payroll.registrationCompletion.effective_hint') }}
      </p>
    </div>

    <div
      v-if="!loading && items.length > 0"
      class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-neutral-200 bg-surface px-4 py-3 text-sm text-neutral-700"
      data-test="registration-completion-filter"
    >
      <p>
        {{ t('payroll.registrationCompletion.filter.summary', { pending: pendingCount, total: items.length }) }}
        <template v-if="predecessorCount > 0">
          · {{ t('payroll.registrationCompletion.filter.predecessor', { count: predecessorCount }) }}
        </template>
      </p>
      <label class="inline-flex items-center gap-2 whitespace-nowrap text-sm">
        <input v-model="showAll" type="checkbox" data-test="registration-completion-show-all">
        {{ t('payroll.registrationCompletion.filter.show_all') }}
      </label>
    </div>

    <div v-if="loading" class="h-40 animate-pulse rounded-xl bg-neutral-100" />
    <div
      v-else-if="items.length === 0"
      class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-600"
      data-test="registration-completion-empty"
    >
      {{ t('payroll.registrationCompletion.empty') }}
    </div>
    <div
      v-else-if="visibleItems.length === 0"
      class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
      data-test="registration-completion-nothing-pending"
    >
      {{ t('payroll.registrationCompletion.filter.nothing_pending') }}
    </div>
    <div v-else class="overflow-x-auto rounded-xl border border-neutral-200 bg-surface">
      <table class="min-w-full divide-y divide-neutral-200 text-sm" data-test="registration-completion-table">
        <thead class="bg-neutral-50 text-left text-xs font-medium text-neutral-600">
          <tr>
            <th class="px-3 py-2">
              <input
                type="checkbox"
                :checked="allSelected"
                :disabled="selectable.length === 0 || !canWrite"
                :aria-label="t('payroll.registrationCompletion.select_all')"
                data-test="registration-completion-select-all"
                @change="toggleAll"
              >
            </th>
            <th class="px-3 py-2">{{ t('payroll.registrationCompletion.col.person') }}</th>
            <th class="px-3 py-2">{{ t('payroll.registrationCompletion.col.period') }}</th>
            <th class="px-3 py-2">{{ t('payroll.registrationCompletion.col.profile') }}</th>
            <th class="px-3 py-2">{{ t('payroll.registrationCompletion.col.completion') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr
            v-for="item in visibleItems"
            :key="item.employment_id"
            :data-test="`registration-completion-row-${item.employment_id}`"
          >
            <td class="px-3 py-2 align-top">
              <input
                type="checkbox"
                :checked="selected.includes(item.employment_id)"
                :disabled="!isSelectable(item) || !canWrite"
                :aria-label="t('payroll.registrationCompletion.select_one', { name: item.employee_name })"
                :data-test="`registration-completion-select-${item.employment_id}`"
                @change="toggle(item.employment_id)"
              >
            </td>
            <td class="px-3 py-2 align-top">
              <p class="font-medium text-neutral-900">{{ item.employee_name }}</p>
              <p class="text-xs text-neutral-500">{{ item.code }}</p>
            </td>
            <td class="whitespace-nowrap px-3 py-2 align-top text-xs text-neutral-700">
              {{ item.start_date ? formatDate(item.start_date) : '?' }}
              –
              {{ item.end_date ? formatDate(item.end_date) : t('payroll.registrationCompletion.open_ended') }}
            </td>
            <td class="px-3 py-2 align-top">
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                :class="profileBadge(item)"
                :data-test="`registration-completion-profile-${item.employment_id}`"
              >
                {{ t(`payroll.registrationCompletion.profile.${profileState(item)}`) }}
              </span>
              <p
                v-if="profileState(item) !== 'verified' && !handledByPredecessor(item)"
                class="mt-1 max-w-xs text-xs text-neutral-600"
              >
                {{ t(`payroll.registrationCompletion.profile_hint.${profileState(item)}`) }}
              </p>
              <RouterLink
                :to="registrationTarget(item)"
                :class="btnOutline(profileState(item) === 'verified' || handledByPredecessor(item) ? 'neutral' : 'warning')"
                class="mt-1 whitespace-nowrap"
                :data-test="`registration-completion-open-${item.employment_id}`"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.eye" />
                </svg>
                {{ t('payroll.registrationCompletion.open_profile') }}
              </RouterLink>
            </td>
            <td class="px-3 py-2 align-top text-xs">
              <div
                v-if="handledByPredecessor(item)"
                class="max-w-md"
                :data-test="`registration-completion-predecessor-${item.employment_id}`"
              >
                <span class="inline-flex rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 ring-1 ring-success-500/30">
                  {{ t('payroll.registrationCompletion.predecessor.badge') }}
                </span>
                <p v-if="item.predecessor_reason === 'registration'" class="mt-1 text-neutral-600">
                  {{ t('payroll.registrationCompletion.predecessor.registration', {
                    action: item.predecessor_action ?? '',
                    program: item.predecessor_program ?? t('payroll.registrationCompletion.predecessor.program'),
                    date: item.predecessor_submitted_at ? formatDate(item.predecessor_submitted_at) : '—',
                  }) }}
                  <RouterLink
                    :to="predecessorTarget(item)"
                    class="ml-1 font-medium text-payroll-600 underline underline-offset-2 hover:text-payroll-700"
                    :data-test="`registration-completion-predecessor-link-${item.employment_id}`"
                  >
                    {{ t('payroll.registrationCompletion.predecessor.open') }}
                  </RouterLink>
                </p>
                <p v-else class="mt-1 text-neutral-600">
                  {{ t('payroll.registrationCompletion.predecessor.deadline') }}
                </p>
              </div>
              <p v-else class="text-neutral-700">
                {{ t(`payroll.registrationCompletion.state.${completionState(item)}`) }}
                <template v-if="item.completion_effective_on">
                  · {{ formatDate(item.completion_effective_on) }}
                </template>
              </p>
              <p
                v-if="results[item.employment_id]?.status === 'failed'"
                class="mt-1 max-w-md text-danger-700"
                :data-test="`registration-completion-failure-${item.employment_id}`"
              >
                {{ results[item.employment_id]?.message }}
              </p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
