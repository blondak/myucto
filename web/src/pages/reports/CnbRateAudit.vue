<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi, type CnbRateAuditResult, type CnbRateAuditItem } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'

const { t, locale } = useI18n()

const now = new Date()
const year = ref(now.getFullYear())
const threshold = ref(0.5)

const result = ref<CnbRateAuditResult | null>(null)
const loading = ref(false)
const error = ref('')

const yearOptions = useYearOptions('combined', year)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const from = `${year.value}-01-01`
    const to = `${year.value}-12-31`
    result.value = await reportsApi.cnbRateAudit(from, to, threshold.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function fmtRate(v: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 3, maximumFractionDigits: 4,
  }).format(Number(v) || 0)
}

function fmtMoney(v: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}

function fmtPct(v: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2, maximumFractionDigits: 3,
  }).format(Number(v) || 0)
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (isNaN(d.getTime())) return ''
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function docLink(item: CnbRateAuditItem): string {
  return item.doc_type === 'purchase_invoice'
    ? `/purchase-invoices/${item.doc_id}`
    : `/invoices/${item.doc_id}`
}

const items = computed(() => result.value?.items ?? [])

watch([year, threshold], load)
onMounted(load)
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.cnb_audit.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.cnb_audit.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <label class="text-sm text-neutral-600 flex items-center gap-1">
          {{ t('reports.cnb_audit.threshold_label') }}
          <input v-model.number="threshold" type="number" min="0" max="100" step="0.1"
            class="h-9 w-20 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
          <span class="text-neutral-400">%</span>
        </label>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="result" class="space-y-4">
      <!-- Pevný režim §24/7 -->
      <div v-if="result.fixed_mode_skipped"
        class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6 text-center text-neutral-600">
        {{ t('reports.cnb_audit.fixed_mode') }}
      </div>

      <template v-else>
        <!-- Souhrn -->
        <div class="flex gap-3 flex-wrap text-sm">
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm px-4 py-3">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.cnb_audit.found_label') }}</div>
            <div class="text-lg font-semibold" :class="items.length > 0 ? 'text-danger-500' : 'text-emerald-600'">{{ items.length }}</div>
          </div>
          <div v-if="result.missing_cnb_count > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm px-4 py-3">
            <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.cnb_audit.missing_label') }}</div>
            <div class="text-lg font-semibold text-neutral-700">{{ result.missing_cnb_count }}</div>
          </div>
        </div>

        <!-- Bez nálezů -->
        <div v-if="items.length === 0"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-500">
          {{ t('reports.cnb_audit.no_data') }}
        </div>

        <!-- Tabulka nálezů -->
        <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-neutral-200 bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-4 py-2 font-medium">{{ t('reports.cnb_audit.col_doc') }}</th>
                <th class="px-4 py-2 font-medium">{{ t('reports.cnb_audit.col_date') }}</th>
                <th class="px-4 py-2 font-medium">{{ t('reports.cnb_audit.col_currency') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('reports.cnb_audit.col_used_rate') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('reports.cnb_audit.col_cnb_rate') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('reports.cnb_audit.col_diff') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('reports.cnb_audit.col_impact') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in items" :key="`${item.doc_type}-${item.doc_id}`"
                class="border-b border-neutral-100 hover:bg-neutral-50">
                <td class="px-4 py-2">
                  <router-link :to="docLink(item)" class="text-primary-600 hover:underline font-mono">
                    {{ item.doc_no || `#${item.doc_id}` }}
                  </router-link>
                  <span class="ml-1 text-xs text-neutral-400">
                    {{ item.doc_type === 'purchase_invoice' ? t('reports.cnb_audit.kind_purchase') : t('reports.cnb_audit.kind_sale') }}
                  </span>
                </td>
                <td class="px-4 py-2 text-neutral-600">{{ fmtDate(item.date) }}</td>
                <td class="px-4 py-2 font-mono">{{ item.currency }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ fmtRate(item.used_rate) }}</td>
                <td class="px-4 py-2 text-right font-mono text-neutral-500">
                  {{ fmtRate(item.cnb_rate) }}
                  <span class="block text-[11px] text-neutral-400">{{ fmtDate(item.cnb_rate_date) }}</span>
                </td>
                <td class="px-4 py-2 text-right font-mono"
                  :class="Math.abs(item.diff_percent) >= 0.5 ? 'text-danger-500 font-semibold' : 'text-neutral-700'">
                  {{ item.diff_percent > 0 ? '+' : '' }}{{ fmtPct(item.diff_percent) }} %
                </td>
                <td class="px-4 py-2 text-right font-mono"
                  :class="item.impact_czk >= 0 ? 'text-emerald-600' : 'text-danger-500'">
                  {{ item.impact_czk > 0 ? '+' : '' }}{{ fmtMoney(item.impact_czk) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="text-xs text-neutral-400">{{ t('reports.cnb_audit.note') }}</p>
      </template>
    </div>
  </div>
</template>
