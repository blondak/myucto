<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollEmployerSettings,
  type PayrollRegzelEnvironment,
  type PayrollRegzelProfile,
  type PayrollRegzelSnapshot,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

type SubmissionTab = 'regzel' | 'jmhz' | 'health'

const { t } = useI18n()
const auth = useAuthStore()
const activeTab = ref<SubmissionTab>('regzel')
const tabs: SubmissionTab[] = ['regzel', 'jmhz', 'health']
const loading = ref(true)
const preparing = ref(false)
const downloadingId = ref<number | null>(null)
const settings = ref<PayrollEmployerSettings | null>(null)
const profile = ref<PayrollRegzelProfile | null>(null)
const snapshots = ref<PayrollRegzelSnapshot[]>([])
const environment = ref<PayrollRegzelEnvironment>('production')
const officeId = ref<number | null>(null)
const evidenceConfirmed = ref(false)
const error = ref('')
const success = ref('')

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const environmentOptions = computed(() => [
  {
    value: 'production' as PayrollRegzelEnvironment,
    label: t('payroll.regzel.environment.production'),
  },
  {
    value: 'test' as PayrollRegzelEnvironment,
    label: t('payroll.regzel.environment.test'),
  },
])
const officeOptions = computed(() =>
  (settings.value?.offices ?? [])
    .filter(office => office.is_active)
    .map(office => ({
      value: office.id,
      label: `${office.code} — ${office.name}`,
      secondary: office.social_security_variable_symbol
        ? t('payroll.regzel.office_vs', { vs: office.social_security_variable_symbol })
        : t('payroll.regzel.office_vs_missing'),
    })),
)
const selectedOffice = computed(() =>
  officeOptions.value.find(option => option.value === officeId.value) ?? null,
)

function officeLabel(id: number): string {
  const office = settings.value?.offices.find(item => item.id === id)
  return office ? `${office.code} — ${office.name}` : `#${id}`
}

function apiMessage(exception: unknown, fallback: string): string {
  if (isAxiosError<{ error?: { message?: string } }>(exception)) {
    return exception.response?.data?.error?.message || fallback
  }
  const response = (exception as { response?: { data?: { error?: { message?: string } } } })
    ?.response
  return response?.data?.error?.message || fallback
}

async function loadSnapshots() {
  error.value = ''
  try {
    snapshots.value = await payrollApi.regzelSnapshots(environment.value)
  } catch (exception: unknown) {
    snapshots.value = []
    error.value = apiMessage(exception, t('payroll.regzel.history.load_failed'))
  }
}

async function load() {
  loading.value = true
  error.value = ''
  success.value = ''
  try {
    const [employerSettings, regzelProfile] = await Promise.all([
      payrollApi.employerSettings(),
      payrollApi.regzelProfile(),
    ])
    settings.value = employerSettings
    profile.value = regzelProfile
    officeId.value = employerSettings.offices.find(office => office.is_active)?.id ?? null
    await loadSnapshots()
  } catch (exception: unknown) {
    error.value = apiMessage(exception, t('payroll.regzel.load_failed'))
  } finally {
    loading.value = false
  }
}

