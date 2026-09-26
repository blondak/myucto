<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzExternalSubmission,
  type PayrollJmhzTransportEnvironment,
} from '@/api/payroll'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDateTime, formatPeriod } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'

/*
 * Podání, která za firmu podal předchozí mzdový program (převod z PAMICA, nahrané
 * XML hlášení). Firma bez převodu tu nic nemá a panel se nevykreslí vůbec.
 *
 * Záznam není jen informace: za měsíc s odeslaným řádným hlášením server vlastní
 * řádné hlášení nezmrazí (ČSSZ by ho zamítla jako duplicitní). Když záznam
 * neodpovídá skutečnosti, musí jít odebrat tady — ne v databázi.
 */
const props = defineProps<{
  environment: PayrollJmhzTransportEnvironment
}>()

const { t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))

const items = ref<PayrollJmhzExternalSubmission[]>([])
const loading = ref(true)
const error = ref('')
const removing = ref<number | null>(null)

const monthly = computed(() => items.value.filter(item => item.document_kind === 'monthly'))
const registrations = computed(() => items.value.filter(item => item.document_kind === 'registration'))

/** Měsíce, za které předchozí program hlášení připravil, ale žádné neodeslal. */
const unsentPeriods = computed(() => {
  const sent = new Set(monthly.value.filter(item => item.status === 'sent').map(item => item.period))
  const periods = new Set<string>()
  for (const item of monthly.value) {
    if (item.status === 'not_sent' && item.period && !sent.has(item.period)) periods.add(item.period)
  }
  return [...periods].sort()
})

function typeLabel(item: PayrollJmhzExternalSubmission): string {
  if (item.document_kind === 'registration') return t('payroll.external_jmhz.type.registration')
  return t(`payroll.external_jmhz.type.${item.submission_type ?? 'R'}`)
}

function sourceLabel(item: PayrollJmhzExternalSubmission): string {
  if (item.source === 'jmhz_xml') {
    return item.file_name
      ? t('payroll.external_jmhz.source.jmhz_xml_file', { file: item.file_name })
      : t('payroll.external_jmhz.source.jmhz_xml')
  }
  return item.program ?? t('payroll.external_jmhz.source.pamica')
}

function statusClass(item: PayrollJmhzExternalSubmission): string {
  return item.status === 'sent'
    ? 'bg-success-50 text-success-700'
    : 'bg-warning-50 text-warning-700'
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    items.value = (await payrollApi.jmhzExternalSubmissions(props.environment)).items
  } catch (exception) {
    items.value = []
    error.value = apiErrorMessage(exception, t('payroll.external_jmhz.load_failed'))
  } finally {
    loading.value = false
  }
}

async function remove(item: PayrollJmhzExternalSubmission) {
  if (!canWrite.value || removing.value !== null) return
  const question = t('payroll.external_jmhz.remove_confirm', {
    what: typeLabel(item),
    period: item.period ? formatPeriod(item.period) : '—',
  })
  if (!window.confirm(question)) return
  removing.value = item.id
  error.value = ''
  try {
    await payrollApi.deleteJmhzExternalSubmission(item.id, props.environment)
    items.value = items.value.filter(row => row.id !== item.id)
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.external_jmhz.remove_failed'))
  } finally {
    removing.value = null
  }
}

watch(() => props.environment, load)
onMounted(load)
</script>

