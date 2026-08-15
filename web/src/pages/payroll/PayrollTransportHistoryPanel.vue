<script setup lang="ts">
/**
 * Co jsme odeslali na ČSSZ a jak to dopadlo.
 *
 * Odesílací cesta i ledger pokusů existovaly dřív než tahle obrazovka, takže
 * odpověď na otázku „co jsem podal a v jakém je to stavu" žila jen v databázi.
 * Tady se čte a doptává; ODESÍLÁ se jinde — odeslání patří ke zmrazení podání,
 * ne k seznamu stavů, a tlačítko „odeslat" v přehledu by svádělo k druhému
 * podání za totéž období.
 *
 * Tři rozlišení, na kterých celá obrazovka stojí:
 *
 *  * `awaiting_protocol` NENÍ přijaté podání. ČSSZ potvrzuje převzetí hned
 *    a o výsledku rozhoduje až potom; kdo si to splete, přestane výsledek
 *    sledovat. Hotovo je teprve `completed`.
 *  * Neúspěšné pokusy jsou to hlavní, kvůli čemu se sem uživatel podívá —
 *    kód i hláška chyby jsou proto vidět rovnou, ne po rozkliknutí.
 *  * Ledger je přírůstkový. Několik pokusů k jednomu podání je doklad o tom,
 *    co se dělo, takže se seskupují a pořadí se zachovává.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzProtocolError,
  type PayrollJmhzTransportAttempt,
  type PayrollJmhzTransportEnvironment,
  type PayrollJmhzTransportPoll,
  type PayrollJmhzTransportStatus,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()

const ENVIRONMENTS: PayrollJmhzTransportEnvironment[] = ['production', 'test']

const environment = ref<PayrollJmhzTransportEnvironment>('production')
const loading = ref(false)
const attempts = ref<PayrollJmhzTransportAttempt[]>([])

/**
 * Chyba načtení se drží ve stavu a NIKDY se nepřevádí na prázdný seznam.
 * „Zatím nic neodesláno" u požadavku, který selhal, je horší než chybová
 * hláška: uživatel z něj usoudí, že podání neodešlo, a odešle ho podruhé.
 */
const loadError = ref('')
const actionError = ref('')
const success = ref('')

const pollingId = ref<number | null>(null)
const closingId = ref<number | null>(null)
const copiedId = ref<number | null>(null)

/** Výsledky doptání, klíčované ID pokusu — zůstávají do dalšího načtení. */
const polls = ref<Record<number, PayrollJmhzTransportPoll>>({})
/** Období podání dotažené k ID; nepovinné, selhání se jen projeví chybějícím údajem. */
const periods = ref<Record<number, { start: string; end: string }>>({})

const variableSymbol = ref('')
const variableSymbolTouched = ref(false)
/** Variabilní symboly z nastavení zaměstnavatele — kandidáti, ne jistota. */
const variableSymbolOptions = ref<Array<{ value: string; label: string }>>([])

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const busy = computed(() =>
  loading.value || pollingId.value !== null || closingId.value !== null,
)

const variableSymbolValid = computed(() =>
  /^[0-9]{1,10}$/.test(variableSymbol.value.trim()),
)

interface AttemptGroup {
  submissionId: number
  attempts: PayrollJmhzTransportAttempt[]
}

/**
 * Seskupení podle podání se zachovaným pořadím: první výskyt určí místo
 * skupiny, pokusy uvnitř zůstanou tak, jak přišly ze serveru (od nejnovějšího).
 */
const groups = computed<AttemptGroup[]>(() => {
  const byId = new Map<number, AttemptGroup>()
  const ordered: AttemptGroup[] = []
  for (const attempt of attempts.value) {
    let group = byId.get(attempt.submission_id)
    if (!group) {
      group = { submissionId: attempt.submission_id, attempts: [] }
      byId.set(attempt.submission_id, group)
      ordered.push(group)
    }
    group.attempts.push(attempt)
  }
  return ordered
})

