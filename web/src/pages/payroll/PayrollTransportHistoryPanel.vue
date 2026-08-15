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
 *
 * Čtvrté rozlišení přibylo s načítáním protokolů: NE VŠECHNO, co firma podala,
 * odešlo naší cestou. Kdo přechází od jiného softwaru, má podání u ČSSZ a
 * protokol v datové schránce, ale ledger prázdný — a prázdná obrazovka se čte
 * jako „nic neodešlo". Načtené protokoly proto stojí v témž chronologickém
 * přehledu jako naše pokusy, ale VŽDY označené zdrojem: u načteného protokolu
 * aplikace nezná datovou větu, nemůže se doptat na stav ani uzavřít transakci,
 * a tvářit se, že ano, by bylo horší než ho neukázat.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzImportedProtocol,
  type PayrollJmhzProtocolError,
  type PayrollJmhzTransportAttempt,
  type PayrollJmhzTransportEnvironment,
  type PayrollJmhzTransportPoll,
  type PayrollJmhzTransportStatus,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()

const ENVIRONMENTS: PayrollJmhzTransportEnvironment[] = ['production', 'test']

const environment = ref<PayrollJmhzTransportEnvironment>('production')
const loading = ref(false)
const attempts = ref<PayrollJmhzTransportAttempt[]>([])
const imported = ref<PayrollJmhzImportedProtocol[]>([])
const importing = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

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
/** Podání, u kterého uživatel právě potvrzuje storno. Storno je nevratné. */
const cancellingId = ref<number | null>(null)
const cancelPendingId = ref<number | null>(null)

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
  loading.value
  || importing.value
  || pollingId.value !== null
  || closingId.value !== null
  || cancelPendingId.value !== null,
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

type TimelineEntry =
  | { source: 'app'; key: string; sortKey: string; group: AttemptGroup }
  | { source: 'imported'; key: string; sortKey: string; protocol: PayrollJmhzImportedProtocol }

/**
 * Jeden chronologický přehled „co jsem podal", ať to odešlo odsud nebo odjinud.
 *
 * Řadí se podle OBDOBÍ hlášení, ne podle času založení řádku: uživatel hledá
 * „červenec", ne „to, co jsem načetl naposled". Období, které se nepodařilo
 * zjistit, jde na konec — ne nahoru, kde by vytlačilo to, co je vidět jasně.
 */
const timeline = computed<TimelineEntry[]>(() => {
  const entries: TimelineEntry[] = groups.value.map(group => {
    const period = periods.value[group.submissionId]
    return {
      source: 'app' as const,
      key: `app-${group.submissionId}`,
      sortKey: period?.start ?? '',
      group,
    }
  })
  for (const protocol of imported.value) {
    entries.push({
      source: 'imported' as const,
      key: `imported-${protocol.id}`,
      sortKey: protocol.period_year && protocol.period_month
        ? `${protocol.period_year}-${String(protocol.period_month).padStart(2, '0')}-01`
        : '',
      protocol,
    })
  }
  return entries.sort((a, b) => {
    if (a.sortKey === b.sortKey) return a.key < b.key ? 1 : -1
    if (a.sortKey === '') return 1
    if (b.sortKey === '') return -1
    return a.sortKey < b.sortKey ? 1 : -1
  })
})

function importedPeriodLabel(protocol: PayrollJmhzImportedProtocol): string {
  if (!protocol.period_year || !protocol.period_month) {
    return t('payroll.submissions.transport.imported.period_unknown')
  }
  return t('payroll.submissions.transport.imported.period', {
    month: protocol.period_month,
    year: protocol.period_year,
  })
}

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

/**
 * Barva stavu z protokolu. „Částečně přijato" a „obsahuje propustné chyby"
 * jsou výstražné, ne zelené: hlášení sice prošlo, ale něco v něm zůstalo
 * nedořešené a zelená by to zavřela jako hotové.
 */
