<script setup lang="ts">
/**
 * Systém → Datová schránka.
 *
 * Průřezový kanál podání: DPH, kontrolní i souhrnné hlášení, DPPO, přehledy
 * zdravotním pojišťovnám. Ne mzdová odbočka.
 *
 * ── Dvě věci, které musí být na první pohled vidět ──────────────────────────
 * 1. **„Doručeno" není „zpracováno".** Datovka vrací doručenku, tedy důkaz
 *    o doručení. Stav podání proto ukazujeme dvěma odznaky vedle sebe —
 *    dopravu a vyřízení — a nikdy je neslučujeme do jednoho.
 * 2. **Vybírání schránky je právní úkon.** Vyzvednutí zprávy ji doručí
 *    (§ 17 odst. 3 zák. 300/2008 Sb.) a rozjede lhůty, takže se zapíná
 *    vědomě, s vysvětlením, a ne přepínačem bez kontextu.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  dataBoxApi,
  type AcceptanceState,
  type DataBoxCredential,
  type DispatchState,
  type InboxMessage,
  type InboxPollState,
  type OutboxAttempt,
  type OutboxSubmission,
  type ReceiptCandidate,
  type ReceiptUploadResult,
  type RecipientKind,
  type SubmissionRecipient,
} from '@/api/dataBox'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()

type Tab = 'access' | 'outbox' | 'inbox' | 'recipients'
const tab = ref<Tab>('access')
const environment = ref<'production' | 'test'>('production')
const loading = ref(true)
const saving = ref(false)
const busyId = ref<number | null>(null)

const credentials = ref<DataBoxCredential[]>([])
const recipients = ref<SubmissionRecipient[]>([])
const outbox = ref<OutboxSubmission[]>([])
const inbox = ref<InboxMessage[]>([])
const pollState = ref<InboxPollState | null>(null)
const attempts = ref<Record<number, OutboxAttempt[]>>({})
const expanded = ref<number | null>(null)

// ── Ruční cesta: člověk odešle, člověk přinese doručenku ─────────────────────
// Strojový transport do ISDS nasazený není, takže tohle není nouzový režim,
// ale běžný provoz. UI proto nesmí jen konstatovat stav — musí říct, co udělat.
const unmatchedReceipts = ref<InboxMessage[]>([])
const receiptCandidates = ref<Record<number, ReceiptCandidate[]>>({})
const lastUpload = ref<ReceiptUploadResult | null>(null)
const receiptInput = ref<HTMLInputElement | null>(null)
const uploadTargetId = ref<number | null>(null)
const markSentFor = ref<number | null>(null)
const markSentMessageId = ref('')

// ── Přístup: pouze systémový certifikát ──────────────────────────────────────
// Přihlašovací jméno a heslo tu vědomě nejsou: přístupové údaje ke schránce
// nesmí opustit zařízení uživatele (§ 9 odst. 2 zák. 300/2008 Sb.).
const certLabel = ref('')
const certBoxId = ref('')
const certPassword = ref('')
const certFile = ref<File | null>(null)
const certFileInput = ref<HTMLInputElement | null>(null)
const pollingAck = ref(false)

const currentCredential = computed(
  () => credentials.value.find(c => c.environment === environment.value) ?? null,
)

// ── Recipient form ───────────────────────────────────────────────────────────
const recipientCode = ref('')
const recipientName = ref('')
const recipientKind = ref<RecipientKind>('tax_office')
const recipientBoxId = ref('')
const recipientSource = ref('')

const recipientsWithoutBox = computed(() => recipients.value.filter(r => !r.has_box_id))

/**
 * Doprava a vyřízení jako dva NEZÁVISLÉ odznaky.
 *
 * Kdyby to byl jeden štítek, „doručeno" by nutně splynulo se „zpracováno" —
 * a přesně na téhle záměně projekt už jednou doplatil.
 */
function dispatchTone(state: DispatchState): string {
  switch (state) {
    case 'ready': return 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200'
    case 'sending': return 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200'
    case 'send_uncertain': return 'bg-warning-50 text-warning-700 dark:bg-warning-900/30 dark:text-warning-200'
    case 'sent': return 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200'
    case 'delivered': return 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-200'
    case 'failed': return 'bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-200'
    default: return 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'
  }
}