const STATUS_TONES: Record<PayrollJmhzTransportStatus, string> = {
  prepared: 'bg-neutral-100 text-neutral-700',
  sent: 'bg-payroll-100 text-payroll-800',
  // Převzato, ale nerozhodnuto — proto výstražná, ne zelená.
  awaiting_protocol: 'bg-warning-100 text-warning-800',
  completed: 'bg-success-100 text-success-700',
  failed: 'bg-danger-100 text-danger-700',
  expired: 'bg-danger-100 text-danger-700',
}

function statusTone(status: PayrollJmhzTransportStatus): string {
  return STATUS_TONES[status] ?? 'bg-neutral-100 text-neutral-700'
}

/** Doptat se jde jen tam, kde brána přidělila CorrelationID. */
function canPoll(attempt: PayrollJmhzTransportAttempt): boolean {
  return (attempt.correlation_reference ?? '') !== ''
}

/**
 * Uzavřít se smí až po dotažení protokolu. Dřív by se výsledek ztratil, a to
 * je nevratné — proto se tlačítko u ostatních stavů vůbec nenabízí.
 */
function canClose(attempt: PayrollJmhzTransportAttempt): boolean {
  return attempt.status === 'completed' && canPoll(attempt)
}

function periodLabel(submissionId: number): string {
  const period = periods.value[submissionId]
  if (!period) return t('payroll.submissions.transport.group.period_unknown')
  return t('payroll.submissions.transport.group.period', {
    start: period.start,
    end: period.end,
  })
}

function errorLocation(error: PayrollJmhzProtocolError): string[] {
  const parts: string[] = []
  if (error.ik_mpsv) {
    parts.push(t('payroll.submissions.transport.report.ik_mpsv', { value: error.ik_mpsv }))
  }
  if (error.id_ppv) {
    parts.push(t('payroll.submissions.transport.report.id_ppv', { value: error.id_ppv }))
  }
  if (error.form_guid) {
    parts.push(t('payroll.submissions.transport.report.form_guid', { value: error.form_guid }))
  }
  return parts
}

async function copyCorrelation(attempt: PayrollJmhzTransportAttempt) {
  const value = attempt.correlation_reference
  if (!value) return
  try {
    await navigator.clipboard.writeText(value)
    copiedId.value = attempt.id
  } catch {
    // Schránka může být zakázaná politikou prohlížeče; text je vidět i tak.
    copiedId.value = null
  }
}

/**
 * Období podání je to, co uživatel hledá jako první („co jsem poslal za
 * červenec"). Ledger ho nenese, dotahuje se proto z detailu podání — a když
 * se nepovede, zůstane jen odkaz na podání. Chybějící období není důvod
 * neukázat stavy.
 */
async function loadPeriods(rows: PayrollJmhzTransportAttempt[]) {
  const ids = [...new Set(rows.map(row => row.submission_id))]
    .filter(id => periods.value[id] === undefined)
  if (ids.length === 0) return
  const results = await Promise.allSettled(
    ids.map(id => payrollApi.submissionDetail(id)),
  )
  const next = { ...periods.value }
  results.forEach((result, index) => {
    if (result.status !== 'fulfilled') return
    const submission = result.value?.submission
    if (!submission) return
    next[ids[index]!] = {
      start: submission.period_start,
      end: submission.period_end,
    }
  })
  periods.value = next
}

