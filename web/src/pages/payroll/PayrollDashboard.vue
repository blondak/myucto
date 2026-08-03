<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollCapabilitiesResponse } from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const capabilities = ref<PayrollCapabilitiesResponse | null>(null)
const startPeriod = ref(new Date().toISOString().slice(0, 7))

const state = computed(() => capabilities.value?.state ?? null)
const canConfigure = computed(() => auth.canWrite('payroll.settings'))
const isEnabled = computed(() => state.value?.status !== 'disabled')
const availableFeatures = computed(() =>
  capabilities.value?.support_matrix.features.filter(feature => feature.available) ?? [],
)
const plannedFeatures = computed(() =>
  capabilities.value?.support_matrix.features.filter(feature => !feature.available) ?? [],
)

async function load() {
  loading.value = true
  try {
    const data = await payrollApi.capabilities()
    capabilities.value = data
    if (data.state.start_period) {
      startPeriod.value = data.state.start_period
    }
  } catch {
    toast.error(t('payroll.load_failed'))
  } finally {
    loading.value = false
  }
}

async function enable() {
  if (!state.value || !startPeriod.value) return
  saving.value = true
  try {
    const updated = await payrollApi.setActivation({
      enabled: true,
      start_period: startPeriod.value,
      row_version: state.value.row_version,
    })
    if (capabilities.value) capabilities.value.state = updated
    toast.success(t('payroll.activation.enabled'))
  } catch (error: any) {
    if (error?.response?.data?.error?.code === 'row_version_conflict') {
      toast.warning(t('payroll.activation.conflict'))
      await load()
    } else {
      toast.error(error?.response?.data?.error?.message || t('payroll.activation.failed'))
    }
  } finally {
    saving.value = false
  }
}

async function disableSetup() {
  if (!state.value || state.value.status !== 'setup') return
  if (!window.confirm(t('payroll.activation.disable_confirm'))) return
  saving.value = true
  try {
    const updated = await payrollApi.setActivation({
      enabled: false,
      start_period: null,
      row_version: state.value.row_version,
    })
    if (capabilities.value) capabilities.value.state = updated
    toast.success(t('payroll.activation.disabled'))
  } catch (error: any) {
    if (error?.response?.data?.error?.code === 'row_version_conflict') {
      toast.warning(t('payroll.activation.conflict'))
      await load()
    } else {
      toast.error(error?.response?.data?.error?.message || t('payroll.activation.failed'))
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.title') }}</h1>
          <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.subtitle') }}</p>
        </div>
        <span
          v-if="state"
          class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium"
          :class="isEnabled ? 'bg-payroll-50 text-payroll-600' : 'bg-neutral-100 text-neutral-600'"
        >
          {{ t(`payroll.status.${state.status}`) }}
        </span>
      </div>
    </header>

    <div v-if="loading" class="grid grid-cols-1 gap-4 md:grid-cols-3">
      <div v-for="index in 3" :key="index" class="h-32 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else-if="capabilities && state">
      <section
        v-if="!isEnabled"
        class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6"
      >
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
          <div class="max-w-2xl">
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.activation.title') }}</h2>
            <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.activation.description') }}</p>
          </div>
          <div v-if="canConfigure" class="flex flex-wrap items-end gap-3">
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-neutral-600">
                {{ t('payroll.activation.start_period') }}
              </span>
              <input
                v-model="startPeriod"
                type="month"
                min="2024-01"
                class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
              >
            </label>
            <button :class="btnFilled('primary')" :disabled="saving || !startPeriod" @click="enable">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.play" />
              </svg>
              {{ saving ? t('common.saving') : t('payroll.activation.enable') }}
            </button>
          </div>
        </div>
      </section>

      <div v-else class="space-y-3">
        <div v-if="state.status === 'setup' && canConfigure" class="flex flex-wrap justify-end gap-2">
          <button :class="btnOutline('warning')" :disabled="saving" @click="disableSetup">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path :d="ICONS.uturn" />
            </svg>
            {{ t('payroll.activation.disable') }}
          </button>
        </div>
        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
              {{ t('payroll.dashboard.start_period') }}
            </p>
            <p class="mt-2 text-xl font-semibold text-neutral-900">{{ state.start_period }}</p>
          </article>
          <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
              {{ t('payroll.dashboard.supported_years') }}
            </p>
            <p class="mt-2 text-xl font-semibold text-neutral-900">
              {{ capabilities.support_matrix.supported_years.join(', ') }}
            </p>
          </article>
          <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
              {{ t('payroll.dashboard.available_features') }}
            </p>
            <p class="mt-2 text-xl font-semibold text-neutral-900">{{ availableFeatures.length }}</p>
          </article>
          <article class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
              {{ t('payroll.dashboard.matrix_version') }}
            </p>
            <p class="mt-2 break-all text-sm font-semibold text-neutral-900">
              {{ capabilities.support_matrix.version }}
            </p>
          </article>
        </section>
      </div>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.capabilities.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.capabilities.description') }}</p>

        <div class="mt-4 hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-3 py-2">{{ t('payroll.capabilities.feature') }}</th>
                <th class="px-3 py-2">{{ t('payroll.capabilities.status') }}</th>
                <th class="px-3 py-2">{{ t('payroll.capabilities.availability') }}</th>
                <th class="px-3 py-2">{{ t('payroll.capabilities.epic') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="feature in capabilities.support_matrix.features" :key="feature.key">
                <td class="px-3 py-3 font-medium text-neutral-900">
                  {{ t(`payroll.features.${feature.key}`) }}
                </td>
                <td class="px-3 py-3 text-neutral-600">
                  {{ t(`payroll.support_status.${feature.status}`) }}
                </td>
                <td class="px-3 py-3">
                  <span
                    class="rounded-full px-2 py-1 text-xs font-medium"
                    :class="feature.available ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'"
                  >
                    {{ t(feature.available ? 'payroll.capabilities.available' : 'payroll.capabilities.planned') }}
                  </span>
                </td>
                <td class="px-3 py-3 text-neutral-500">{{ feature.min_epic }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-3 md:hidden">
          <article
            v-for="feature in capabilities.support_matrix.features"
            :key="feature.key"
            class="rounded-lg border border-neutral-200 p-3"
          >
            <div class="flex items-start justify-between gap-3">
              <h3 class="font-medium text-neutral-900">{{ t(`payroll.features.${feature.key}`) }}</h3>
              <span
                class="shrink-0 rounded-full px-2 py-1 text-xs font-medium"
                :class="feature.available ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'"
              >
                {{ t(feature.available ? 'payroll.capabilities.available' : 'payroll.capabilities.planned') }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.capabilities.status') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ t(`payroll.support_status.${feature.status}`) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.capabilities.epic') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ feature.min_epic }}</dd>
              </div>
            </dl>
          </article>
        </div>

        <p v-if="plannedFeatures.length" class="mt-4 text-xs text-neutral-500">
          {{ t('payroll.capabilities.planned_hint') }}
        </p>
      </section>
    </template>
  </div>
</template>
