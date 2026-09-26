<script setup lang="ts">
/**
 * Pokus o odeslání „možná doručeno" a co s ním.
 *
 * Požadavek na ČSSZ odešel, ale odpověď nedorazila (vypršený čas, spadlé
 * spojení, nečitelná odpověď). Aplikace sama znovu neodesílá: kdyby originál
 * u ČSSZ byl, vzniklo by druhé podání. Účetní tu dostane celý postup na jednom
 * místě: co se stalo, kde protokol hledat, jak ho načíst, a teprve pak
 * výslovné potvrzení opakování (se stejným GUID).
 *
 * Varianta `jmhz_original_at_cssz`: ČSSZ na opakované odeslání odpověděla
 * „shodné podání už existuje". Opakovat nemá smysl, zbývá doložit protokol
 * originálu.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { payrollApi, type PayrollRegzelEnvironment } from '@/api/payroll'
import { PAYROLL_TRANSPORT_ORIGINAL_AT_CSSZ } from '@/api/payrollTransportCodes'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, disabledTitle, ICONS } from '@/components/ui/buttonStyles'

const props = withDefaults(defineProps<{
  environment: PayrollRegzelEnvironment
  submissionId: number
  errorCode: string | null
  correlationReference?: string | null
  canWrite: boolean
  /**
   * `emit`: načtení protokolu obstará rodič (obrazovka Stav odeslání má vlastní
   * výběr souboru). `link`: proklik na Stav odeslání, kde se protokol načítá.
   */
  importMode?: 'emit' | 'link'
}>(), {
  correlationReference: null,
  importMode: 'emit',
})

const emit = defineEmits<{
  'import-protocol': []
  confirmed: []
}>()

const { t } = useI18n()

const originalAtCssz = computed(() => props.errorCode === PAYROLL_TRANSPORT_ORIGINAL_AT_CSSZ)
const confirming = ref(false)
const reason = ref('')
const searched = ref(false)
const busy = ref(false)
const error = ref('')
const done = ref(false)

const canSubmit = computed(() => reason.value.trim() !== '' && searched.value && !busy.value)

function openConfirm() {
  confirming.value = true
  error.value = ''
}

function cancel() {
  confirming.value = false
  reason.value = ''
  searched.value = false
  error.value = ''
}

async function submit() {
  if (!canSubmit.value) {
    error.value = t('payroll.transport_delivery.reason_required')
    return
  }
  busy.value = true
  error.value = ''
  try {
    await payrollApi.confirmSubmissionRetry(props.environment, props.submissionId, reason.value.trim())
    done.value = true
    confirming.value = false
    emit('confirmed')
  } catch (e) {
    error.value = apiErrorMessage(e, t('payroll.transport_delivery.confirm_failed'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div
    class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-900"
    :data-test="`possibly-delivered-${submissionId}`"
    role="status"
  >
    <p class="font-semibold">
      {{ originalAtCssz
        ? t('payroll.transport_delivery.original_title')
        : t('payroll.transport_delivery.possibly_title') }}
    </p>
    <p class="mt-1">
      {{ originalAtCssz
        ? t('payroll.transport_delivery.original_what')
        : t('payroll.transport_delivery.possibly_what') }}
    </p>
    <p class="mt-1 text-xs text-warning-800">
      {{ t('payroll.transport_delivery.who', { id: submissionId }) }}
    </p>
    <p class="mt-1 text-xs text-warning-800">
      {{ originalAtCssz
        ? t('payroll.transport_delivery.original_where')
        : t('payroll.transport_delivery.possibly_where') }}
    </p>
    <p v-if="correlationReference" class="mt-1 text-xs text-warning-800">
      {{ t('payroll.transport_delivery.correlation', { id: correlationReference }) }}
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-2">
      <button
        v-if="importMode === 'emit'"
        type="button"
        :class="btnOutline('primary')"
        :data-test="`possibly-delivered-import-${submissionId}`"
        @click="emit('import-protocol')"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.upload" /></svg>
        {{ t('payroll.transport_delivery.import_action') }}
      </button>
      <RouterLink
        v-else
        :to="{ name: 'payroll-submissions-tab', params: { tab: 'transport' } }"
        :class="btnOutline('primary')"
        :data-test="`possibly-delivered-import-link-${submissionId}`"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.upload" /></svg>
        {{ t('payroll.transport_delivery.import_link') }}
      </RouterLink>
      <a
        href="/admin/databox?tab=inbox"
        :class="btnOutline('neutral')"
        :data-test="`possibly-delivered-databox-${submissionId}`"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.inbox" /></svg>
        {{ t('payroll.transport_delivery.databox_action') }}
      </a>
      <button
        v-if="!originalAtCssz && canWrite && !confirming && !done"
        type="button"
        :class="btnOutline('warning')"
        :data-test="`possibly-delivered-confirm-open-${submissionId}`"
        @click="openConfirm"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
        {{ t('payroll.transport_delivery.confirm_action') }}
      </button>
    </div>

    <form
      v-if="confirming"
      class="mt-3 space-y-2 rounded-md border border-warning-300 bg-surface p-3 text-neutral-800"
      :data-test="`possibly-delivered-confirm-form-${submissionId}`"
      @submit.prevent="submit"
    >
      <p class="font-medium text-neutral-900">{{ t('payroll.transport_delivery.confirm_title') }}</p>
      <p class="text-xs text-neutral-600">{{ t('payroll.transport_delivery.confirm_hint') }}</p>
      <label class="block text-xs font-medium text-neutral-700" :for="`possibly-delivered-reason-${submissionId}`">
        {{ t('payroll.transport_delivery.reason_label') }}
      </label>
      <textarea
        :id="`possibly-delivered-reason-${submissionId}`"
        v-model="reason"
        rows="2"
        class="w-full rounded-md border border-neutral-300 px-2 py-1 text-sm"
        :placeholder="t('payroll.transport_delivery.reason_placeholder')"
        :data-test="`possibly-delivered-reason-${submissionId}`"
      />
      <label class="flex items-start gap-2 text-xs text-neutral-700">
        <input
          v-model="searched"
          type="checkbox"
          class="mt-0.5"
          :data-test="`possibly-delivered-searched-${submissionId}`"
        >
        <span>{{ t('payroll.transport_delivery.searched_label') }}</span>
      </label>
      <div class="flex flex-wrap gap-2">
        <button
          type="submit"
          :class="btnFilled('warning')"
          :disabled="!canSubmit"
          :title="disabledTitle(!canSubmit, t('payroll.transport_delivery.reason_required'))"
          :data-test="`possibly-delivered-confirm-submit-${submissionId}`"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ busy ? t('common.loading') : t('payroll.transport_delivery.submit') }}
        </button>
        <button type="button" :class="btnOutline('neutral')" @click="cancel">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
      </div>
      <p v-if="!canSubmit && !busy" :class="BTN_DISABLED_NOTE">
        {{ t('payroll.transport_delivery.reason_required') }}
      </p>
    </form>

    <p v-if="error" class="mt-2 text-xs text-danger-700" role="alert">{{ error }}</p>
    <p v-if="done" class="mt-2 text-xs text-success-700" :data-test="`possibly-delivered-confirmed-${submissionId}`">
      {{ t('payroll.transport_delivery.confirmed') }}
    </p>
  </div>
</template>