/** Nastavení zaměstnavatele zná variabilní symboly pracovišť — jinak se ptáme. */
async function loadVariableSymbols() {
  try {
    const settings = await payrollApi.employerSettings()
    const seen = new Map<string, string>()
    for (const office of settings.offices ?? []) {
      const symbol = (office.social_security_variable_symbol ?? '').trim()
      if (!office.is_active || symbol === '') continue
      if (!seen.has(symbol)) seen.set(symbol, `${office.code} — ${office.name}`)
    }
    variableSymbolOptions.value = [...seen].map(([value, label]) => ({ value, label }))
    // Předvyplní se jen jednoznačný případ. Víc různých symbolů znamená volbu,
    // a hádat ji za uživatele by znamenalo ptát se ČSSZ pod cizím symbolem.
    if (
      !variableSymbolTouched.value
      && variableSymbol.value === ''
      && variableSymbolOptions.value.length === 1
    ) {
      variableSymbol.value = variableSymbolOptions.value[0]!.value
    }
  } catch {
    // Nabídka je pohodlí, ne podmínka — pole na symbol zůstane k vyplnění ručně.
    variableSymbolOptions.value = []
  }
}

function useVariableSymbol(value: string) {
  variableSymbol.value = value
  variableSymbolTouched.value = true
}

async function load() {
  loading.value = true
  loadError.value = ''
  actionError.value = ''
  success.value = ''
  try {
    const history = await payrollApi.jmhzTransportHistory(environment.value)
    attempts.value = history.attempts ?? []
    await loadPeriods(attempts.value)
  } catch (exception: unknown) {
    // Stav zůstává NEZNÁMÝ, ne prázdný — šablona podle `loadError` skryje
    // prázdný stav i seznam, aby se selhání nedalo přečíst jako „nic neodešlo".
    attempts.value = []
    loadError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

async function switchEnvironment(next: PayrollJmhzTransportEnvironment) {
  if (next === environment.value || busy.value) return
  environment.value = next
  polls.value = {}
  await load()
}

function replaceAttempt(updated: PayrollJmhzTransportAttempt) {
  attempts.value = attempts.value.map(
    attempt => (attempt.id === updated.id ? updated : attempt),
  )
}

async function poll(attempt: PayrollJmhzTransportAttempt) {
  if (!variableSymbolValid.value || busy.value) return
  pollingId.value = attempt.id
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.pollJmhzTransportAttempt(
      attempt.id,
      variableSymbol.value.trim(),
      environment.value,
    )
    polls.value = { ...polls.value, [attempt.id]: result }
    if (result.attempt) replaceAttempt(result.attempt)
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.poll_failed'),
    )
  } finally {
    pollingId.value = null
  }
}

async function close(attempt: PayrollJmhzTransportAttempt) {
  if (!canWrite.value || !variableSymbolValid.value || busy.value) return
  closingId.value = attempt.id
  actionError.value = ''
  success.value = ''
  try {
    await payrollApi.closeJmhzTransportAttempt(
      attempt.id,
      variableSymbol.value.trim(),
      environment.value,
    )
    // Potvrzení až po znovunačtení: `load()` hlášky čistí, takže nastavené
    // dřív by zmizelo dřív, než by ho někdo stihl přečíst.
    await load()
    success.value = t('payroll.submissions.transport.closed', { id: attempt.id })
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.close_failed'),
    )
  } finally {
    closingId.value = null
  }
}

onMounted(load)
onMounted(loadVariableSymbols)
</script>

