<script setup lang="ts">
/**
 * Oznámení soudu / exekutorovi, že povinný u plátce přestal pracovat
 * (§ 295 odst. 2 o. s. ř.) — do jednoho týdne od skončení poměru, s vyúčtováním
 * provedených a vyplacených srážek a s pořadím pohledávek.
 *
 * Panel vystaví oznámení (zmrazí jeho obsah), nabídne PDF, zařazení do fronty
 * datové schránky jako koncept a ruční záznam o odeslání. Oznámení je zároveň
 * doklad, bez kterého nejde případ u plátce ukončit.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollEnforcementApi,
  type EnforcementTerminationNotice,
  type EnforcementTerminationNoticeChannel,
  type EnforcementTerminationNoticeOverview,
} from '@/api/payrollEnforcement'
import { dataBoxApi, type SubmissionRecipient } from '@/api/dataBox'
import { btnFilled, btnOutline, btnOutlineSm, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import { formatMoneyMinor as money } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { appIsoDate } from '@/utils/date'

const props = defineProps<{
  caseId: number
  canWrite: boolean
}>()
const emit = defineEmits<{ (e: 'changed'): void }>()

const { t } = useI18n()
const toast = useToast()
const overview = ref<EnforcementTerminationNoticeOverview | null>(null)
const loading = ref(false)
const busy = ref(false)
const newPayerName = ref('')
const newPayerReference = ref('')
const recipients = ref<SubmissionRecipient[]>([])
const recipientsFailed = ref(false)
const recipientId = ref<number | null>(null)
const sentOn = ref(appIsoDate())
const sentChannel = ref<EnforcementTerminationNoticeChannel>('isds')
const queuedOutboxId = ref<number | null>(null)
const channels: EnforcementTerminationNoticeChannel[] = ['isds', 'post', 'personal', 'other']

const latest = computed<EnforcementTerminationNotice | null>(
  () => overview.value?.notices[0] ?? null,
)
const overdue = computed(() => {
  const notice = latest.value
  const due = notice?.due_on ?? overview.value?.preview?.due_on ?? null
  return due !== null && notice?.sent_on == null && appIsoDate() > due
})

async function load() {
  loading.value = true
  try {
    overview.value = await payrollEnforcementApi.terminationNotices(props.caseId)
  } catch {
    overview.value = null
    toast.error(t('payroll.enforcement.termination_notice.load_failed'))
  } finally {
    loading.value = false
  }
}

async function loadRecipients() {
  try {
    recipients.value = (await dataBoxApi.recipients())
      .filter((item) => item.is_active && item.has_box_id)
    recipientsFailed.value = false
  } catch {
    recipients.value = []
    recipientsFailed.value = true
  }
}

function errorMessage(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { error?: { message?: string } } } })
    ?.response?.data?.error?.message
  return typeof message === 'string' && message !== '' ? message : fallback
}

async function generate() {
  if (busy.value) return
  busy.value = true
  try {
    await payrollEnforcementApi.generateTerminationNotice(props.caseId, {
      new_payer_name: newPayerName.value.trim() || null,
      new_payer_reference: newPayerReference.value.trim() || null,
    })
    toast.success(t('payroll.enforcement.termination_notice.generated'))
    await load()
    emit('changed')
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.enforcement.termination_notice.generate_failed')))
  } finally {
    busy.value = false
  }
}

async function download(notice: EnforcementTerminationNotice) {
  try {
    await payrollEnforcementApi.downloadTerminationNotice(notice.id)
  } catch {
    toast.error(t('payroll.enforcement.termination_notice.download_failed'))
  }
}

async function markSent(notice: EnforcementTerminationNotice) {
  if (busy.value || sentOn.value === '') return
  busy.value = true
  try {
    await payrollEnforcementApi.markTerminationNoticeSent(notice.id, {
      sent_on: sentOn.value,
      channel: sentChannel.value,
    })
    toast.success(t('payroll.enforcement.termination_notice.marked_sent'))
    await load()
    emit('changed')
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.enforcement.termination_notice.mark_failed')))
  } finally {
    busy.value = false
  }
}

async function enqueue(notice: EnforcementTerminationNotice) {
  if (busy.value || recipientId.value === null) return
  busy.value = true
  try {
    const result = await payrollEnforcementApi.enqueueTerminationNotice(notice.id, recipientId.value)
    queuedOutboxId.value = result.outbox_id
    toast.success(t('payroll.enforcement.termination_notice.queued'))
    await load()
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.enforcement.termination_notice.enqueue_failed')))
  } finally {
    busy.value = false
  }
}

watch(() => props.caseId, () => {
  queuedOutboxId.value = null
  void load()
})
onMounted(() => {
  void load()
  if (props.canWrite) void loadRecipients()
})
</script>

<template>
  <section class="rounded-lg border border-neutral-200 bg-surface p-4" data-test="enforcement-termination-notice">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 class="font-medium text-neutral-900">{{ t('payroll.enforcement.termination_notice.title') }}</h3>
        <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.enforcement.termination_notice.hint') }}</p>
      </div>
      <span
        v-if="overdue"
        class="rounded-full bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700"
        data-test="termination-notice-overdue"
      >{{ t('payroll.enforcement.termination_notice.overdue') }}</span>
    </div>

    <p v-if="loading" class="mt-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <template v-else-if="overview">
      <p
        v-if="overview.blocked_reason && overview.notices.length === 0"
        class="mt-3 rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-600"
        data-test="termination-notice-blocked"
      >{{ overview.blocked_reason }}</p>

      <div v-if="overview.preview" class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4" data-test="termination-notice-preview">
        <div><span class="block text-xs text-neutral-500">{{ t('payroll.enforcement.termination_notice.ended_on') }}</span>{{ overview.preview.employment.ended_on }}</div>
        <div><span class="block text-xs text-neutral-500">{{ t('payroll.enforcement.termination_notice.due_on') }}</span><strong>{{ overview.preview.due_on }}</strong></div>
        <div><span class="block text-xs text-neutral-500">{{ t(`payroll.enforcement.termination_notice.authority.${overview.preview.authority.role}`) }}</span>{{ overview.preview.authority.name }}<span v-if="overview.preview.authority.reference" class="text-neutral-500"> · {{ overview.preview.authority.reference }}</span></div>
        <div><span class="block text-xs text-neutral-500">{{ t('payroll.enforcement.termination_notice.withheld_paid') }}</span>{{ money(overview.preview.totals.withheld_minor) }} / {{ money(overview.preview.totals.paid_out_minor) }}</div>
      </div>

      <form v-if="canWrite && overview.preview" class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3" @submit.prevent="generate">
        <label class="text-xs font-medium text-neutral-600">
          {{ t('payroll.enforcement.termination_notice.new_payer_name') }}
          <input v-model="newPayerName" maxlength="255" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="termination-notice-new-payer">
          <span class="mt-1 block font-normal text-neutral-500">{{ t('payroll.enforcement.termination_notice.new_payer_hint') }}</span>
        </label>
        <label class="text-xs font-medium text-neutral-600">
          {{ t('payroll.enforcement.termination_notice.new_payer_reference') }}
          <input v-model="newPayerReference" maxlength="128" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
        </label>
        <div class="flex flex-wrap items-end gap-2">
          <button type="submit" :class="btnFilled('primary')" class="whitespace-nowrap" :disabled="busy" data-test="termination-notice-generate">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
            {{ t(overview.notices.length ? 'payroll.enforcement.termination_notice.regenerate' : 'payroll.enforcement.termination_notice.generate') }}
          </button>
        </div>
      </form>

      <ul v-if="overview.notices.length" class="mt-4 space-y-3">
        <li v-for="notice in overview.notices" :key="notice.id" class="rounded-md border border-neutral-200 p-3" data-test="termination-notice-row">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="text-sm">
              <strong>{{ t('payroll.enforcement.termination_notice.revision', { revision: notice.revision_no }) }}</strong>
              <span class="ml-2 text-neutral-500">{{ t('payroll.enforcement.termination_notice.due_on') }} {{ notice.due_on }}</span>
              <span
                class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium"
                :class="notice.sent_on ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-600'"
              >{{ notice.sent_on ? t('payroll.enforcement.termination_notice.sent', { date: notice.sent_on, channel: t(`payroll.enforcement.termination_notice.channels.${notice.sent_channel}`) }) : t('payroll.enforcement.termination_notice.not_sent') }}</span>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" data-test="termination-notice-download" @click="download(notice)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
              {{ t('payroll.enforcement.termination_notice.download') }}
            </button>
          </div>

          <div v-if="canWrite && notice.id === latest?.id && !notice.sent_on" class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
            <div class="rounded-md bg-neutral-50 p-3">
              <p class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.termination_notice.isds_title') }}</p>
              <div class="mt-2 flex flex-wrap items-end gap-2">
                <select v-model="recipientId" class="min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="termination-notice-recipient">
                  <option :value="null">{{ t('payroll.enforcement.termination_notice.recipient_placeholder') }}</option>
                  <option v-for="recipient in recipients" :key="recipient.id" :value="recipient.id">{{ recipient.name }} ({{ recipient.isds_box_id }})</option>
                </select>
                <button type="button" :class="btnFilled('primary')" class="whitespace-nowrap" :disabled="busy || recipientId === null" data-test="termination-notice-enqueue" @click="enqueue(notice)">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.send" /></svg>
                  {{ t('payroll.enforcement.termination_notice.enqueue') }}
                </button>
              </div>
              <p v-if="recipientsFailed || recipients.length === 0" :class="[BTN_DISABLED_NOTE, 'mt-2']">{{ t('payroll.enforcement.termination_notice.recipient_missing') }}</p>
              <p v-if="queuedOutboxId !== null || notice.outbox_id !== null" class="mt-2 text-xs text-success-700">
                {{ t('payroll.enforcement.termination_notice.queued_hint') }}
                <RouterLink class="font-medium text-primary-700 hover:underline" to="/admin/databox">{{ t('payroll.enforcement.termination_notice.open_databox') }}</RouterLink>
              </p>
            </div>
            <div class="rounded-md bg-neutral-50 p-3">
              <p class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.termination_notice.mark_sent_title') }}</p>
              <div class="mt-2 flex flex-wrap items-end gap-2">
                <DateInput v-model="sentOn" class="w-40" :aria-label="t('payroll.enforcement.termination_notice.sent_on')" />
                <select v-model="sentChannel" class="rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="termination-notice-channel">
                  <option v-for="channel in channels" :key="channel" :value="channel">{{ t(`payroll.enforcement.termination_notice.channels.${channel}`) }}</option>
                </select>
                <button type="button" :class="btnOutline('success')" class="whitespace-nowrap" :disabled="busy || sentOn === ''" data-test="termination-notice-mark-sent" @click="markSent(notice)">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                  {{ t('payroll.enforcement.termination_notice.mark_sent') }}
                </button>
              </div>
            </div>
          </div>
        </li>
      </ul>
    </template>
  </section>
</template>