const PROTOCOL_TONES: Record<string, string> = {
  ProcessedAndComplete: 'bg-success-100 text-success-700',
  ContainsPassableErrors: 'bg-warning-100 text-warning-800',
  PartiallyAccepted: 'bg-warning-100 text-warning-800',
  Processing: 'bg-payroll-100 text-payroll-800',
  Rejected: 'bg-danger-100 text-danger-700',
  NotAccepted: 'bg-danger-100 text-danger-700',
}

function protocolTone(status: string): string {
  return PROTOCOL_TONES[status] ?? 'bg-neutral-100 text-neutral-700'
}

/** Doptat se jde jen tam, kde brána přidělila CorrelationID. */
function canPoll(attempt: PayrollJmhzTransportAttempt): boolean {
  return (attempt.correlation_reference ?? '') !== ''
}

/**
 * Uzavřít se smí až po dotažení protokolu. Dřív by se výsledek ztratil, a to
 * je nevratné — proto se tlačítko u ostatních stavů vůbec nenabízí.
 *
 * Uzavřenou transakci nabízet znovu nemá smysl: automatika ji uzavírá sama a
 * druhé uzavření by u ČSSZ byl dotaz na transakci, která už neexistuje.
 */
function canClose(attempt: PayrollJmhzTransportAttempt): boolean {
  return attempt.status === 'completed' && canPoll(attempt) && !attempt.closed_at
}

/**
 * Stornovat lze jen hlášení, které DOLOŽITELNĚ odešlo. Podání, které nikdy
 * neopustilo aplikaci, u ČSSZ neexistuje a rušit se u něj nemá co.
 */
function canCancel(group: AttemptGroup): boolean {
  return canWrite.value && group.attempts.some(attempt => attempt.sent_at !== null)
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
    // Obě strany přehledu se načítají naráz a SELHÁNÍ KTERÉKOLI Z NICH je
    // selhání celku. Ukázat jen jednu polovinu a druhou tiše vynechat by
    // znamenalo přehled, který zamlčuje podání — a přesně kvůli tomu se sem
    // uživatel dívá.
    const [history, protocols] = await Promise.all([
      payrollApi.jmhzTransportHistory(environment.value),
      payrollApi.jmhzImportedProtocols(environment.value),
    ])
    attempts.value = history.attempts ?? []
    imported.value = protocols.protocols ?? []
    await loadPeriods(attempts.value)
  } catch (exception: unknown) {
    // Stav zůstává NEZNÁMÝ, ne prázdný — šablona podle `loadError` skryje
    // prázdný stav i seznam, aby se selhání nedalo přečíst jako „nic neodešlo".
    attempts.value = []
    imported.value = []
    loadError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

function pickProtocolFile() {
  if (busy.value || !canWrite.value) return
  fileInput.value?.click()
}

/**
 * Načtení protokolu z datové schránky.
 *
 * Vstup se po každém pokusu čistí, aby šel tentýž soubor načíst znovu — po
 * neúspěchu je druhý pokus s týmž souborem to první, co člověk zkusí.
 */
async function importProtocol(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || importing.value) return
  importing.value = true
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.importJmhzProtocol(file, environment.value)
    await load()
    success.value = result.created
      ? t('payroll.submissions.transport.imported.added', {
        status: t(`payroll.submissions.transport.protocol_status.${result.protocol.status_name}`),
      })
      : t('payroll.submissions.transport.imported.replaced')
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.imported.failed'),
    )
  } finally {
    importing.value = false
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

function askToCancel(submissionId: number) {
  if (busy.value) return
  cancellingId.value = submissionId
  actionError.value = ''
  success.value = ''
}

/**
 * Storno se jen PŘIPRAVÍ. Odesílá se pak stejnou cestou jako řádné hlášení —
 * sloučit obojí do jednoho kliknutí by znamenalo, že se při chybě odeslání
 * nedá poznat, jestli storno vzniklo, a druhý pokus by ho založil znovu.
 */
async function confirmCancel(submissionId: number) {
  if (!canWrite.value || busy.value) return
  cancelPendingId.value = submissionId
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.cancelJmhzSubmission(submissionId, environment.value)
    cancellingId.value = null
    await load()
    success.value = result.created
      ? t('payroll.submissions.transport.storno.frozen', { id: result.submission_id })
      : t('payroll.submissions.transport.storno.already', { id: result.submission_id })
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.storno.failed'),
    )
  } finally {
    cancelPendingId.value = null
  }
}