<template>
  <section class="space-y-4" data-test="payroll-transport-history">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.transport.title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.submissions.transport.description') }}
          </p>
        </div>
        <button
          type="button"
          data-test="transport-reload"
          :class="btnOutline('neutral')"
          :disabled="busy"
          @click="load"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>

      <div class="mt-5">
        <span class="mb-1 block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.transport.environment.label') }}
        </span>
        <div
          class="inline-flex flex-wrap gap-1 rounded-lg border border-neutral-200 bg-neutral-50 p-1"
          role="group"
          :aria-label="t('payroll.submissions.transport.environment.label')"
        >
          <button
            v-for="option in ENVIRONMENTS"
            :key="option"
            type="button"
            :data-test="`transport-environment-${option}`"
            :aria-pressed="environment === option"
            class="cursor-pointer whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
            :class="environment === option
              ? (option === 'production'
                ? 'bg-warning-500 text-white shadow-sm'
                : 'bg-payroll-600 text-white shadow-sm')
              : 'text-neutral-600 hover:text-neutral-900'"
            :disabled="busy"
            @click="switchEnvironment(option)"
          >
            {{ t(`payroll.submissions.transport.environment.${option}`) }}
          </button>
        </div>
        <p
          class="mt-3 rounded-lg border p-3 text-sm"
          :class="environment === 'production'
            ? 'border-warning-500/40 bg-warning-50 text-warning-800'
            : 'border-payroll-500/30 bg-payroll-50 text-neutral-700'"
          data-test="transport-environment-note"
        >
          {{ t(`payroll.submissions.transport.environment.${environment}_note`) }}
        </p>
      </div>

      <div class="mt-5 rounded-lg border border-neutral-200 p-4">
        <label class="block max-w-xs">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.submissions.transport.vs.label') }}
          </span>
          <input
            v-model="variableSymbol"
            data-test="transport-variable-symbol"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            maxlength="10"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 font-mono text-sm"
            @input="variableSymbolTouched = true"
          >
        </label>
        <p class="mt-2 max-w-3xl text-xs text-neutral-500">
          {{ t('payroll.submissions.transport.vs.hint') }}
        </p>
        <div
          v-if="variableSymbolOptions.length > 1"
          class="mt-3 flex flex-wrap items-center gap-2"
          data-test="transport-vs-options"
        >
          <span class="text-xs text-neutral-500">
            {{ t('payroll.submissions.transport.vs.pick') }}
          </span>
          <button
            v-for="option in variableSymbolOptions"
            :key="option.value"
            type="button"
            :class="btnOutlineSm('neutral')"
            @click="useVariableSymbol(option.value)"
          >
            {{ option.value }} — {{ option.label }}
          </button>
        </div>
        <p
          v-else-if="variableSymbolOptions.length === 0"
          class="mt-3 text-xs text-warning-700"
          data-test="transport-vs-missing"
        >
          {{ t('payroll.submissions.transport.vs.missing') }}
        </p>
        <p
          v-if="!variableSymbolValid"
          class="mt-3 text-xs text-neutral-600"
          data-test="transport-vs-required"
        >
          {{ t('payroll.submissions.transport.vs.required') }}
        </p>
      </div>
    </div>

    <div
      v-if="loadError"
      data-test="transport-load-error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
    >
      <p class="font-medium">{{ loadError }}</p>
      <p class="mt-1">{{ t('payroll.submissions.transport.state_unknown') }}</p>
    </div>

    <div
      v-else-if="loading"
      data-test="transport-loading"
      class="h-64 animate-pulse rounded-xl bg-neutral-100"
    />

    <template v-else>
      <p
        v-if="actionError"
        data-test="transport-error"
        class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
      >
        {{ actionError }}
      </p>
      <p
        v-if="success"
        data-test="transport-success"
        class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
        role="status"
      >
        {{ success }}
      </p>

      <div
        v-if="groups.length === 0"
        data-test="transport-empty"
        class="rounded-xl border border-dashed border-neutral-300 bg-surface p-6 text-sm text-neutral-600"
      >
        <p class="font-medium text-neutral-800">
          {{ t('payroll.submissions.transport.empty.title') }}
        </p>
        <p class="mt-1">{{ t('payroll.submissions.transport.empty.description') }}</p>
      </div>

      <template v-else>
        <section
          v-for="group in groups"
          :key="group.submissionId"
          :data-test="`transport-group-${group.submissionId}`"
          class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
        >
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 p-4 sm:p-6">
            <div>
              <h3 class="text-base font-semibold text-neutral-900">
                {{ periodLabel(group.submissionId) }}
              </h3>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('payroll.submissions.transport.group.submission', {
                  id: group.submissionId,
                }) }}
              </p>
            </div>
            <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-700">
              {{ t('payroll.submissions.transport.group.attempts', {
                total: group.attempts.length,
              }) }}
            </span>
          </div>

          <div class="divide-y divide-neutral-100">
            <article
              v-for="attempt in group.attempts"
              :key="attempt.id"
              :data-test="`transport-attempt-${attempt.id}`"
              class="p-4 sm:p-6"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                  <span
                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="statusTone(attempt.status)"
                    :data-test="`transport-status-${attempt.id}`"
                  >
                    {{ t(`payroll.submissions.transport.status.${attempt.status}`) }}
                  </span>
                  <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-700">
                    {{ t('payroll.submissions.transport.attempt_no', { no: attempt.attempt_no }) }}
                  </span>
                  <span class="text-xs text-neutral-500">
                    {{ attempt.sent_at
                      ? t('payroll.submissions.transport.sent_at', { at: attempt.sent_at })
                      : t('payroll.submissions.transport.not_sent_yet') }}
                  </span>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                  <button
                    v-if="canPoll(attempt)"
                    type="button"
                    :data-test="`transport-poll-${attempt.id}`"
                    :class="btnOutlineSm('primary')"
                    :disabled="busy || !variableSymbolValid"
                    @click="poll(attempt)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.cycle" />
                    </svg>
                    {{ pollingId === attempt.id
                      ? t('payroll.submissions.transport.polling')
                      : t('payroll.submissions.transport.poll') }}
                  </button>
                  <button
                    v-if="canWrite && canClose(attempt)"
                    type="button"
                    :data-test="`transport-close-${attempt.id}`"
                    :class="btnOutlineSm('neutral')"
                    :disabled="busy || !variableSymbolValid"
                    @click="close(attempt)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.archive" />
                    </svg>
                    {{ closingId === attempt.id
                      ? t('payroll.submissions.transport.closing')
                      : t('payroll.submissions.transport.close') }}
                  </button>
                </div>
              </div>

              <p
                v-if="attempt.status === 'awaiting_protocol'"
                class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
                :data-test="`transport-awaiting-note-${attempt.id}`"
              >
                {{ t('payroll.submissions.transport.awaiting_note') }}
              </p>
              <p
                v-else-if="attempt.status === 'completed'"
                class="mt-3 text-sm text-neutral-600"
                :data-test="`transport-close-note-${attempt.id}`"
              >
                {{ t('payroll.submissions.transport.close_note') }}
              </p>

              <div
                v-if="attempt.error_code || attempt.error_message"
                :data-test="`transport-failure-${attempt.id}`"
                class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
                role="alert"
              >
                <p v-if="attempt.error_code" class="font-mono text-xs font-semibold">
                  {{ attempt.error_code }}
                </p>
                <p v-if="attempt.error_message" class="mt-1">{{ attempt.error_message }}</p>
              </div>

              <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div class="sm:col-span-2">
                  <dt class="text-xs uppercase tracking-wide text-neutral-500">
                    {{ t('payroll.submissions.transport.correlation') }}
                  </dt>
                  <dd class="mt-0.5 flex flex-wrap items-center gap-2">
                    <span
                      class="break-all font-mono text-xs text-neutral-800"
                      :data-test="`transport-correlation-${attempt.id}`"
                    >
                      {{ attempt.correlation_reference
                        ?? t('payroll.submissions.transport.correlation_missing') }}
                    </span>
                    <button
                      v-if="attempt.correlation_reference"
                      type="button"
                      :data-test="`transport-copy-${attempt.id}`"
                      :class="btnOutlineSm('neutral')"
                      @click="copyCorrelation(attempt)"
                    >
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.copy" />
                      </svg>
                      {{ copiedId === attempt.id
                        ? t('payroll.submissions.transport.copied')
                        : t('payroll.submissions.transport.copy') }}
                    </button>
                  </dd>
                </div>
                <div>
                  <dt class="text-xs uppercase tracking-wide text-neutral-500">
                    {{ t('payroll.submissions.transport.http_status') }}
                  </dt>
                  <dd class="mt-0.5 text-neutral-800">
                    {{ attempt.response_http_status ?? '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs uppercase tracking-wide text-neutral-500">
                    {{ t('payroll.submissions.transport.completed_at') }}
                  </dt>
                  <dd class="mt-0.5 text-neutral-800">{{ attempt.completed_at ?? '—' }}</dd>
                </div>
              </dl>

              <div
                v-if="polls[attempt.id]"
                :data-test="`transport-poll-result-${attempt.id}`"
                class="mt-4 space-y-3"
              >
                <p
                  v-if="polls[attempt.id]!.acknowledgement"
                  :data-test="`transport-acknowledgement-${attempt.id}`"
                  class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
                >
                  {{ t('payroll.submissions.transport.acknowledged', {
                    seconds: polls[attempt.id]!.acknowledgement!.poll_interval_seconds ?? 0,
                  }) }}
                </p>

                <div
                  v-if="polls[attempt.id]!.report"
                  :data-test="`transport-report-${attempt.id}`"
                  class="rounded-lg border border-neutral-200 p-3"
                >
                  <p class="text-sm font-medium text-neutral-900">
                    {{ t(`payroll.submissions.transport.protocol_status.${polls[attempt.id]!.report!.status}`) }}
                  </p>
                  <p
                    v-if="polls[attempt.id]!.report!.errors.length === 0"
                    class="mt-2 text-sm text-neutral-600"
                  >
                    {{ t('payroll.submissions.transport.report.no_errors') }}
                  </p>
                  <ul v-else class="mt-3 space-y-3">
                    <li
                      v-for="(error, index) in polls[attempt.id]!.report!.errors"
                      :key="`${error.code}-${index}`"
                      :data-test="`transport-report-error-${attempt.id}-${index}`"
                      class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
                    >
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold">{{ error.code }}</span>
                        <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700">
                          {{ t(`payroll.submissions.transport.origin.${error.origin}`) }}
                        </span>
                      </div>
                      <p class="mt-1 font-medium">{{ error.message }}</p>
                      <template v-if="error.control">
                        <p class="mt-2 text-neutral-800">{{ error.control.name }}</p>
                        <p v-if="error.control.detail" class="mt-1 text-xs text-neutral-600">
                          {{ error.control.detail }}
                        </p>
                        <p v-if="error.control.area" class="mt-1 text-xs text-neutral-600">
                          {{ t('payroll.submissions.transport.report.area', {
                            area: error.control.area,
                          }) }}
                        </p>
                        <div
                          v-if="error.control.attribute_ids.length"
                          class="mt-2 flex flex-wrap items-center gap-1"
                          :data-test="`transport-report-attributes-${attempt.id}-${index}`"
                        >
                          <span class="text-xs text-neutral-600">
                            {{ t('payroll.submissions.transport.report.attributes') }}
                          </span>
                          <span
                            v-for="attributeId in error.control.attribute_ids"
                            :key="attributeId"
                            class="rounded-full bg-neutral-100 px-2 py-0.5 font-mono text-xs text-neutral-700"
                          >
                            {{ attributeId }}
                          </span>
                        </div>
                      </template>
                      <p
                        v-else
                        class="mt-2 text-xs text-neutral-600"
                        :data-test="`transport-report-uncatalogued-${attempt.id}-${index}`"
                      >
                        {{ t('payroll.submissions.transport.report.control_unknown') }}
                      </p>
                      <p v-if="errorLocation(error).length" class="mt-2 text-xs text-neutral-600">
                        {{ errorLocation(error).join(' · ') }}
                      </p>
                    </li>
                  </ul>
                </div>

                <p
                  v-else-if="!polls[attempt.id]!.acknowledgement"
                  class="rounded-lg border border-neutral-200 p-3 text-sm text-neutral-600"
                  :data-test="`transport-poll-inconclusive-${attempt.id}`"
                >
                  {{ t('payroll.submissions.transport.poll_inconclusive') }}
                </p>
              </div>
            </article>
          </div>
        </section>
      </template>
    </template>
  </section>
</template>
