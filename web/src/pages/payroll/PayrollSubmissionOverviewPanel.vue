<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollHealthPaymentOverview,
  type PayrollRegzelEnvironment,
  type PayrollSubmissionOverviewItem,
} from '@/api/payroll'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{
  mode: 'jmhz' | 'health'
}>()

const { locale, t } = useI18n()
const loading = ref(true)
const error = ref('')
const healthError = ref('')
const period = ref(new Date().toISOString().slice(0, 7))
const environment = ref<PayrollRegzelEnvironment>('production')
const allItems = ref<PayrollSubmissionOverviewItem[]>([])
const healthOverviews = ref<PayrollHealthPaymentOverview[]>([])
const downloadingHealthKey = ref<string | null>(null)

const environmentOptions = computed(() => [
  {
    value: 'production' as const,
    label: t('payroll.regzel.environment.production'),
  },
  {
    value: 'test' as const,
    label: t('payroll.regzel.environment.test'),
  },
])
const items = computed(() => allItems.value.filter(item =>
  agendaGroup(item.agenda_code) === props.mode,
))
const counts = computed(() => ({
  total: items.value.length,
  open: items.value.filter(item => ['open', 'prepared'].includes(item.status)).length,
  submitted: items.value.filter(item => item.status === 'submitted').length,
  fulfilled: items.value.filter(item => item.status === 'fulfilled').length,
  attention: items.value.filter(item =>
    ['overdue', 'manual_review'].includes(item.status),
  ).length,
}))

function agendaGroup(code: string): 'jmhz' | 'health' | 'other' {
  const normalized = code.trim().toUpperCase()
  if (/^(?:HEALTH[_-])?(?:HOZ|PPZ)(?:[_-]|$)/.test(normalized)) {
    return 'health'
  }
  if (/^(?:JMHZ?|REGZEL(?:DOPL)?|PREZAM|PREZEC|REGZEC|DZMH|OREZAM|ZREZAM)(?:[_-]|$)/.test(normalized)) {
    return 'jmhz'
  }
  return 'other'
}

function statusClass(status: string): string {
  if (status === 'fulfilled') return 'bg-success-50 text-success-700'
  if (status === 'submitted') return 'bg-primary-50 text-primary-700'
  if (['overdue', 'manual_review'].includes(status)) {
    return 'bg-warning-50 text-warning-700'
  }
  if (status === 'cancelled') return 'bg-neutral-100 text-neutral-600'
  return 'bg-payroll-50 text-payroll-700'
}

function statusLabel(status: string): string {
  const key = `payroll.submissions.overview.status.${status}`
  const translated = t(key)
  return translated === key ? status : translated
}

function channelLabel(channel: string): string {
  const key = `payroll.submissions.overview.channel.${channel}`
  const translated = t(key)
  return translated === key ? channel : translated
}

function formatMinor(value: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    style: 'currency',
    currency: 'CZK',
    maximumFractionDigits: 2,
  }).format(value / 100)
}

function healthOverviewKey(overview: PayrollHealthPaymentOverview): string {
  return `${overview.revision_id}:${overview.insurer.code}`
}

async function loadHealthOverviews() {
  healthOverviews.value = []
  healthError.value = ''
  if (props.mode !== 'health') return
  try {
    const runs = await payrollApi.runs(period.value)
    const approved = runs.filter(run =>
      run.revision_status === 'approved' && run.revision_id !== null,
    )
    const responses = await Promise.all(approved.map(run =>
      payrollApi.healthPaymentOverviews(run.revision_id!),
    ))
    healthOverviews.value = responses.flatMap(response => response.items)
  } catch (exception) {
    healthError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.health_load_failed'),
    )
  }
}

