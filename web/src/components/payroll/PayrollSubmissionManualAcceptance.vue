<script setup lang="ts">
/*
 * Ruční potvrzení přijetí podání podle aplikace ČSSZ.
 *
 * Štítek „Přijato ručně" stojí vedle stavu podání schválně odlišený od
 * přijetí podle protokolu: tvrdí to účetní, ne úřad. Pozdější ověřený protokol
 * má přednost a jeho nesouhlas tu svítí jako rozpor.
 *
 * Tlačítko otevře dialog, který si stav podání načte sám ze serveru — server
 * rozhoduje, jestli se smí potvrdit (stejná brána jako při zápisu), takže
 * obrazovka nenabídne nic, co by pak odmítl.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollRegzelEnvironment,
  type PayrollSubmissionManualAcceptance,
  type PayrollSubmissionManualAcceptanceOverview,
  type PayrollSubmissionManualAcceptanceResult,
  type PayrollSubmissionManualAcceptanceVariant,
} from '@/api/payroll'
import Modal from '@/components/ui/Modal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatUtcDateTime } from '@/composables/useFormat'
import { usePayrollLabels } from '@/composables/usePayrollLabels'

/** Zrcadlo `PayrollSubmissionStateMachine::MANUALLY_ACCEPTABLE_STATUSES`. */
const ACCEPTABLE_STATUSES = [
  'submitted',
  'processing',
  'waiting_for_identity',
  'partially_accepted',
  'rejected',
  'correction_required',
]
const NOTE_MIN = 10
const NOTE_MAX = 1000
const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024

const props = withDefaults(defineProps<{
  environment: PayrollRegzelEnvironment
  submissionId: number
  submissionStatus: string | null
  agendaCode?: string | null
  summary?: PayrollSubmissionManualAcceptance | null
  canWrite?: boolean
  /** Pod štítkem ukázat i poznámku, kdo a kdy (detail podání). */
  detailed?: boolean
}>(), {
  agendaCode: 'JMHZ25',
  summary: null,
  canWrite: false,
  detailed: false,
})

const emit = defineEmits<{
  accepted: [result: PayrollSubmissionManualAcceptanceResult]
}>()

const { t } = useI18n()
const { submissionStatusLabel } = usePayrollLabels()

const open = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const overview = ref<PayrollSubmissionManualAcceptanceOverview | null>(null)
const variant = ref<PayrollSubmissionManualAcceptanceVariant>('unchanged')
const note = ref('')
const acceptedOn = ref<string | null>(null)
const attachment = ref<File | null>(null)
const idempotencyKey = ref('')

const isJmhz = computed(() => (props.agendaCode ?? '').toUpperCase().startsWith('JMHZ'))
const offered = computed(() =>
  props.canWrite
  && isJmhz.value
  && ACCEPTABLE_STATUSES.includes(props.submissionStatus ?? ''))
const openContradiction = computed(() =>
  props.summary?.contradiction != null && !props.summary.contradiction.is_resolved)
const noteLength = computed(() => note.value.trim().length)
const today = computed(() => new Date().toLocaleDateString('sv-SE'))
const canSubmit = computed(() =>
  overview.value?.can_accept === true
  && noteLength.value >= NOTE_MIN
  && noteLength.value <= NOTE_MAX
  && !saving.value)

function newKey(): string {
  const random = globalThis.crypto?.randomUUID?.()
    ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `manual-acceptance-${props.submissionId}-${random}`
}

function statusLabel(status: string | null | undefined): string {
  return status ? submissionStatusLabel(status) : '—'
}

function variantLabel(value: string): string {
  return t(`payroll.submissions.manual_acceptance.variant.${value}`)
}

async function openDialog(): Promise<void> {
  open.value = true
  error.value = ''
  overview.value = null
  variant.value = 'unchanged'
  note.value = ''
  acceptedOn.value = null
  attachment.value = null
  idempotencyKey.value = newKey()
  loading.value = true
  try {
    overview.value = await payrollApi.submissionManualAcceptance(props.environment, props.submissionId)
  } catch (failure) {
    error.value = apiErrorMessage(failure, t('payroll.submissions.manual_acceptance.load_failed'))
  } finally {
    loading.value = false
  }
}