<template>
  <section
    v-if="items.length > 0 || error"
    id="external-submissions"
    class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
    data-test="external-jmhz-panel"
  >
    <div class="border-b border-neutral-200 p-4 sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.external_jmhz.title') }}</h2>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.external_jmhz.description') }}</p>
    </div>

    <div
      v-if="error"
      class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="external-jmhz-error"
    >
      {{ error }}
    </div>

    <div
      v-for="period in unsentPeriods"
      :key="period"
      class="m-4 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
      role="status"
      data-test="external-jmhz-unsent"
    >
      <p class="font-medium">{{ t('payroll.external_jmhz.unsent_title', { period: formatPeriod(period) }) }}</p>
      <p class="mt-1">{{ t('payroll.external_jmhz.unsent_hint') }}</p>
      <RouterLink
        :to="{ name: 'imports-pamica' }"
        class="mt-1 inline-block font-medium text-payroll-600 underline hover:text-payroll-700"
      >
        {{ t('payroll.external_jmhz.open_migration') }}
      </RouterLink>
    </div>

    <div v-if="items.length" class="hidden overflow-x-auto md:block">
      <table class="min-w-full divide-y divide-neutral-200 text-sm">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
            <th class="px-4 py-3">{{ t('payroll.external_jmhz.col_period') }}</th>
            <th class="px-4 py-3">{{ t('payroll.external_jmhz.col_type') }}</th>
            <th class="px-4 py-3">{{ t('payroll.external_jmhz.col_status') }}</th>
            <th class="px-4 py-3">{{ t('payroll.external_jmhz.col_submitted') }}</th>
            <th class="px-4 py-3">{{ t('payroll.external_jmhz.col_accepted') }}</th>
            <th class="px-4 py-3 text-right">{{ t('payroll.external_jmhz.col_forms') }}</th>
            <th class="px-4 py-3">{{ t('payroll.external_jmhz.col_source') }}</th>
            <th class="px-4 py-3 text-right">{{ t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="item in [...monthly, ...registrations]" :key="item.id" data-test="external-jmhz-row">
            <td class="px-4 py-3 whitespace-nowrap text-neutral-900">{{ item.period ? formatPeriod(item.period) : '—' }}</td>
            <td class="px-4 py-3 text-neutral-700">{{ typeLabel(item) }}</td>
            <td class="px-4 py-3">
              <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(item)">
                {{ t(`payroll.external_jmhz.status.${item.status}`) }}
              </span>
            </td>
            <td class="px-4 py-3 whitespace-nowrap text-neutral-700">{{ item.submitted_at ? formatDateTime(item.submitted_at) : '—' }}</td>
            <td class="px-4 py-3 whitespace-nowrap text-neutral-700">{{ item.accepted_at ? formatDateTime(item.accepted_at) : '—' }}</td>
            <td class="px-4 py-3 whitespace-nowrap text-right text-neutral-700">
              {{ t('payroll.external_jmhz.forms_matched', { matched: item.matched_forms, total: item.form_count }) }}
            </td>
            <td class="px-4 py-3 text-neutral-700">{{ sourceLabel(item) }}</td>
            <td class="px-4 py-3 text-right">
              <button
                v-if="canWrite"
                type="button"
                :class="[btnOutlineSm('danger'), 'whitespace-nowrap']"
                :disabled="removing === item.id"
                data-test="external-jmhz-remove"
                @click="remove(item)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.trash" />
                </svg>
                {{ t('payroll.external_jmhz.remove') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="items.length" class="grid grid-cols-1 gap-3 p-4 md:hidden">
      <article
        v-for="item in [...monthly, ...registrations]"
        :key="item.id"
        class="rounded-lg border border-neutral-200 p-4"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h3 class="font-medium text-neutral-900">{{ typeLabel(item) }} {{ item.period ? formatPeriod(item.period) : '' }}</h3>
            <p class="mt-1 text-xs text-neutral-500">{{ sourceLabel(item) }}</p>
          </div>
          <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(item)">
            {{ t(`payroll.external_jmhz.status.${item.status}`) }}
          </span>
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
          <div>
            <dt class="text-neutral-500">{{ t('payroll.external_jmhz.col_submitted') }}</dt>
            <dd class="mt-0.5 text-neutral-800">{{ item.submitted_at ? formatDateTime(item.submitted_at) : '—' }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.external_jmhz.col_forms') }}</dt>
            <dd class="mt-0.5 text-neutral-800">{{ t('payroll.external_jmhz.forms_matched', { matched: item.matched_forms, total: item.form_count }) }}</dd>
          </div>
        </dl>
        <button
          v-if="canWrite"
          type="button"
          class="mt-4"
          :class="[btnOutlineSm('danger'), 'whitespace-nowrap']"
          :disabled="removing === item.id"
          @click="remove(item)"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.trash" />
          </svg>
          {{ t('payroll.external_jmhz.remove') }}
        </button>
      </article>
    </div>

    <p v-if="loading" class="sr-only">{{ t('common.loading') }}</p>
  </section>
</template>
