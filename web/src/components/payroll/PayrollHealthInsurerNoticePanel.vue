<script setup lang="ts">
/*
 * Sdělení zdravotní pojišťovny zaměstnancem a písemné potvrzení zaměstnavatele.
 *
 * Pojištěnec je povinen sdělit zaměstnavateli pojišťovnu při nástupu a změnu
 * do osmi dnů a zaměstnavatel přijetí sdělení písemně potvrdí (§ 12 písm. b)
 * zákona č. 48/1997 Sb.). Panel eviduje obě data u věty historie pojišťovny
 * osoby a nabízí potvrzení k vytištění. Obsah hromadného oznámení (HOZ) se
 * tím nemění.
 */
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isAxiosError } from 'axios'
import {
  payrollHealthInsurerNoticesApi,
  type PayrollHealthInsurerNotice,
} from '@/api/payrollHealthInsurerNotices'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { formatDate } from '@/composables/useFormat'
import { downloadApiFile } from '@/utils/downloadFile'

const props = defineProps<{
  personId: number
  canWrite: boolean
}>()

const { t } = useI18n()

const loading = ref(true)
const busyId = ref<number | null>(null)
const items = ref<PayrollHealthInsurerNotice[]>([])
const notifiedOn = ref<Record<number, string>>({})
const confirmedOn = ref<Record<number, string>>({})
const error = ref('')
const success = ref('')

function message(cause: unknown, fallback: string): string {
  if (isAxiosError(cause)) {
    const detail = cause.response?.data?.error?.message
    if (typeof detail === 'string' && detail !== '') {
      return detail
    }
  }
  return fallback
}

function fill(rows: PayrollHealthInsurerNotice[]): void {
  items.value = rows
  const notified: Record<number, string> = {}
  const confirmed: Record<number, string> = {}
  for (const row of rows) {
    notified[row.id] = row.employee_notified_on ?? ''
    confirmed[row.id] = row.employer_confirmed_on ?? ''
  }
  notifiedOn.value = notified
  confirmedOn.value = confirmed
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    fill(await payrollHealthInsurerNoticesApi.list(props.personId))
  } catch (cause) {
    error.value = message(cause, t('payroll.healthInsurerNotice.errors.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function save(item: PayrollHealthInsurerNotice): Promise<void> {
  busyId.value = item.id
  error.value = ''
  success.value = ''
  try {
    await payrollHealthInsurerNoticesApi.record(props.personId, item.id, {
      employee_notified_on: notifiedOn.value[item.id] || null,
      employer_confirmed_on: confirmedOn.value[item.id] || null,
    })
    success.value = t('payroll.healthInsurerNotice.saved')
    fill(await payrollHealthInsurerNoticesApi.list(props.personId))
  } catch (cause) {
    error.value = message(cause, t('payroll.healthInsurerNotice.errors.saveFailed'))
  } finally {
    busyId.value = null
  }
}

async function confirmation(item: PayrollHealthInsurerNotice): Promise<void> {
  busyId.value = item.id
  error.value = ''
  try {
    await downloadApiFile(
      payrollHealthInsurerNoticesApi.confirmationUrl(props.personId, item.id),
      `potvrzeni-zp-${item.id}.pdf`,
    )
  } catch (cause) {
    error.value = message(cause, t('payroll.healthInsurerNotice.errors.downloadFailed'))
  } finally {
    busyId.value = null
  }
}

function actionsFor(item: PayrollHealthInsurerNotice): ActionItem[] {
  const busy = busyId.value === item.id
  return [
    {
      key: 'save',
      label: t('payroll.healthInsurerNotice.actions.save'),
      icon: 'check',
      tier: 'primary',
      variant: 'primary',
      loading: busy,
      disabled: !props.canWrite,
      disabledReason: props.canWrite ? undefined : t('payroll.healthInsurerNotice.hints.readOnly'),
      run: () => void save(item),
    },
    {
      key: 'confirmation',
      label: t('payroll.healthInsurerNotice.actions.confirmation'),
      icon: 'download',
      tier: 'secondary',
      variant: 'neutral',
      loading: busy,
      disabled: !item.confirmation_available,
      disabledReason: item.confirmation_available
        ? undefined
        : t('payroll.healthInsurerNotice.hints.noticeRequired'),
      run: () => void confirmation(item),
    },
  ]
}

watch(() => props.personId, () => void load())
onMounted(() => void load())
</script>

<template>
  <section
    class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm"
    data-test="health-insurer-notices"
  >
    <div>
      <h4 class="text-sm font-semibold text-neutral-900">
        {{ t('payroll.healthInsurerNotice.title') }}
      </h4>
      <p class="mt-1 max-w-prose text-xs text-neutral-500">
        {{ t('payroll.healthInsurerNotice.intro') }}
      </p>
    </div>

    <p
      v-if="error"
      class="rounded-lg bg-danger-50 p-2 text-xs text-danger-700"
      role="alert"
      data-test="health-insurer-notice-error"
    >
      {{ error }}
    </p>
    <p
      v-if="success"
      class="rounded-lg bg-success-50 p-2 text-xs text-success-700"
      role="status"
      data-test="health-insurer-notice-success"
    >
      {{ success }}
    </p>

    <div v-if="loading" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
    <p v-else-if="!items.length" class="text-xs text-neutral-500" data-test="health-insurer-notice-empty">
      {{ t('payroll.healthInsurerNotice.empty') }}
    </p>
    <ul v-else class="space-y-3">
      <li
        v-for="item in items"
        :key="item.id"
        class="rounded-lg border border-neutral-200 p-3 text-sm"
        :data-test="`health-insurer-notice-${item.id}`"
      >
        <p class="font-medium text-neutral-900">
          {{ item.insurer_code }}<template v-if="item.insurer_name"> - {{ item.insurer_name }}</template>
        </p>
        <p class="text-xs text-neutral-500">
          {{ t('payroll.healthInsurerNotice.effectiveFrom', { day: formatDate(item.effective_from) }) }}
        </p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <label class="block text-xs">
            <span class="mb-1 block font-medium text-neutral-700">
              {{ t('payroll.healthInsurerNotice.notifiedOn') }}
            </span>
            <DateInput
              v-model="notifiedOn[item.id]"
              :disabled="!canWrite"
              class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm text-neutral-900"
              :data-test="`health-insurer-notified-on-${item.id}`" />
          </label>
          <label class="block text-xs">
            <span class="mb-1 block font-medium text-neutral-700">
              {{ t('payroll.healthInsurerNotice.confirmedOn') }}
            </span>
            <DateInput
              v-model="confirmedOn[item.id]"
              :disabled="!canWrite"
              class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm text-neutral-900"
              :data-test="`health-insurer-confirmed-on-${item.id}`" />
          </label>
        </div>
        <ActionBar class="mt-3" :actions="actionsFor(item)" />
      </li>
    </ul>
  </section>
</template>