function acceptanceTone(state: AcceptanceState): string {
  switch (state) {
    case 'accepted': return 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-200'
    case 'rejected': return 'bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-200'
    // `unknown` je u datovky legitimní KONCOVÝ stav, ne mezistupeň — proto
    // neutrální šeď, ne varovná barva. Není to porucha, je to fakt.
    default: return 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'
  }
}

/**
 * Právě jedna plná primární akce podle stavu — zbytek outline.
 *
 * U datovky je pro připravené podání primární akcí „označit jako odesláno",
 * ne „odeslat datovkou": strojový transport nasazený není, takže zprávu
 * doopravdy odesílá člověk ve své schránce. Nabízet jako hlavní krok tlačítko,
 * které dnes vždycky skončí překážkou, by uživatele posílalo do zdi.
 */
function primaryAction(row: OutboxSubmission): 'confirm' | 'resolve' | 'markSent' | 'uploadReceipt' | null {
  if (row.dispatch_state === 'send_uncertain' || row.dispatch_state === 'sending') return 'resolve'
  if (row.dispatch_state === 'ready') return row.channel === 'isds' ? 'markSent' : 'confirm'
  if (row.dispatch_state === 'sent' && row.channel === 'isds' && !row.receipt_document_id) return 'uploadReceipt'
  return null
}

/** Ukazuje se u připraveného ISDS podání: konkrétní postup, ne obecná nápověda. */
function needsManualSteps(row: OutboxSubmission): boolean {
  return row.channel === 'isds' && row.dispatch_state === 'ready'
}