function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `regzel-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

async function prepare() {
  error.value = ''
  success.value = ''
  if (!evidenceConfirmed.value) {
    error.value = t('payroll.regzel.prepare.confirmation_required')
    return
  }
  if (!profile.value) {
    error.value = t('payroll.regzel.prepare.profile_required')
    return
  }
  if (officeId.value === null) {
    error.value = t('payroll.regzel.prepare.office_required')
    return
  }

  preparing.value = true
  try {
    const snapshot = await payrollApi.prepareRegzel({
      office_id: officeId.value,
      environment: environment.value,
      evidence_confirmed: true,
      idempotency_key: newIdempotencyKey(),
    })
    evidenceConfirmed.value = false
    success.value = snapshot.created
      ? t('payroll.regzel.prepare.created')
      : t('payroll.regzel.prepare.replayed')
    await loadSnapshots()
  } catch (exception: unknown) {
    error.value = apiMessage(exception, t('payroll.regzel.prepare.failed'))
  } finally {
    preparing.value = false
  }
}

async function download(snapshot: PayrollRegzelSnapshot) {
  error.value = ''
  downloadingId.value = snapshot.id
  try {
    await payrollApi.downloadRegzelSnapshot(snapshot)
  } catch (exception: unknown) {
    error.value = apiMessage(exception, t('payroll.regzel.download_failed'))
  } finally {
    downloadingId.value = null
  }
}

function readableBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  return `${(bytes / 1024).toFixed(1)} kB`
}

watch(environment, async () => {
  evidenceConfirmed.value = false
  success.value = ''
  if (!loading.value) {
    await loadSnapshots()
  }
})

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">
          {{ t('payroll.submissions.title') }}
        </h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.submissions.subtitle') }}
        </p>
      </div>
      <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.refresh') }}
      </button>
    </header>

    <nav
      class="flex flex-wrap gap-1 border-b border-neutral-200"
      role="tablist"
      :aria-label="t('payroll.submissions.tabs_label')"
    >
      <button
        v-for="tab in tabs"
        :key="tab"
        type="button"
        role="tab"
        :aria-selected="activeTab === tab"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab
          ? 'border-payroll-600 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="activeTab = tab"
      >
        {{ t(`payroll.submissions.tabs.${tab}`) }}
      </button>
    </nav>

    <div v-if="loading" class="space-y-4">
      <div class="h-28 animate-pulse rounded-xl bg-neutral-100" />
      <div class="h-64 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else-if="activeTab === 'regzel'">
      <div
        v-if="error"
        data-test="regzel-error"
        class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
      >
        {{ error }}
      </div>
      <div
        v-if="success"
        class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
        role="status"
      >
        {{ success }}
      </div>

      <section
        class="rounded-xl border p-4 sm:p-6"
        :class="environment === 'production'
          ? 'border-warning-500/40 bg-warning-50'
          : 'border-payroll-500/30 bg-payroll-50'"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="max-w-3xl">
            <h2 class="text-lg font-semibold text-neutral-900">
              REGZELDOPL25 1.2
            </h2>
            <p class="mt-1 text-sm text-neutral-600">
              {{ t('payroll.regzel.description') }}
            </p>
          </div>
          <span
            class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase tracking-wide"
            :class="environment === 'production'
              ? 'bg-warning-100 text-warning-800'
              : 'bg-payroll-100 text-payroll-800'"
          >
            {{ t(`payroll.regzel.environment.${environment}`) }}
          </span>
        </div>
        <p class="mt-4 text-sm font-medium text-neutral-800">
          {{ t(`payroll.regzel.environment.${environment}_warning`) }}
        </p>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">
              {{ t('payroll.regzel.prepare.title') }}
            </h2>
            <p class="mt-1 max-w-3xl text-sm text-neutral-500">
              {{ t('payroll.regzel.prepare.description') }}
            </p>
          </div>
          <RouterLink
            :to="{ name: 'payroll-settings', query: { tab: 'submissions' } }"
            :class="btnOutline('neutral')"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.edit" />
            </svg>
            {{ t('payroll.regzel.prepare.open_settings') }}
          </RouterLink>
        </div>

        <div
          v-if="!profile"
          class="mt-5 rounded-lg border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-700"
        >
          {{ t('payroll.regzel.prepare.profile_required') }}
        </div>
        <div
          v-else
          class="mt-5 flex flex-wrap items-center gap-2 text-sm text-neutral-600"
        >
          <svg class="h-5 w-5 text-success-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.checkCircle" />
          </svg>
          {{ t('payroll.regzel.prepare.profile_confirmed', {
            at: profile.evidence_confirmed_at,
            version: profile.row_version,
          }) }}
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.regzel.environment.label') }}
            </span>
            <SearchableSelect
              v-model="environment"
              data-test="regzel-environment"
              :options="environmentOptions"
              :clearable="false"
              accent="payroll"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.regzel.office') }}
            </span>
            <SearchableSelect
              v-model="officeId"
              :options="officeOptions"
              :selected-option="selectedOffice"
              :placeholder="t('payroll.regzel.office_placeholder')"
              :no-results-label="t('payroll.regzel.office_empty')"
              :clearable="false"
              accent="payroll"
            />
          </label>
        </div>

        <label
          v-if="canWrite"
          class="mt-5 flex cursor-pointer items-start gap-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-4"
        >
          <input
            v-model="evidenceConfirmed"
            data-test="regzel-prepare-confirmation"
            type="checkbox"
            class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
          >
          <span class="text-sm text-neutral-700">
            {{ t('payroll.regzel.prepare.confirmation') }}
          </span>
        </label>

        <div class="mt-5 flex flex-wrap justify-end gap-2">
          <button
            v-if="canWrite"
            type="button"
            data-test="regzel-prepare"
            :class="btnFilled('primary')"
            :disabled="preparing || !profile || officeId === null"
            @click="prepare"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.doc" />
            </svg>
            {{ preparing
              ? t('payroll.regzel.prepare.preparing')
              : t('payroll.regzel.prepare.action') }}
          </button>
        </div>
        <p v-if="!canWrite" class="mt-5 text-sm text-neutral-500">
          {{ t('payroll.regzel.read_only') }}
        </p>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div class="border-b border-neutral-200 p-4 sm:p-6">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.regzel.history.title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.regzel.history.description') }}
          </p>
        </div>

        <div v-if="snapshots.length === 0" class="p-6 text-sm text-neutral-500">
          {{ t('payroll.regzel.history.empty') }}
        </div>

        <div v-else class="hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-4 py-3">{{ t('payroll.regzel.history.created_at') }}</th>
                <th class="px-4 py-3">{{ t('payroll.regzel.history.office') }}</th>
                <th class="px-4 py-3">{{ t('payroll.regzel.history.version') }}</th>
                <th class="px-4 py-3">{{ t('payroll.regzel.history.size') }}</th>
                <th class="px-4 py-3 text-right">{{ t('common.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="snapshot in snapshots" :key="snapshot.id">
                <td class="px-4 py-3 text-neutral-900">{{ snapshot.created_at }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ officeLabel(snapshot.office_id) }}</td>
                <td class="px-4 py-3 text-neutral-700">XSD {{ snapshot.xsd_version }}</td>
                <td class="px-4 py-3 text-neutral-700">{{ readableBytes(snapshot.xml_byte_size) }}</td>
                <td class="px-4 py-3 text-right">
                  <button
                    type="button"
                    :class="btnOutlineSm('neutral')"
                    :disabled="downloadingId === snapshot.id"
                    @click="download(snapshot)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.download" />
                    </svg>
                    {{ t('common.download') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="snapshots.length" class="grid grid-cols-1 gap-3 p-4 md:hidden">
          <article
            v-for="snapshot in snapshots"
            :key="snapshot.id"
            class="rounded-lg border border-neutral-200 p-4"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <h3 class="font-medium text-neutral-900">REGZELDOPL25</h3>
                <p class="mt-1 text-xs text-neutral-500">{{ snapshot.created_at }}</p>
              </div>
              <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs text-neutral-700">
                XSD {{ snapshot.xsd_version }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.regzel.history.office') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ officeLabel(snapshot.office_id) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.regzel.history.size') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ readableBytes(snapshot.xml_byte_size) }}</dd>
              </div>
            </dl>
            <button
              type="button"
              class="mt-4"
              :class="btnOutline('neutral')"
              :disabled="downloadingId === snapshot.id"
              @click="download(snapshot)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.download" />
              </svg>
              {{ t('common.download') }}
            </button>
          </article>
        </div>
      </section>
    </template>

    <section
      v-else
      class="rounded-xl border border-neutral-200 bg-surface p-6 shadow-sm"
    >
      <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-600">
        {{ t('payroll.submissions.unsupported') }}
      </span>
      <h2 class="mt-4 text-lg font-semibold text-neutral-900">
        {{ t(`payroll.submissions.${activeTab}_title`) }}
      </h2>
      <p class="mt-2 max-w-3xl text-sm text-neutral-600">
        {{ t(`payroll.submissions.${activeTab}_fail_closed`) }}
      </p>
    </section>
  </div>
</template>