async function close(attempt: PayrollJmhzTransportAttempt) {
  if (!canWrite.value || !variableSymbolValid.value || busy.value) return
  closingId.value = attempt.id
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.closeJmhzTransportAttempt(
      attempt.id,
      variableSymbol.value.trim(),
      environment.value,
    )
    // Potvrzení až po znovunačtení: `load()` hlášky čistí, takže nastavené
    // dřív by zmizelo dřív, než by ho někdo stihl přečíst.
    await load()
    success.value = result.already_closed
      ? t('payroll.submissions.transport.closed_already')
      : t('payroll.submissions.transport.closed', { id: attempt.id })
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
        <div class="flex flex-wrap justify-end gap-2">
          <button
            v-if="canWrite"
            type="button"
            data-test="transport-import-protocol"
            :class="btnOutline('primary')"
            :disabled="busy"
            @click="pickProtocolFile"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.upload" />
            </svg>
            {{ importing
              ? t('payroll.submissions.transport.imported.importing')
              : t('payroll.submissions.transport.imported.action') }}
          </button>
          <input
            ref="fileInput"
            data-test="transport-import-input"
            type="file"
            accept=".xml,text/xml,application/xml"
            class="hidden"
            @change="importProtocol"
          >
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
      </div>
      <p v-if="canWrite" class="mt-3 max-w-3xl text-sm text-neutral-600" data-test="transport-import-hint">
        {{ t('payroll.submissions.transport.imported.hint') }}
      </p>

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
        v-if="timeline.length === 0"
        data-test="transport-empty"
        class="rounded-xl border border-dashed border-neutral-300 bg-surface p-6 text-sm text-neutral-600"
      >
        <p class="font-medium text-neutral-800">
          {{ t('payroll.submissions.transport.empty.title') }}
        </p>
        <p class="mt-1">{{ t('payroll.submissions.transport.empty.description') }}</p>
        <p class="mt-2">{{ t('payroll.submissions.transport.empty.import_hint') }}</p>
      </div>

      <template v-else>
        <template v-for="entry in timeline" :key="entry.key">
        <section
          v-if="entry.source === 'app'"
          :data-test="`transport-group-${entry.group.submissionId}`"
          class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
        >
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 p-4 sm:p-6">
            <div>
              <h3 class="text-base font-semibold text-neutral-900">
                {{ periodLabel(entry.group.submissionId) }}
              </h3>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('payroll.submissions.transport.group.submission', {
                  id: entry.group.submissionId,
                }) }}
              </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <span
                class="rounded-full bg-payroll-100 px-2.5 py-1 text-xs font-medium text-payroll-800"
                :data-test="`transport-source-app-${entry.group.submissionId}`"
              >
                {{ t('payroll.submissions.transport.source.app') }}
              </span>
              <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-700">
                {{ t('payroll.submissions.transport.group.attempts', {
                  total: entry.group.attempts.length,
                }) }}
              </span>
              <button
                v-if="canCancel(entry.group) && cancellingId !== entry.group.submissionId"
                type="button"
                :data-test="`transport-cancel-${entry.group.submissionId}`"
                :class="btnOutlineSm('danger')"
                :disabled="busy"
                @click="askToCancel(entry.group.submissionId)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.x" />
                </svg>
                {{ t('payroll.submissions.transport.storno.action') }}
              </button>
            </div>
          </div>

          <!-- Storno ruší u ČSSZ všechna hlášení za období a je nevratné,
               takže se nespouští jedním kliknutím. -->
          <div
            v-if="cancellingId === entry.group.submissionId"
            :data-test="`transport-cancel-confirm-${entry.group.submissionId}`"
            class="border-b border-danger-500/30 bg-danger-50 p-4 sm:p-6"
            role="alert"
          >
            <p class="text-sm font-semibold text-danger-700">
              {{ t('payroll.submissions.transport.storno.confirm_title', {
                period: periodLabel(entry.group.submissionId),
              }) }}
            </p>
            <p class="mt-1 text-sm text-danger-700">
              {{ t('payroll.submissions.transport.storno.confirm_text') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
              <button
                type="button"
                :data-test="`transport-cancel-submit-${entry.group.submissionId}`"
                :class="btnFilled('danger')"
                :disabled="busy"
                @click="confirmCancel(entry.group.submissionId)"
              >
                {{ t('payroll.submissions.transport.storno.confirm') }}
              </button>
              <button
                type="button"
                :data-test="`transport-cancel-abort-${entry.group.submissionId}`"
                :class="btnOutline('neutral')"
                :disabled="busy"
                @click="cancellingId = null"
              >
                {{ t('payroll.submissions.transport.storno.cancel') }}
              </button>
            </div>
          </div>

          <div class="divide-y divide-neutral-100">
            <article
              v-for="attempt in entry.group.attempts"
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
                v-else-if="attempt.status === 'completed' && !attempt.closed_at"
                class="mt-3 text-sm text-neutral-600"
                :data-test="`transport-close-note-${attempt.id}`"
              >
                {{ t('payroll.submissions.transport.close_note') }}
              </p>
              <p
                v-else-if="attempt.status === 'expired'"
                class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
                :data-test="`transport-expired-note-${attempt.id}`"
                role="alert"
              >
                {{ t('payroll.submissions.transport.automation.expired_note') }}
              </p>

              <!-- Co dělá automatika. Bez tohohle by uživatel nevěděl, jestli
                   se aplikace ptá sama, nebo jestli na něj podání čeká. -->
              <div
                v-if="attempt.status === 'awaiting_protocol' || attempt.status === 'completed'"
                :data-test="`transport-automation-${attempt.id}`"
                class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-700"
              >
                <p class="font-medium text-neutral-900">
                  {{ t('payroll.submissions.transport.automation.title') }}
                </p>
                <p class="mt-1">
                  {{ t('payroll.submissions.transport.automation.description') }}
                </p>
                <ul class="mt-2 space-y-1 text-xs text-neutral-600">
                  <li v-if="attempt.status === 'awaiting_protocol'">
                    {{ attempt.next_retry_at
                      ? t('payroll.submissions.transport.automation.next_poll', {
                        at: attempt.next_retry_at,
                      })
                      : t('payroll.submissions.transport.automation.next_poll_unknown') }}
                  </li>
                  <li>
                    {{ t('payroll.submissions.transport.automation.polls', {
                      count: attempt.poll_count,
                    }) }}
                    <template v-if="attempt.last_polled_at">
                      {{ t('payroll.submissions.transport.automation.last_polled', {
                        at: attempt.last_polled_at,
                      }) }}
                    </template>
                  </li>
                  <li v-if="attempt.closed_at" :data-test="`transport-closed-${attempt.id}`">
                    {{ t('payroll.submissions.transport.automation.closed', {
                      at: attempt.closed_at,
                    }) }}
                  </li>
                  <li v-else-if="attempt.status === 'completed'">
                    {{ t('payroll.submissions.transport.automation.close_pending') }}
                  </li>
                </ul>
                <p
                  v-if="attempt.last_poll_error"
                  class="mt-2 text-xs text-warning-700"
                  :data-test="`transport-poll-error-${attempt.id}`"
                >
                  {{ t('payroll.submissions.transport.automation.last_error', {
                    message: attempt.last_poll_error,
                  }) }}
                </p>
                <p
                  v-if="attempt.close_error && !attempt.closed_at"
                  class="mt-2 text-xs text-warning-700"
                  :data-test="`transport-close-error-${attempt.id}`"
                >
                  {{ t('payroll.submissions.transport.automation.close_error', {
                    message: attempt.close_error,
                  }) }}
                </p>
              </div>

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

        <section
          v-else
          :data-test="`transport-imported-${entry.protocol.id}`"
          class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
        >
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 p-4 sm:p-6">
            <div>
              <h3 class="text-base font-semibold text-neutral-900">
                {{ importedPeriodLabel(entry.protocol) }}
              </h3>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t(`payroll.submissions.transport.imported.kind.${entry.protocol.protocol_kind}`) }}
                <template v-if="entry.protocol.source_filename">
                  · {{ entry.protocol.source_filename }}
                </template>
              </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <!-- Zdroj je vidět vždy: u načteného protokolu aplikace nezná
                   datovou větu, takže se nedá doptat na stav ani uzavřít
                   transakci — a tvářit se opačně by bylo horší než mlčet. -->
              <span
                class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-700"
                :data-test="`transport-source-imported-${entry.protocol.id}`"
              >
                {{ t('payroll.submissions.transport.source.imported') }}
              </span>
              <span
                class="rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="protocolTone(entry.protocol.status_name)"
                :data-test="`transport-imported-status-${entry.protocol.id}`"
              >
                {{ t(`payroll.submissions.transport.protocol_status.${entry.protocol.status_name}`) }}
              </span>
            </div>
          </div>

          <div class="p-4 sm:p-6">
            <p class="text-sm text-neutral-600" :data-test="`transport-imported-note-${entry.protocol.id}`">
              {{ t('payroll.submissions.transport.imported.note') }}
            </p>

            <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
              <div class="sm:col-span-2">
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.guid') }}
                </dt>
                <dd
                  class="mt-0.5 break-all font-mono text-xs text-neutral-800"
                  :data-test="`transport-imported-guid-${entry.protocol.id}`"
                >
                  {{ entry.protocol.submission_guid
                    ?? t('payroll.submissions.transport.imported.guid_missing') }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.correlation') }}
                </dt>
                <dd class="mt-0.5 break-all font-mono text-xs text-neutral-800">
                  {{ entry.protocol.correlation_reference
                    ?? t('payroll.submissions.transport.correlation_missing') }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.status_code') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">{{ entry.protocol.status_code }}</dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.protocol_dated_at') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">
                  {{ entry.protocol.protocol_dated_at ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.submitted_at') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">
                  {{ entry.protocol.submitted_at ?? '—' }}
                </dd>
              </div>
            </dl>

            <p
              v-if="entry.protocol.detail_available === false"
              class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
              :data-test="`transport-imported-detail-missing-${entry.protocol.id}`"
            >
              {{ t('payroll.submissions.transport.imported.detail_unavailable', {
                total: entry.protocol.error_count,
              }) }}
            </p>

            <p
              v-else-if="(entry.protocol.errors ?? []).length === 0"
              class="mt-3 text-sm text-neutral-600"
              :data-test="`transport-imported-clean-${entry.protocol.id}`"
            >
              {{ t('payroll.submissions.transport.report.no_errors') }}
            </p>

            <ul v-else class="mt-3 space-y-3">
              <li
                v-for="(error, index) in entry.protocol.errors ?? []"
                :key="`${error.code}-${index}`"
                :data-test="`transport-imported-error-${entry.protocol.id}-${index}`"
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
                <p v-else class="mt-2 text-xs text-neutral-600">
                  {{ t('payroll.submissions.transport.report.control_unknown') }}
                </p>
                <p v-if="errorLocation(error).length" class="mt-2 text-xs text-neutral-600">
                  {{ errorLocation(error).join(' · ') }}
                </p>
              </li>
            </ul>
          </div>
        </section>
        </template>
      </template>
    </template>
  </section>
</template>