function pickFile(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  error.value = ''
  if (file && file.size > ATTACHMENT_MAX_BYTES) {
    error.value = t('payroll.submissions.manual_acceptance.attachment_too_large')
    input.value = ''
    attachment.value = null
    return
  }
  attachment.value = file
}

async function submit(): Promise<void> {
  if (!canSubmit.value || !overview.value) return
  saving.value = true
  error.value = ''
  try {
    const result = await payrollApi.acceptSubmissionManually(props.environment, props.submissionId, {
      rowVersion: overview.value.submission.row_version,
      variant: variant.value,
      note: note.value.trim(),
      acceptedOn: acceptedOn.value || null,
      attachment: attachment.value,
      idempotencyKey: idempotencyKey.value,
    })
    open.value = false
    emit('accepted', result)
  } catch (failure) {
    error.value = apiErrorMessage(failure, t('payroll.submissions.manual_acceptance.save_failed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <span class="inline-flex flex-wrap items-center gap-2" :data-test="`manual-acceptance-${submissionId}`">
    <span
      v-if="summary"
      class="inline-flex items-center gap-1 whitespace-nowrap rounded-full border border-success-500/40 bg-success-50 px-2.5 py-1 text-xs font-semibold text-success-700"
      :title="summary.note"
      :data-test="`manual-acceptance-badge-${submissionId}`"
    >
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
      {{ t('payroll.submissions.manual_acceptance.badge') }}
    </span>
    <span
      v-if="openContradiction"
      class="inline-flex items-center gap-1 whitespace-nowrap rounded-full bg-danger-50 px-2.5 py-1 text-xs font-semibold text-danger-700"
      :data-test="`manual-acceptance-contradiction-${submissionId}`"
    >
      {{ t('payroll.submissions.manual_acceptance.contradiction_badge', {
        status: statusLabel(summary?.contradiction?.remote_status),
      }) }}
    </span>
    <button
      v-if="offered"
      type="button"
      :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
      :data-test="`manual-acceptance-open-${submissionId}`"
      @click="openDialog"
    >
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
      {{ t('payroll.submissions.manual_acceptance.action') }}
    </button>
  </span>

  <p
    v-if="detailed && summary"
    class="mt-2 w-full text-xs text-neutral-600"
    :data-test="`manual-acceptance-detail-${submissionId}`"
  >
    {{ t('payroll.submissions.manual_acceptance.detail', {
      variant: variantLabel(summary.variant),
      who: summary.recorded_by_name || `#${summary.recorded_by}`,
      when: formatUtcDateTime(summary.recorded_at),
      before: statusLabel(summary.status_before),
    }) }}
    <template v-if="summary.authority_accepted_on">
      · {{ t('payroll.submissions.manual_acceptance.accepted_on_value', { date: formatDate(summary.authority_accepted_on) }) }}
    </template>
    <span class="mt-1 block whitespace-pre-line text-neutral-700">{{ summary.note }}</span>
    <span v-if="openContradiction" class="mt-1 block text-danger-700">
      {{ t('payroll.submissions.manual_acceptance.contradiction_detail', {
        status: statusLabel(summary.contradiction?.remote_status),
        date: formatUtcDateTime(summary.contradiction?.received_at),
      }) }}
    </span>
  </p>

  <Modal
    v-if="open"
    :title="t('payroll.submissions.manual_acceptance.title', { id: submissionId })"
    width-class="max-w-xl"
    @close="open = false"
  >
    <form class="space-y-4" data-test="manual-acceptance-dialog" @submit.prevent="submit">
      <p class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800">
        {{ t('payroll.submissions.manual_acceptance.explanation') }}
      </p>

      <p v-if="loading" class="text-sm text-neutral-500" role="status">
        {{ t('payroll.submissions.manual_acceptance.loading') }}
      </p>

      <p
        v-else-if="overview && !overview.can_accept"
        class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-700"
        data-test="manual-acceptance-blocked"
      >
        {{ overview.blocked_reason }}
      </p>

      <template v-else-if="overview">
        <p class="text-sm text-neutral-600">
          {{ t('payroll.submissions.manual_acceptance.current_status', {
            status: statusLabel(overview.submission.status),
          }) }}
        </p>

        <fieldset class="space-y-2">
          <legend class="text-sm font-medium text-neutral-800">
            {{ t('payroll.submissions.manual_acceptance.variant_label') }}
          </legend>
          <label
            v-for="option in (['unchanged', 'changed_by_authority'] as const)"
            :key="option"
            class="flex items-start gap-2 text-sm"
          >
            <input
              v-model="variant"
              type="radio"
              name="manual-acceptance-variant"
              :value="option"
              class="mt-0.5"
              :data-test="`manual-acceptance-variant-${option}`"
            >
            <span>
              <span class="font-medium text-neutral-900">{{ variantLabel(option) }}</span>
              <span class="block text-xs text-neutral-500">
                {{ t(`payroll.submissions.manual_acceptance.variant_hint.${option}`) }}
              </span>
            </span>
          </label>
        </fieldset>

        <label class="block text-sm font-medium text-neutral-800">
          {{ t('payroll.submissions.manual_acceptance.note_label') }}
          <textarea
            v-model="note"
            :maxlength="NOTE_MAX"
            required
            class="mt-1 min-h-24 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            :placeholder="t('payroll.submissions.manual_acceptance.note_placeholder')"
            data-test="manual-acceptance-note"
          />
          <span class="mt-1 block text-xs font-normal text-neutral-500">
            {{ t('payroll.submissions.manual_acceptance.note_hint', { min: NOTE_MIN, count: noteLength, max: NOTE_MAX }) }}
          </span>
        </label>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="block text-sm font-medium text-neutral-800">
            {{ t('payroll.submissions.manual_acceptance.accepted_on_label') }}
            <DateInput
              v-model="acceptedOn"
              :max="today"
              accent="payroll"
              class="mt-1"
              data-test="manual-acceptance-date"
            />
          </label>
          <label class="block text-sm font-medium text-neutral-800">
            {{ t('payroll.submissions.manual_acceptance.attachment_label') }}
            <input
              type="file"
              accept="application/pdf,image/png,image/jpeg"
              class="mt-1 block w-full text-sm"
              data-test="manual-acceptance-file"
              @change="pickFile"
            >
            <span class="mt-1 block text-xs font-normal text-neutral-500">
              {{ t('payroll.submissions.manual_acceptance.attachment_hint') }}
            </span>
          </label>
        </div>

        <div v-if="overview.history.length" class="rounded-lg border border-neutral-200 p-3">
          <p class="text-sm font-medium text-neutral-800">
            {{ t('payroll.submissions.manual_acceptance.history_title') }}
          </p>
          <ul class="mt-2 space-y-2 text-xs text-neutral-600">
            <li v-for="entry in overview.history" :key="entry.id">
              {{ t('payroll.submissions.manual_acceptance.detail', {
                variant: variantLabel(entry.variant),
                who: entry.recorded_by_name || `#${entry.recorded_by}`,
                when: formatUtcDateTime(entry.recorded_at),
                before: statusLabel(entry.status_before),
              }) }}
              <span class="block text-neutral-700">{{ entry.note }}</span>
              <span v-if="entry.contradiction" class="block text-danger-700">
                {{ t('payroll.submissions.manual_acceptance.contradiction_detail', {
                  status: statusLabel(entry.contradiction.remote_status),
                  date: formatUtcDateTime(entry.contradiction.received_at),
                }) }}
              </span>
            </li>
          </ul>
        </div>
      </template>

      <p
        v-if="error"
        class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
        data-test="manual-acceptance-error"
      >
        {{ error }}
      </p>

      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="open = false">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button
          v-if="overview?.can_accept"
          type="submit"
          :class="[btnFilled('success'), 'whitespace-nowrap']"
          :disabled="!canSubmit"
          data-test="manual-acceptance-submit"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
          {{ saving
            ? t('payroll.submissions.manual_acceptance.saving')
            : t('payroll.submissions.manual_acceptance.submit') }}
        </button>
      </div>
    </form>
  </Modal>
</template>