async function downloadHealth(overview: PayrollHealthPaymentOverview) {
  healthError.value = ''
  downloadingHealthKey.value = healthOverviewKey(overview)
  try {
    await payrollApi.downloadHealthPaymentOverview(overview)
  } catch (exception) {
    healthError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.health_download_failed'),
    )
  } finally {
    downloadingHealthKey.value = null
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await payrollApi.submissionOverview(
      environment.value,
      period.value,
    )
    allItems.value = response.items
    await loadHealthOverviews()
  } catch (exception) {
    allItems.value = []
    healthOverviews.value = []
    error.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

watch([environment, period, () => props.mode], load)
onMounted(load)
</script>

<template>
  <section class="space-y-4">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-lg font-semibold text-neutral-900">
              {{ t(`payroll.submissions.${mode}_title`) }}
            </h2>
            <span class="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-700">
              {{ t('payroll.submissions.overview.transport_unavailable') }}
            </span>
          </div>
          <p class="mt-2 text-sm text-neutral-600">
            {{ t(`payroll.submissions.${mode}_fail_closed`) }}
          </p>
        </div>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>

      <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.overview.period') }}
          <input
            v-model="period"
            type="month"
            class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
            data-test="submission-overview-period"
          >
        </label>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.overview.environment') }}
          <SearchableSelect
            v-model="environment"
            class="mt-1"
            :options="environmentOptions"
            :clearable="false"
            accent="payroll"
            data-test="submission-overview-environment"
          />
        </label>
      </div>
    </div>

    <p
      v-if="error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="submission-overview-error"
    >
      {{ error }}
    </p>

    <div v-if="loading" class="grid grid-cols-2 gap-3 lg:grid-cols-5">
      <div v-for="index in 5" :key="index" class="h-20 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else>
      <dl class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div
          v-for="entry in (['total', 'open', 'submitted', 'fulfilled', 'attention'] as const)"
          :key="entry"
          class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm"
        >
          <dt class="text-xs font-medium text-neutral-500">
            {{ t(`payroll.submissions.overview.summary.${entry}`) }}
          </dt>
          <dd class="mt-1 text-2xl font-semibold text-neutral-900">
            {{ counts[entry] }}
          </dd>
        </div>
      </dl>

      <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div v-if="items.length === 0" class="p-6 text-sm text-neutral-500">
          {{ t('payroll.submissions.overview.empty') }}
        </div>

        <div v-else class="hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-4 py-3">{{ t('payroll.submissions.overview.agenda') }}</th>
                <th class="px-4 py-3">{{ t('payroll.submissions.overview.subject') }}</th>
                <th class="px-4 py-3">{{ t('payroll.submissions.overview.due_on') }}</th>
                <th class="px-4 py-3">{{ t('payroll.submissions.overview.channel_label') }}</th>
                <th class="px-4 py-3">{{ t('payroll.submissions.overview.status_label') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in items" :key="item.id">
                <td class="px-4 py-3 font-medium text-neutral-900">{{ item.agenda_code }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ item.subject_reference }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ item.due_on }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ channelLabel(item.preferred_channel) }}</td>
                <td class="px-4 py-3">
                  <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(item.status)">
                    {{ statusLabel(item.status) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="items.length" class="grid grid-cols-1 gap-3 p-4 md:hidden">
          <article v-for="item in items" :key="item.id" class="rounded-lg border border-neutral-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h3 class="font-semibold text-neutral-900">{{ item.agenda_code }}</h3>
                <p class="mt-1 text-xs text-neutral-500">{{ item.subject_reference }}</p>
              </div>
              <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(item.status)">
                {{ statusLabel(item.status) }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.overview.due_on') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ item.due_on }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.overview.channel_label') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ channelLabel(item.preferred_channel) }}</dd>
              </div>
            </dl>
          </article>
        </div>
      </section>

      <section
        v-if="mode === 'health'"
        class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
        data-test="health-payment-overviews"
      >
        <div class="border-b border-neutral-200 p-4 sm:p-6">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.overview.health_title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.submissions.overview.health_description') }}
          </p>
        </div>

        <p
          v-if="healthError"
          class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="health-overview-error"
        >
          {{ healthError }}
        </p>

        <div v-if="healthOverviews.length === 0 && !healthError" class="p-6 text-sm text-neutral-500">
          {{ t('payroll.submissions.overview.health_empty') }}
        </div>

        <div v-else-if="healthOverviews.length" class="grid grid-cols-1 gap-3 p-4 lg:grid-cols-2">
          <article
            v-for="overview in healthOverviews"
            :key="healthOverviewKey(overview)"
            class="rounded-lg border border-neutral-200 p-4"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 class="font-semibold text-neutral-900">
                  {{ t('payroll.submissions.overview.health_insurer', { code: overview.insurer.code }) }}
                </h3>
                <p class="mt-1 text-xs text-neutral-500">
                  {{ overview.period }} ·
                  {{ t('payroll.submissions.overview.health_people', { count: overview.totals.person_count }) }} ·
                  {{ t('payroll.submissions.overview.health_run_revision', {
                    run: overview.run_id,
                    revision: overview.revision_no,
                  }) }}
                </p>
              </div>
              <button
                type="button"
                :class="btnOutlineSm('neutral')"
                :disabled="downloadingHealthKey === healthOverviewKey(overview)"
                @click="downloadHealth(overview)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.download" />
                </svg>
                {{ t('common.download') }}
              </button>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.overview.health_base') }}</dt>
                <dd class="mt-0.5 font-medium text-neutral-900">
                  {{ formatMinor(overview.totals.assessment_base_minor_units) }}
                </dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.overview.health_total') }}</dt>
                <dd class="mt-0.5 font-medium text-neutral-900">
                  {{ formatMinor(overview.totals.total_contribution_minor_units) }}
                </dd>
              </div>
            </dl>
          </article>
        </div>
      </section>
    </template>
  </section>
</template>
