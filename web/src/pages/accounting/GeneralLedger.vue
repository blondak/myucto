<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import {
  accountingApi,
  type AccountingPeriod,
  type GeneralLedgerReport,
  type GeneralLedgerAccount,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters } from '@/composables/useSavedFilters'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import ActivationBanner from '@/components/settings/activation/ActivationBanner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()

const periods = ref<AccountingPeriod[]>([])
const report = ref<GeneralLedgerReport | null>(null)
const loading = ref(false)

const filters = reactive({
  period_id: '' as number | '',
  from: '',
  to: '',
  analytics: false,
  after_closing: false,
  // Hledání dle protistrany/položky zdrojového dokladu (§ hlavní kniha, vzor Journal.vue).
  vendor: '',
  client: '',
  item: '',
})

function queryParams() {
  return {
    period_id: Number(filters.period_id),
    from: filters.from || undefined,
    to: filters.to || undefined,
    analytics: filters.analytics ? (1 as const) : undefined,
    after_closing: filters.after_closing ? (1 as const) : undefined,
    vendor: filters.vendor || undefined,
    client: filters.client || undefined,
    item: filters.item || undefined,
  }
}

function resetFilters() {
  filters.vendor = ''
  filters.client = ''
  filters.item = ''
  load()
}

async function load() {
  if (!filters.period_id) return
  loading.value = true
  expandedId.value = null
  try {
    report.value = await accountingApi.getGeneralLedger(queryParams())
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

const expandedId = ref<number | null>(null)
function toggleExpand(a: GeneralLedgerAccount) {
  expandedId.value = expandedId.value === a.account_id ? null : a.account_id
}

function statementLink(a: GeneralLedgerAccount) {
  return {
    name: 'accounting-account-statement',
    params: { accountId: a.account_id },
    query: { from: report.value?.from, to: report.value?.to },
  }
}

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!filters.period_id || !report.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/general-ledger/export', { ...queryParams(), format })
    downloadBlob(r.data as unknown as Blob, `hlavni-kniha-${report.value.period.fiscal_year}.${format}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.period_id !== '') q.period_id = String(filters.period_id)
  if (filters.from) q.from = filters.from
  if (filters.to) q.to = filters.to
  if (filters.analytics) q.analytics = '1'
  if (filters.vendor) q.vendor = filters.vendor
  if (filters.client) q.client = filters.client
  if (filters.item) q.item = filters.item
  return q
}

function applyQueryToPage(q: Record<string, string>) {
  filters.period_id = q.period_id ? Number(q.period_id) : ''
  filters.from = q.from ?? ''
  filters.to = q.to ?? ''
  filters.analytics = q.analytics === '1'
  filters.vendor = q.vendor ?? ''
  filters.client = q.client ?? ''
  filters.item = q.item ?? ''
  load()
}

const COLUMNS: ColumnDef[] = [
  { key: 'account', labelKey: 'accounting.general_ledger.col_account', required: true },
  { key: 'name', labelKey: 'accounting.general_ledger.col_name', required: true },
  { key: 'account_type', labelKey: 'accounting.general_ledger.col_type', defaultHidden: true },
  { key: 'synthetic', labelKey: 'accounting.general_ledger.col_synthetic', defaultHidden: true },
  { key: 'opening_md', labelKey: 'accounting.general_ledger.col_ps_md' },
  { key: 'opening_d', labelKey: 'accounting.general_ledger.col_ps_d' },
  { key: 'turnover_md', labelKey: 'accounting.general_ledger.col_turnover_md' },
  { key: 'turnover_d', labelKey: 'accounting.general_ledger.col_turnover_d' },
  { key: 'closing_md', labelKey: 'accounting.general_ledger.col_ks_md' },
  { key: 'closing_d', labelKey: 'accounting.general_ledger.col_ks_d' },
]
const tbl = useTablePrefs('general_ledger', COLUMNS)
const saved = useSavedFilters('general_ledger', { getQuery: buildQuery, applyQuery: applyQueryToPage })
const visibleColCount = computed(() => 1 + tbl.columns.filter(c => tbl.isVisible(c.key)).length)

onMounted(async () => {
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  const open = periods.value.filter(p => p.status === 'open')
  const def = open.length
    ? open.reduce((a, b) => (b.fiscal_year > a.fiscal_year ? b : a))
    : periods.value[0]
  if (def) {
    filters.period_id = def.id
    await load()
  }
})
</script>

<template>
  <div>
    <ActivationBanner />
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.general_ledger.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.general_ledger.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.general_ledger.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.general_ledger.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_period') }}</label>
          <select v-model="filters.period_id" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_from') }}</label>
          <input v-model="filters.from" type="date" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_to') }}</label>
          <input v-model="filters.to" type="date" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div class="flex items-end pb-2">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="filters.analytics" type="checkbox" @change="load" class="rounded border-neutral-300" />
            {{ t('accounting.general_ledger.filter_analytics') }}
          </label>
        </div>
        <!-- Výchozí je stav PŘED uzavřením knih; po uzavření jsou rozvahové účty
             převedené na 702 a konečné stavy vyjdou nulové. -->
        <div class="flex items-end pb-2">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="filters.after_closing" type="checkbox" @change="load" class="rounded border-neutral-300" />
            {{ t('accounting.reports.after_closing') }}
          </label>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_vendor') }}</label>
          <input v-model.trim="filters.vendor" type="search" @keyup.enter="load" @search="load"
            :placeholder="t('accounting.general_ledger.filter_vendor_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_client') }}</label>
          <input v-model.trim="filters.client" type="search" @keyup.enter="load" @search="load"
            :placeholder="t('accounting.general_ledger.filter_client_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_item') }}</label>
          <input v-model.trim="filters.item" type="search" @keyup.enter="load" @search="load"
            :placeholder="t('accounting.general_ledger.filter_item_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <div class="flex flex-wrap items-center justify-end gap-2 mt-2">
        <button @click="resetFilters" class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700">{{ t('accounting.general_ledger.reset_filters') }}</button>
      </div>
    </div>

    <div v-if="report && report.draft_count > 0"
      class="mb-4 px-3 py-2 rounded-md bg-warning-50 border border-warning-500/30 text-warning-600 text-sm">
      {{ t('accounting.general_ledger.draft_warning', { n: report.draft_count }) }}
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report || report.accounts.length === 0" boxed accent="neutral" icon="chart" :title="t('accounting.general_ledger.empty')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 w-8"></th>
              <th v-if="tbl.isVisible('account')" class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.general_ledger.col_account') }}</th>
              <th v-if="tbl.isVisible('name')" class="px-3 py-2 text-left font-medium">{{ t('accounting.general_ledger.col_name') }}</th>
              <th v-if="tbl.isVisible('account_type')" class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.general_ledger.col_type') }}</th>
              <th v-if="tbl.isVisible('synthetic')" class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.general_ledger.col_synthetic') }}</th>
              <th v-if="tbl.isVisible('opening_md')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_ps_md') }}</th>
              <th v-if="tbl.isVisible('opening_d')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_ps_d') }}</th>
              <th v-if="tbl.isVisible('turnover_md')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_turnover_md') }}</th>
              <th v-if="tbl.isVisible('turnover_d')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_turnover_d') }}</th>
              <th v-if="tbl.isVisible('closing_md')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_ks_md') }}</th>
              <th v-if="tbl.isVisible('closing_d')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_ks_d') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="a in report.accounts" :key="a.account_id">
              <tr class="cursor-pointer hover:bg-neutral-50" @click="toggleExpand(a)">
                <td class="px-3 py-2 text-neutral-400">
                  <span class="inline-block transition-transform" :class="{ 'rotate-90': expandedId === a.account_id }">▸</span>
                </td>
                <td v-if="tbl.isVisible('account')" class="px-3 py-2">
                  <RouterLink :to="statementLink(a)" @click.stop
                    class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                    {{ a.account_code }}
                  </RouterLink>
                </td>
                <td v-if="tbl.isVisible('name')" class="px-3 py-2">{{ a.name }}</td>
                <td v-if="tbl.isVisible('account_type')" class="px-3 py-2 text-neutral-600 whitespace-nowrap">{{ t(`accounting.accounts.type.${a.account_type}`) }}</td>
                <td v-if="tbl.isVisible('synthetic')" class="px-3 py-2 text-neutral-600 whitespace-nowrap">
                  {{ a.is_synthetic ? t('accounting.general_ledger.synthetic') : t('accounting.general_ledger.analytic') }}
                </td>
                <td v-if="tbl.isVisible('opening_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.opening_md) }}</td>
                <td v-if="tbl.isVisible('opening_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.opening_d) }}</td>
                <td v-if="tbl.isVisible('turnover_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.turnover_md) }}</td>
                <td v-if="tbl.isVisible('turnover_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.turnover_d) }}</td>
                <td v-if="tbl.isVisible('closing_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.closing_md) }}</td>
                <td v-if="tbl.isVisible('closing_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.closing_d) }}</td>
              </tr>
              <tr v-if="expandedId === a.account_id">
                <td :colspan="visibleColCount" class="px-3 py-3 bg-neutral-50">
                  <div class="text-xs text-neutral-500 uppercase tracking-wide font-medium mb-2">
                    {{ t('accounting.general_ledger.months_detail') }}
                  </div>
                  <table class="w-full max-w-xl text-sm">
                    <thead class="text-xs text-neutral-500 uppercase tracking-wide">
                      <tr>
                        <th class="px-2 py-1 text-left font-medium">{{ t('accounting.general_ledger.col_month') }}</th>
                        <th class="px-2 py-1 text-right font-medium w-40">{{ t('accounting.general_ledger.col_turnover_md') }}</th>
                        <th class="px-2 py-1 text-right font-medium w-40">{{ t('accounting.general_ledger.col_turnover_d') }}</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                      <tr v-for="m in report.months" :key="m">
                        <td class="px-2 py-1 font-mono">{{ m }}</td>
                        <td class="px-2 py-1 text-right font-mono">{{ formatMoney(a.months[m]?.md ?? 0) }}</td>
                        <td class="px-2 py-1 text-right font-mono">{{ formatMoney(a.months[m]?.d ?? 0) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-neutral-300 font-semibold bg-neutral-50">
              <td class="px-3 py-2" colspan="3">{{ t('accounting.general_ledger.totals') }}</td>
              <td v-if="tbl.isVisible('account_type')" class="px-3 py-2"></td>
              <td v-if="tbl.isVisible('synthetic')" class="px-3 py-2"></td>
              <td v-if="tbl.isVisible('opening_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.opening_md) }}</td>
              <td v-if="tbl.isVisible('opening_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.opening_d) }}</td>
              <td v-if="tbl.isVisible('turnover_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.turnover_md) }}</td>
              <td v-if="tbl.isVisible('turnover_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.turnover_d) }}</td>
              <td v-if="tbl.isVisible('closing_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.closing_md) }}</td>
              <td v-if="tbl.isVisible('closing_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.closing_d) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>
