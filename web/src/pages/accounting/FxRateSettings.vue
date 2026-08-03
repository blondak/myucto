<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type FxRateMode, type FixedRate } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

// embedded = vykresleno jako záložka uvnitř ToolsPage.vue (Nástroje); hlavičku dodává obálka.
defineProps<{ embedded?: boolean }>()

const { t } = useI18n()
const toast = useToast()

const mode = ref<FxRateMode>('daily')
const rates = ref<FixedRate[]>([])
const loading = ref(false)
const busy = ref(false)

const MODES: FxRateMode[] = ['daily', 'fixed_monthly', 'fixed_annual']
const MONTHS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]

const isFixed = computed(() => mode.value === 'fixed_monthly' || mode.value === 'fixed_annual')

const form = reactive({
  currency: 'EUR',
  fiscal_year: new Date().getFullYear(),
  month: 1,
  rate: 0 as number,
})

async function load() {
  loading.value = true
  try {
    const s = await accountingApi.getFxRateSettings()
    mode.value = s.mode
    rates.value = s.rates
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function changeMode(m: FxRateMode) {
  busy.value = true
  try {
    await accountingApi.setFxRateMode(m)
    mode.value = m
    toast.success(t('accounting.fx_rates.mode_saved'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

function effectiveMonth() {
  return mode.value === 'fixed_annual' ? 0 : form.month
}

async function prefill() {
  busy.value = true
  try {
    const r = await accountingApi.cnbPrefillRate(form.currency.toUpperCase(), form.fiscal_year, effectiveMonth())
    form.rate = r.rate
    toast.success(t('accounting.fx_rates.prefilled', { date: r.rate_date }))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function saveRate() {
  if (!form.currency || form.rate <= 0) return
  busy.value = true
  try {
    rates.value = await accountingApi.upsertFixedRate({
      currency: form.currency.toUpperCase(),
      fiscal_year: form.fiscal_year,
      month: effectiveMonth(),
      rate: form.rate,
    })
    toast.success(t('accounting.fx_rates.rate_saved'))
    form.rate = 0
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function removeRate(r: FixedRate) {
  if (!window.confirm(t('accounting.fx_rates.delete_confirm'))) return
  busy.value = true
  try {
    await accountingApi.deleteFixedRate(r.id)
    rates.value = rates.value.filter(x => x.id !== r.id)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

function periodLabel(r: FixedRate) {
  return r.month === 0
    ? t('accounting.fx_rates.annual_label', { year: r.fiscal_year })
    : `${r.fiscal_year}-${String(r.month).padStart(2, '0')}`
}

onMounted(load)
</script>

<template>
  <div>
    <div v-if="!embedded" class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('accounting.fx_rates.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.fx_rates.subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else class="space-y-6">
      <!-- Režim -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-base font-semibold mb-1">{{ t('accounting.fx_rates.mode_title') }}</h2>
        <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.fx_rates.mode_hint') }}</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="m in MODES" :key="m" :disabled="busy" @click="changeMode(m)"
            :class="mode === m ? btnFilled('primary') : btnOutline('neutral')">
            {{ t(`accounting.fx_rates.mode_${m}`) }}
          </button>
        </div>
      </div>

      <!-- Pevné kurzy -->
      <div v-if="isFixed" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-base font-semibold mb-3">{{ t('accounting.fx_rates.rates_title') }}</h2>

        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 items-end mb-4">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.fx_rates.currency') }}</label>
            <input v-model="form.currency" type="text" maxlength="3"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm uppercase" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.fx_rates.year') }}</label>
            <input v-model.number="form.fiscal_year" type="number" min="2000" max="2100"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div v-if="mode === 'fixed_monthly'">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.fx_rates.month') }}</label>
            <select v-model.number="form.month" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option v-for="mo in MONTHS" :key="mo" :value="mo">{{ mo }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.fx_rates.rate') }}</label>
            <div class="flex gap-1">
              <input v-model.number="form.rate" type="number" step="0.000001" min="0"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
              <button :disabled="busy" @click="prefill" :class="btnOutline('neutral')" :title="t('accounting.fx_rates.prefill')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              </button>
            </div>
          </div>
          <div>
            <button :disabled="busy || form.rate <= 0" @click="saveRate" :class="btnFilled('primary')">
              {{ t('accounting.fx_rates.add') }}
            </button>
          </div>
        </div>

        <EmptyState v-if="rates.length === 0" dense accent="neutral" icon="swap" :title="t('accounting.fx_rates.no_rates')" />
        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.fx_rates.col_currency') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.fx_rates.col_period') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.fx_rates.col_rate') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.fx_rates.col_source') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.fx_rates.col_updated') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in rates" :key="r.id" class="hover:bg-neutral-50">
              <td class="px-3 py-2 font-mono">{{ r.currency_code }}</td>
              <td class="px-3 py-2">{{ periodLabel(r) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ r.rate }}</td>
              <td class="px-3 py-2">{{ t(`accounting.fx_rates.source_${r.source}`) }}</td>
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(r.updated_at) }}</td>
              <td class="px-3 py-2 text-right">
                <button :disabled="busy" @click="removeRate(r)" :class="btnOutline('danger')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
