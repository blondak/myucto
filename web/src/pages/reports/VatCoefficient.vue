<script setup lang="ts">
/**
 * § 76 ZDPH — koeficient krácení nároku na odpočet.
 *
 * Zálohový koeficient (§ 76/6) se uplatňuje na ř. 52 DPHDP3 každého zdaňovacího
 * období roku; když není nastaven, přenáší se vypořádací koeficient minulého roku.
 * Vypořádací koeficient (§ 76/7) se počítá ze skutečných dat celého roku a ukládá
 * se VÝHRADNĚ explicitní akcí „Provést roční vypořádání" — nikdy jako vedlejší
 * efekt náhledu přiznání. Vypořádání patří do přiznání za poslední období roku.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi, type VatCoefficientStatus } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const year = ref(new Date().getFullYear())
const yearOptions = useYearOptions('combined', year)

const status = ref<VatCoefficientStatus | null>(null)
const loading = ref(false)
const error = ref('')

const canWrite = computed(() => auth.canWrite('reports'))
const canSettle = computed(() => auth.canWrite('reports.finalize'))

const provisionalInput = ref<number | null>(null)
const savingProvisional = ref(false)
const settling = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    status.value = await reportsApi.vatCoefficient(year.value)
    provisionalInput.value = status.value.provisional_percent
  } catch (e) {
    error.value = apiErrorMessage(e)
    status.value = null
  } finally {
    loading.value = false
  }
}

async function saveProvisional() {
  if (provisionalInput.value === null || provisionalInput.value < 0 || provisionalInput.value > 100) {
    toast.error(t('reports.vatCoefficient.provisional_invalid'))
    return
  }
  savingProvisional.value = true
  try {
    await reportsApi.vatCoefficientSet(year.value, provisionalInput.value)
    toast.success(t('reports.vatCoefficient.provisional_saved'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    savingProvisional.value = false
  }
}

async function settle() {
  if (!confirm(t('reports.vatCoefficient.settle_confirm', { year: year.value }))) return
  settling.value = true
  try {
    const r = await reportsApi.vatCoefficientSettle(year.value)
    toast.success(t('reports.vatCoefficient.settle_done', { percent: r.final_percent }))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    settling.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'reload', label: t('common.refresh'), icon: 'chart',
    tier: 'primary', variant: 'primary',
    show: auth.canRead('reports'), disabled: loading.value,
    loading: loading.value, run: load,
  },
  {
    key: 'settle', label: t('reports.vatCoefficient.action_settle'), icon: 'check',
    tier: 'secondary', variant: 'warning',
    show: canSettle.value, disabled: loading.value || settling.value,
    loading: settling.value,
    title: t('reports.vatCoefficient.action_settle_hint'), run: settle,
  },
])

function fmtMoney(v: number | null): string {
  if (v === null) return '—'
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

watch(year, load)
onMounted(load)
</script>

<template>
  <div class="max-w-3xl">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.vatCoefficient.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.vatCoefficient.subtitle') }}</p>
      </div>
      <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
      </select>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('reports.vatCoefficient.explainer_title') }}</p>
      <p>{{ t('reports.vatCoefficient.explainer_body') }}</p>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <div v-else-if="status" class="space-y-4">
      <!-- Zálohový koeficient (§ 76/6) -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-1">{{ t('reports.vatCoefficient.provisional_title') }}</h2>
        <p class="text-xs text-neutral-500 mb-3">{{ t('reports.vatCoefficient.provisional_hint') }}</p>

        <div class="flex items-end gap-3 flex-wrap">
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('reports.vatCoefficient.provisional_label') }}</label>
            <div class="flex items-center gap-2">
              <input v-model.number="provisionalInput" type="number" min="0" max="100" step="1" :disabled="!canWrite"
                     class="w-24 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface disabled:bg-neutral-50" />
              <span class="text-sm text-neutral-500">%</span>
            </div>
          </div>
          <button v-if="canWrite" type="button" :disabled="savingProvisional || provisionalInput === status.provisional_percent"
                  class="h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white rounded-md text-sm disabled:opacity-50 cursor-pointer"
                  @click="saveProvisional">
            {{ savingProvisional ? t('common.saving') : t('common.save') }}
          </button>
        </div>

        <div class="mt-3 text-sm">
          <span class="text-neutral-500">{{ t('reports.vatCoefficient.resolved_label') }}:</span>
          <strong class="font-mono ml-1">
            {{ status.resolved_provisional_percent !== null ? status.resolved_provisional_percent + ' %' : t('reports.vatCoefficient.resolved_none') }}
          </strong>
          <span v-if="status.carried_forward" class="ml-2 inline-block text-[10px] font-bold px-1.5 py-px rounded bg-neutral-100 text-neutral-600">
            {{ t('reports.vatCoefficient.carried_forward') }}
          </span>
        </div>
      </div>

      <!-- Vypořádací koeficient (§ 76/7) -->
      <div class="bg-surface border rounded-lg shadow-sm p-4"
           :class="status.final_percent !== null ? 'border-success-500/40' : 'border-neutral-200'">
        <h2 class="text-sm font-semibold mb-1">{{ t('reports.vatCoefficient.final_title') }}</h2>
        <p class="text-xs text-neutral-500 mb-3">{{ t('reports.vatCoefficient.final_hint') }}</p>

        <EmptyState v-if="status.final_percent === null" dense accent="neutral" icon="chart" :title="t('reports.vatCoefficient.not_settled')" />
        <table v-else class="text-sm">
          <tbody>
            <tr>
              <td class="pr-6 py-1 text-neutral-500">{{ t('reports.vatCoefficient.final_label') }}</td>
              <td class="py-1 font-mono font-bold text-lg">{{ status.final_percent }} %</td>
            </tr>
            <tr>
              <td class="pr-6 py-1 text-neutral-500">{{ t('reports.vatCoefficient.numerator') }}</td>
              <td class="py-1 font-mono text-right">{{ fmtMoney(status.numerator_czk) }} Kč</td>
            </tr>
            <tr>
              <td class="pr-6 py-1 text-neutral-500">{{ t('reports.vatCoefficient.denominator') }}</td>
              <td class="py-1 font-mono text-right">{{ fmtMoney(status.denominator_czk) }} Kč</td>
            </tr>
            <tr>
              <td class="pr-6 py-1 text-neutral-500">{{ t('reports.vatCoefficient.settled_at') }}</td>
              <td class="py-1 font-mono">{{ fmtDate(status.settled_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