async function loadAll() {
  loading.value = true
  try {
    const [creds, recips, out, inb, unmatched] = await Promise.all([
      dataBoxApi.credentials(),
      dataBoxApi.recipients(),
      dataBoxApi.outbox(environment.value),
      dataBoxApi.inbox(environment.value),
      // Nespárovaná doručenka nesmí zmizet z očí — načítá se vždycky, ne až
      // na vyžádání.
      dataBoxApi.unmatchedReceipts(environment.value).catch(() => [] as InboxMessage[]),
    ])
    credentials.value = creds
    recipients.value = recips
    outbox.value = out
    inbox.value = inb.items
    pollState.value = inb.state
    unmatchedReceipts.value = unmatched
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

// ── Ruční odeslání ───────────────────────────────────────────────────────────

function startMarkSent(row: OutboxSubmission) {
  markSentFor.value = markSentFor.value === row.id ? null : row.id
  markSentMessageId.value = ''
}

async function submitMarkSent(row: OutboxSubmission) {
  const messageId = markSentMessageId.value.trim()
  if (messageId === '') {
    toast.error(t('databox.outbox.messageIdRequired'))
    return
  }
  busyId.value = row.id
  try {
    const result = await dataBoxApi.markSentManually(row.id, messageId)
    if (result.validation?.status === 'failed') {
      toast.error(t('databox.outbox.validationFailed'))
    } else {
      toast.success(t('databox.outbox.markedSent'))
    }
    markSentFor.value = null
    markSentMessageId.value = ''
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

// ── Doručenka ────────────────────────────────────────────────────────────────

/** `null` = doručenka bez určeného podání; párování hledá aplikace. */
function openReceiptPicker(outboxId: number | null) {
  uploadTargetId.value = outboxId
  receiptInput.value?.click()
}

async function onReceiptChosen(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  input.value = ''
  if (!file) return

  const target = uploadTargetId.value
  saving.value = true
  lastUpload.value = null
  try {
    const result = target !== null
      ? await dataBoxApi.uploadReceiptFor(target, environment.value, file)
      : await dataBoxApi.uploadReceipt(environment.value, file)
    lastUpload.value = result
    if (result.status === 'matched') {
      toast.success(result.message)
    } else {
      // Nespárováno není chyba uživatele ani selhání — je to stav, ve kterém
      // se čeká na jeho rozhodnutí. Proto informace, ne červená hláška.
      toast.success(result.message)
    }
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
    uploadTargetId.value = null
  }
}

async function showCandidates(message: InboxMessage) {
  try {
    receiptCandidates.value = {
      ...receiptCandidates.value,
      [message.id]: await dataBoxApi.receiptCandidates(message.id),
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function assignReceipt(inboxMessageId: number, outboxId: number) {
  busyId.value = outboxId
  try {
    const result = await dataBoxApi.matchReceipt(inboxMessageId, outboxId)
    toast.success(result.message)
    lastUpload.value = null
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function saveCredential() {
  if (!certFile.value) {
    toast.error(t('databox.errors.certificateRequired'))
    return
  }
  saving.value = true
  try {
    const form = new FormData()
    form.append('environment', environment.value)
    form.append('label', certLabel.value)
    form.append('box_id', certBoxId.value)
    form.append('certificate', certFile.value)
    form.append('certificate_password', certPassword.value)
    await dataBoxApi.saveCredential(form)
    certPassword.value = ''
    certFile.value = null
    if (certFileInput.value) certFileInput.value.value = ''
    toast.success(t('databox.saved'))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function togglePolling(enabled: boolean) {
  if (enabled && !pollingAck.value) {
    toast.error(t('databox.polling.ackRequired'))
    return
  }
  saving.value = true
  try {
    await dataBoxApi.setPolling(environment.value, enabled, true)
    toast.success(enabled ? t('databox.polling.enabled') : t('databox.polling.disabled'))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function confirmSend(row: OutboxSubmission) {
  busyId.value = row.id
  try {
    const result = await dataBoxApi.confirm(row.id, environment.value)
    if (result.dispatched) {
      toast.success(t('databox.outbox.sent'))
    } else if (result.row.dispatch_state === 'send_uncertain') {
      toast.error(t('databox.outbox.uncertain'))
    } else {
      toast.error(result.row.last_error_message ?? t('databox.outbox.notSent'))
    }
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function resolveUncertain(row: OutboxSubmission) {
  busyId.value = row.id
  try {
    const updated = await dataBoxApi.resolve(row.id, environment.value)
    if (updated.dispatch_state === 'sent') {
      toast.success(t('databox.outbox.resolvedSent'))
    } else if (updated.dispatch_state === 'failed') {
      toast.success(t('databox.outbox.resolvedNotSent'))
    } else {
      toast.error(t('databox.outbox.resolveInconclusive'))
    }
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function cancelSubmission(row: OutboxSubmission) {
  busyId.value = row.id
  try {
    await dataBoxApi.cancel(row.id)
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function toggleAttempts(row: OutboxSubmission) {
  if (expanded.value === row.id) {
    expanded.value = null
    return
  }
  expanded.value = row.id
  if (!attempts.value[row.id]) {
    try {
      attempts.value[row.id] = await dataBoxApi.attempts(row.id)
    } catch (e) {
      toast.error(apiErrorMessage(e))
    }
  }
}

async function pollInbox() {
  saving.value = true
  try {
    const result = await dataBoxApi.pollInbox(environment.value)
    toast.success(t('databox.inbox.polled', { count: result.stored }))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function saveRecipient() {
  if (recipientBoxId.value.trim() !== '' && recipientSource.value.trim() === '') {
    toast.error(t('databox.errors.sourceRequired'))
    return
  }
  saving.value = true
  try {
    await dataBoxApi.saveRecipient({
      code: recipientCode.value,
      name: recipientName.value,
      kind: recipientKind.value,
      isds_box_id: recipientBoxId.value.trim() || null,
      source_url: recipientSource.value.trim() || null,
      is_active: true,
    })
    recipientCode.value = ''
    recipientName.value = ''
    recipientBoxId.value = ''
    recipientSource.value = ''
    toast.success(t('databox.saved'))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function deleteRecipient(row: SubmissionRecipient) {
  busyId.value = row.id
  try {
    await dataBoxApi.deleteRecipient(row.id)
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

onMounted(loadAll)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold">{{ t('databox.title') }}</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ t('databox.subtitle') }}</p>
      </div>
      <select v-model="environment" class="form-select" @change="loadAll">
        <option value="production">{{ t('databox.env.production') }}</option>
        <option value="test">{{ t('databox.env.test') }}</option>
      </select>
    </header>

    <nav class="flex flex-wrap gap-2 border-b border-neutral-200 dark:border-neutral-700">
      <button
        v-for="key in (['access', 'outbox', 'inbox', 'recipients'] as Tab[])"
        :key="key"
        type="button"
        class="cursor-pointer whitespace-nowrap px-3 py-2 text-sm font-medium border-b-2 -mb-px"
        :class="tab === key
          ? 'border-primary-600 text-primary-700 dark:text-primary-300'
          : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        @click="tab = key"
      >
        {{ t(`databox.tabs.${key}`) }}
      </button>
    </nav>

    <!-- ─────────────── Přístup ─────────────── -->
    <section v-if="tab === 'access'" class="space-y-5">
      <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <h2 class="mb-1 font-medium">{{ t('databox.access.title') }}</h2>
        <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">{{ t('databox.access.certificateOnly') }}</p>

        <div v-if="currentCredential" class="mb-4 rounded-md bg-neutral-50 p-3 text-sm dark:bg-neutral-800">
          <div class="font-medium">{{ currentCredential.label }}</div>
          <div class="text-neutral-500 dark:text-neutral-400">
            {{ t('databox.access.boxId') }}: <code>{{ currentCredential.box_id }}</code>
          </div>
          <div v-if="currentCredential.certificate_valid_to" class="text-neutral-500 dark:text-neutral-400">
            {{ t('databox.access.validTo') }}: {{ currentCredential.certificate_valid_to }}
          </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.access.label') }}</span>
            <input v-model="certLabel" type="text" class="form-input mt-1 w-full" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.access.boxId') }}</span>
            <input v-model="certBoxId" type="text" maxlength="7" class="form-input mt-1 w-full" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.access.certificate') }}</span>
            <input
              ref="certFileInput"
              type="file"
              accept=".pfx,.p12"
              class="form-input mt-1 w-full"
              @change="certFile = ($event.target as HTMLInputElement).files?.[0] ?? null"
            />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.access.certificatePassword') }}</span>
            <input v-model="certPassword" type="password" autocomplete="new-password" class="form-input mt-1 w-full" />
          </label>
        </div>
      </div>

      <!-- Vybírání schránky: samostatný, vědomý souhlas -->
      <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-900/20">
        <h2 class="mb-1 font-medium">{{ t('databox.polling.title') }}</h2>
        <p class="mb-3 text-sm">{{ t('databox.polling.explanation') }}</p>

        <div v-if="currentCredential?.inbox_polling_enabled" class="flex flex-wrap items-center gap-3">
          <span class="text-sm font-medium">{{ t('databox.polling.isOn') }}</span>
          <button type="button" :class="btnOutline('warning')" :disabled="saving" @click="togglePolling(false)">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" />
            </svg>
            {{ t('databox.polling.turnOff') }}
          </button>
        </div>
        <div v-else class="space-y-3">
          <label class="flex items-start gap-2 text-sm">
            <input v-model="pollingAck" type="checkbox" class="mt-1" />
            <span>{{ t('databox.polling.acknowledge') }}</span>
          </label>
          <button
            type="button"
            :class="btnOutline('warning')"
            :disabled="saving || !pollingAck || !currentCredential"
            @click="togglePolling(true)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" />
            </svg>
            {{ t('databox.polling.turnOn') }}
          </button>
        </div>
      </div>

      <!-- Jedno společné Uložit pro celou sekci -->
      <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-white/95 py-3 dark:border-neutral-700 dark:bg-neutral-900/95">
        <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="saveCredential">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
          </svg>
          {{ t('common.save') }}
        </button>
      </div>
    </section>

    <!-- ─────────────── Odchozí ─────────────── -->
    <section v-else-if="tab === 'outbox'" class="space-y-3">
      <!-- Jediný skrytý file input pro celou sekci; cíl určuje uploadTargetId. -->
      <input ref="receiptInput" type="file" accept=".zfo" class="hidden" @change="onReceiptChosen" />

      <!-- Výsledek posledního nahrání, které se nespárovalo samo.
           Prázdno by tady bylo nejhorší možná odpověď: uživatel soubor nahrál
           a musí vidět, co s ním je a co má udělat dál. -->
      <div
        v-if="lastUpload && lastUpload.status !== 'matched'"
        class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-700 dark:bg-warning-900/20"
      >
        <div class="font-medium">{{ lastUpload.message }}</div>
        <div class="mt-1 text-xs text-neutral-600 dark:text-neutral-300">
          {{ t('databox.receipts.messageId') }}: <code>{{ lastUpload.receipt.message_id }}</code>
          <span v-if="lastUpload.receipt.delivered_at">
            · {{ t('databox.receipts.deliveredAt') }}: {{ lastUpload.receipt.delivered_at }}
          </span>
        </div>
        <div v-if="lastUpload.candidates.length" class="mt-3 space-y-2">
          <div class="text-xs font-medium uppercase text-neutral-500">{{ t('databox.receipts.candidatesHint') }}</div>
          <div
            v-for="c in lastUpload.candidates"
            :key="c.id"
            class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-white p-2 dark:bg-neutral-900"
          >
            <div class="min-w-0">
              <div class="font-medium">{{ c.subject }}</div>
              <div class="text-xs text-neutral-500 dark:text-neutral-400">
                {{ c.agenda_code }} · <code>{{ c.correlation_reference }}</code> · {{ c.created_at }}
              </div>
              <div v-if="c.reasons.length" class="text-xs text-neutral-500 dark:text-neutral-400">
                {{ c.reasons.map(r => t(`databox.receipts.reasons.${r}`)).join(' · ') }}
              </div>
            </div>
            <button
              type="button"
              :class="btnOutlineSm('primary')"
              :disabled="busyId === c.id"
              @click="assignReceipt(lastUpload.inbox_message_id, c.id)"
            >
              {{ t('databox.receipts.assign') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Nespárované doručenky. Nezmizely, jen čekají na člověka. -->
      <div
        v-if="unmatchedReceipts.length"
        class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
      >
        <h2 class="font-medium">{{ t('databox.receipts.title', { count: unmatchedReceipts.length }) }}</h2>
        <p class="mb-3 text-sm text-neutral-500 dark:text-neutral-400">{{ t('databox.receipts.intro') }}</p>
        <div v-for="m in unmatchedReceipts" :key="m.id" class="border-t border-neutral-100 py-2 dark:border-neutral-800">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
              <div class="text-sm font-medium">{{ m.subject ?? t('databox.receipts.noSubject') }}</div>
              <div class="text-xs text-neutral-500 dark:text-neutral-400">
                {{ t('databox.receipts.messageId') }}: <code>{{ m.external_message_id }}</code>
                <span v-if="m.delivered_at"> · {{ m.delivered_at }}</span>
              </div>
            </div>
            <button type="button" :class="btnOutlineSm('primary')" @click="showCandidates(m)">
              {{ t('databox.receipts.showCandidates') }}
            </button>
          </div>
          <div v-if="receiptCandidates[m.id]" class="mt-2 space-y-2">
            <p v-if="!receiptCandidates[m.id].length" class="text-sm text-neutral-500 dark:text-neutral-400">
              {{ t('databox.receipts.noCandidates') }}
            </p>
            <div
              v-for="c in receiptCandidates[m.id]"
              :key="c.id"
              class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-neutral-50 p-2 dark:bg-neutral-800"
            >
              <div class="min-w-0 text-sm">
                <div class="font-medium">{{ c.subject }}</div>
                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                  {{ c.agenda_code }} · <code>{{ c.correlation_reference }}</code>
                  <span v-if="c.reasons.length">
                    · {{ c.reasons.map(r => t(`databox.receipts.reasons.${r}`)).join(' · ') }}
                  </span>
                </div>
              </div>
              <button
                type="button"
                :class="btnOutlineSm('primary')"
                :disabled="busyId === c.id"
                @click="assignReceipt(m.id, c.id)"
              >
                {{ t('databox.receipts.assign') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="openReceiptPicker(null)">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" />
          </svg>
          {{ t('databox.receipts.uploadAny') }}
        </button>
      </div>

      <EmptyState v-if="!loading && outbox.length === 0" icon="send" :title="t('databox.outbox.empty')" />

      <div
        v-for="row in outbox"
        :key="row.id"
        class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-medium">{{ row.subject }}</div>
            <div class="text-sm text-neutral-500 dark:text-neutral-400">
              {{ row.agenda_code }} · {{ row.artifact_filename }}
              <span v-if="row.recipient_box_id"> · <code>{{ row.recipient_box_id }}</code></span>
            </div>
            <div class="mt-1 text-xs text-neutral-400">
              {{ t('databox.outbox.reference') }}: <code>{{ row.correlation_reference }}</code>
            </div>
          </div>

          <!-- DVĚ osy vedle sebe. Nikdy jeden sloučený štítek. -->
          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="dispatchTone(row.dispatch_state)">
              {{ t(`databox.dispatch.${row.dispatch_state}`) }}
            </span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="acceptanceTone(row.acceptance_state)">
              {{ t(`databox.acceptance.${row.acceptance_state}`) }}
            </span>
          </div>
        </div>

        <!-- Věta, podle které se dá jednat -->
        <p
          v-if="row.dispatch_state === 'delivered' && row.acceptance_state === 'unknown'"
          class="mt-3 rounded-md bg-neutral-50 p-2 text-sm text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
        >
          {{ t('databox.outbox.deliveredNotProcessed') }}
        </p>
        <p
          v-else-if="row.dispatch_state === 'send_uncertain' || row.dispatch_state === 'sending'"
          class="mt-3 rounded-md bg-warning-50 p-2 text-sm text-warning-800 dark:bg-warning-900/20 dark:text-warning-200"
        >
          {{ t('databox.outbox.uncertainHint') }}
        </p>
        <p
          v-else-if="row.last_error_message"
          class="mt-3 rounded-md bg-danger-50 p-2 text-sm text-danger-700 dark:bg-danger-900/20 dark:text-danger-200"
        >
          {{ row.last_error_message }}
        </p>

        <!-- ── Co udělat teď ──
             Ne nápověda někde stranou: konkrétní postup u konkrétního podání,
             včetně čísla jednacího, díky kterému se doručenka spáruje sama. -->
        <div
          v-if="needsManualSteps(row)"
          class="mt-3 rounded-md border border-primary-200 bg-primary-50 p-3 text-sm dark:border-primary-800 dark:bg-primary-900/20"
        >
          <div class="font-medium">{{ t('databox.manual.title') }}</div>
          <ol class="mt-2 list-decimal space-y-1 pl-5">
            <li>{{ t('databox.manual.step1', { file: row.artifact_filename }) }}</li>
            <li>
              {{ t('databox.manual.step2', { box: row.recipient_box_id ?? '—' }) }}
              <code class="rounded bg-white px-1 dark:bg-neutral-900">{{ row.correlation_reference }}</code>
            </li>
            <li>{{ t('databox.manual.step3') }}</li>
            <li>{{ t('databox.manual.step4') }}</li>
          </ol>
          <p class="mt-2 text-xs text-neutral-600 dark:text-neutral-300">{{ t('databox.manual.why') }}</p>
        </div>

        <!-- Doručenka jako důkaz — a hned vedle poctivé „podpis neověřujeme". -->
        <p
          v-if="row.receipt_document_id"
          class="mt-3 rounded-md bg-neutral-50 p-2 text-sm text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
        >
          {{ t('databox.outbox.receiptAttached', { at: row.receipt_attached_at ?? '' }) }}
          <span v-if="row.receipt_matched_by">
            ({{ t(`databox.receipts.matchedBy.${row.receipt_matched_by}`) }})
          </span>
          — {{ t('databox.outbox.receiptUnverified') }}
        </p>
        <p v-else-if="row.dispatch_mode === 'manual'" class="mt-3 text-sm text-neutral-500 dark:text-neutral-400">
          {{ t('databox.outbox.manualDispatch') }}
        </p>

        <p
          v-if="row.artifact_validation_status === 'failed'"
          class="mt-3 rounded-md bg-danger-50 p-2 text-sm text-danger-700 dark:bg-danger-900/20 dark:text-danger-200"
        >
          {{ t('databox.outbox.validationFailed') }}
        </p>

        <!-- „Odeslal jsem to" — ID zprávy je přesný identifikátor, ne formalita. -->
        <div
          v-if="markSentFor === row.id"
          class="mt-3 rounded-md border border-neutral-200 p-3 dark:border-neutral-700"
        >
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.outbox.messageIdLabel') }}</span>
            <input v-model="markSentMessageId" type="text" maxlength="64" class="form-input mt-1 w-full sm:w-72" />
            <span class="mt-1 block text-xs text-neutral-500 dark:text-neutral-400">
              {{ t('databox.outbox.messageIdHint') }}
            </span>
          </label>
          <div class="mt-3 flex flex-wrap gap-2">
            <button
              type="button"
              :class="btnFilled('primary')"
              :disabled="busyId === row.id"
              @click="submitMarkSent(row)"
            >
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
              </svg>
              {{ t('databox.outbox.markSentConfirm') }}
            </button>
            <button type="button" :class="btnOutline('neutral')" @click="markSentFor = null">
              {{ t('common.cancel') }}
            </button>
          </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
          <button
            v-if="primaryAction(row) === 'markSent' && markSentFor !== row.id"
            type="button"
            :class="btnFilled('primary')"
            :disabled="busyId === row.id"
            @click="startMarkSent(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
            </svg>
            {{ t('databox.outbox.markSent') }}
          </button>
          <button
            v-if="primaryAction(row) === 'uploadReceipt'"
            type="button"
            :class="btnFilled('success')"
            :disabled="saving"
            @click="openReceiptPicker(row.id)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" />
            </svg>
            {{ t('databox.outbox.uploadReceipt') }}
          </button>
          <button
            v-else-if="row.channel === 'isds' && !row.receipt_document_id && row.dispatch_state !== 'cancelled'"
            type="button"
            :class="btnOutline('neutral')"
            :disabled="saving"
            @click="openReceiptPicker(row.id)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" />
            </svg>
            {{ t('databox.outbox.uploadReceipt') }}
          </button>
          <button
            v-if="primaryAction(row) === 'confirm'"
            type="button"
            :class="btnFilled('success')"
            :disabled="busyId === row.id"
            @click="confirmSend(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
            </svg>
            {{ t('databox.outbox.confirmSend') }}
          </button>
          <!-- Strojové odeslání zůstává dostupné, ale ne jako hlavní krok:
               dokud transport není nasazený, skončí srozumitelnou překážkou. -->
          <button
            v-if="row.dispatch_state === 'ready' && row.channel === 'isds'"
            type="button"
            :class="btnOutline('primary')"
            :disabled="busyId === row.id"
            @click="confirmSend(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
            </svg>
            {{ t('databox.outbox.confirmSend') }}
          </button>
          <button
            v-if="primaryAction(row) === 'resolve'"
            type="button"
            :class="btnFilled('warning')"
            :disabled="busyId === row.id"
            @click="resolveUncertain(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" />
            </svg>
            {{ t('databox.outbox.resolve') }}
          </button>
          <button type="button" :class="btnOutline('neutral')" @click="toggleAttempts(row)">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" />
            </svg>
            {{ t('databox.outbox.attempts') }}
          </button>
          <button
            v-if="row.dispatch_state === 'ready'"
            type="button"
            :class="btnOutline('danger')"
            :disabled="busyId === row.id"
            @click="cancelSubmission(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
            </svg>
            {{ t('databox.outbox.cancel') }}
          </button>
        </div>

        <div v-if="expanded === row.id" class="mt-3 overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase text-neutral-400">
                <th class="py-1 pr-3">#</th>
                <th class="py-1 pr-3">{{ t('databox.outbox.attemptOutcome') }}</th>
                <th class="py-1 pr-3">{{ t('databox.outbox.messageId') }}</th>
                <th class="py-1 pr-3">{{ t('databox.outbox.startedAt') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in attempts[row.id] ?? []" :key="a.id" class="border-t border-neutral-100 dark:border-neutral-800">
                <td class="py-1 pr-3">{{ a.attempt_no }}</td>
                <td class="py-1 pr-3">{{ t(`databox.attempt.${a.outcome}`) }}</td>
                <td class="py-1 pr-3"><code>{{ a.external_message_id ?? '—' }}</code></td>
                <td class="py-1 pr-3">{{ a.started_at }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ─────────────── Příchozí ─────────────── -->
    <section v-else-if="tab === 'inbox'" class="space-y-4">
      <!-- Prázdno vs. porucha musí být rozlišitelné na první pohled -->
      <div
        v-if="pollState && pollState.consecutive_failures > 0"
        class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-900/20 dark:text-danger-200"
      >
        {{ t('databox.inbox.unreachable', { count: pollState.consecutive_failures }) }}
        <div v-if="pollState.last_ok_at" class="mt-1 text-xs">
          {{ t('databox.inbox.lastOkAt', { at: pollState.last_ok_at }) }}
        </div>
      </div>
      <div v-else-if="pollState?.last_ok_at" class="text-sm text-neutral-500 dark:text-neutral-400">
        {{ t('databox.inbox.lastOkAt', { at: pollState.last_ok_at }) }}
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          :class="btnOutline('primary')"
          :disabled="saving || !currentCredential?.inbox_polling_enabled"
          @click="pollInbox"
        >
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.inbox" />
          </svg>
          {{ t('databox.inbox.poll') }}
        </button>
      </div>
      <p v-if="!currentCredential?.inbox_polling_enabled" class="text-sm text-neutral-500 dark:text-neutral-400">
        {{ t('databox.inbox.pollingOff') }}
      </p>

      <EmptyState v-if="!loading && inbox.length === 0" icon="inbox" :title="t('databox.inbox.empty')" />

      <div class="overflow-x-auto">
        <table v-if="inbox.length" class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase text-neutral-400">
              <th class="py-2 pr-3">{{ t('databox.inbox.subject') }}</th>
              <th class="py-2 pr-3">{{ t('databox.inbox.sender') }}</th>
              <th class="py-2 pr-3">{{ t('databox.inbox.classification') }}</th>
              <th class="py-2 pr-3">{{ t('databox.inbox.deliveredAt') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in inbox" :key="m.id" class="border-t border-neutral-100 dark:border-neutral-800">
              <td class="py-2 pr-3">{{ m.subject ?? '—' }}</td>
              <td class="py-2 pr-3">{{ m.sender_name ?? m.sender_box_id ?? '—' }}</td>
              <td class="py-2 pr-3">
                <span
                  class="rounded-full px-2 py-0.5 text-xs"
                  :class="m.classification === 'unclassified'
                    ? 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'
                    : 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200'"
                >
                  {{ t(`databox.classification.${m.classification}`) }}
                </span>
              </td>
              <td class="py-2 pr-3">{{ m.delivered_at ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ─────────────── Příjemci ─────────────── -->
    <section v-else class="space-y-4">
      <div
        v-if="recipientsWithoutBox.length"
        class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-900/20 dark:text-warning-200"
      >
        {{ t('databox.recipients.missingBoxIds', { count: recipientsWithoutBox.length }) }}
      </div>
      <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ t('databox.recipients.taxOfficeHint') }}</p>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase text-neutral-400">
              <th class="py-2 pr-3">{{ t('databox.recipients.name') }}</th>
              <th class="py-2 pr-3">{{ t('databox.recipients.kind') }}</th>
              <th class="py-2 pr-3">{{ t('databox.recipients.boxId') }}</th>
              <th class="py-2 pr-3">{{ t('databox.recipients.source') }}</th>
              <th class="py-2 pr-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recipients" :key="r.id" class="border-t border-neutral-100 dark:border-neutral-800">
              <td class="py-2 pr-3">{{ r.name }}</td>
              <td class="py-2 pr-3">{{ t(`databox.recipientKind.${r.kind}`) }}</td>
              <td class="py-2 pr-3">
                <code v-if="r.isds_box_id">{{ r.isds_box_id }}</code>
                <span v-else class="text-warning-700 dark:text-warning-300">{{ t('databox.recipients.noBoxId') }}</span>
              </td>
              <td class="py-2 pr-3 max-w-xs truncate">
                <a v-if="r.source_url" :href="r.source_url" target="_blank" rel="noopener" class="text-primary-600 hover:underline">
                  {{ t('databox.recipients.sourceLink') }}
                </a>
                <span v-else>—</span>
              </td>
              <td class="py-2 pr-3">
                <button
                  v-if="!r.is_system"
                  type="button"
                  :class="btnOutlineSm('danger')"
                  :disabled="busyId === r.id"
                  @click="deleteRecipient(r)"
                >
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <h2 class="mb-3 font-medium">{{ t('databox.recipients.addTitle') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.code') }}</span>
            <input v-model="recipientCode" type="text" class="form-input mt-1 w-full" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.name') }}</span>
            <input v-model="recipientName" type="text" class="form-input mt-1 w-full" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.kind') }}</span>
            <select v-model="recipientKind" class="form-select mt-1 w-full">
              <option value="tax_office">{{ t('databox.recipientKind.tax_office') }}</option>
              <option value="cssz">{{ t('databox.recipientKind.cssz') }}</option>
              <option value="health_insurer">{{ t('databox.recipientKind.health_insurer') }}</option>
              <option value="other">{{ t('databox.recipientKind.other') }}</option>
            </select>
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.boxId') }}</span>
            <input v-model="recipientBoxId" type="text" maxlength="7" class="form-input mt-1 w-full" />
          </label>
          <label class="block sm:col-span-2">
            <span class="text-sm font-medium">{{ t('databox.recipients.source') }}</span>
            <input v-model="recipientSource" type="url" class="form-input mt-1 w-full" />
            <span class="mt-1 block text-xs text-neutral-500 dark:text-neutral-400">
              {{ t('databox.recipients.sourceHint') }}
            </span>
          </label>
        </div>
      </div>

      <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-white/95 py-3 dark:border-neutral-700 dark:bg-neutral-900/95">
        <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="saveRecipient">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
          </svg>
          {{ t('common.save') }}
        </button>
      </div>
    </section>
  </div>
</template>
