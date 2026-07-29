<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  taxEvidenceApi,
  type ReceivablesPayablesReport,
  type AgingBucket,
} from '@/api/taxEvidence'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const toast = useToast()

const report = ref<ReceivablesPayablesReport | null>(null)
const loading = ref(false)

const BUCKETS = ['not_due', '1-30', '31-90', '90+'] as const
const BUCKET_KEY: Record<string, string> = {
  'not_due': 'tax_evidence.receivables_payables.bucket_not_due',
  '1-30': 'tax_evidence.receivables_payables.bucket_1_30',
  '31-90': 'tax_evidence.receivables_payables.bucket_31_90',
  '90+': 'tax_evidence.receivables_payables.bucket_90_plus',
}

const filters = reactive({
  currency: '' as string, // '' = všechny měny
})

async function load() {
  loading.value = true
  try {
    report.value = await taxEvidenceApi.receivablesPayables()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

const currencies = computed(() => report.value?.currencies ?? [])

interface PivotRow {
  currency: string
  cells: Record<string, number>
  total: number
}

function pivot(rows: AgingBucket[] | undefined): PivotRow[] {
  const map: Record<string, PivotRow> = {}
  for (const r of rows ?? []) {
    if (filters.currency && r.currency !== filters.currency) continue
    const row = (map[r.currency] ??= { currency: r.currency, cells: {}, total: 0 })
    row.cells[r.bucket] = (row.cells[r.bucket] ?? 0) + (r.total ?? 0)
    row.total = Math.round((row.total + (r.total ?? 0)) * 100) / 100
  }
  return Object.values(map).sort((a, b) => a.currency.localeCompare(b.currency))
}

const receivableRows = computed(() => pivot(report.value?.receivables))
const payableRows = computed(() => pivot(report.value?.payables))

const kpis = computed(() => report.value?.kpis)

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!report.value) return
  exporting.value = true
  try {
    const r = await taxEvidenceApi.exportReport('/tax-evidence/receivables-payables/export', { format })
    downloadBlob(r.data as unknown as Blob, `pohledavky-zavazky.${format}`)
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

const COLUMNS: ColumnDef[] = [
  { key: 'currency', labelKey: 'tax_evidence.receivables_payables.col_currency', required: true },
  { key: 'not_due', labelKey: 'tax_evidence.receivables_payables.bucket_not_due' },
  { key: '1-30', labelKey: 'tax_evidence.receivables_payables.bucket_1_30' },
  { key: '31-90', labelKey: 'tax_evidence.receivables_payables.bucket_31_90' },
  { key: '90+', labelKey: 'tax_evidence.receivables_payables.bucket_90_plus' },
  { key: 'total', labelKey: 'tax_evidence.receivables_payables.row_total', required: true },
]
const tbl = useTablePrefs('receivables_payables', COLUMNS)

const hasData = computed(() => receivableRows.value.length > 0 || payableRows.value.length > 0)

onMounted(load)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('tax_evidence.receivables_payables.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('tax_evidence.receivables_payables.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('tax_evidence.receivables_payables.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('tax_evidence.receivables_payables.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtr měny + KPI -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.receivables_payables.col_currency') }}</label>
          <select v-model="filters.currency"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <div v-if="kpis">
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.receivables_payables.kpi_dso') }}</div>
          <div class="font-mono font-medium">{{ t('tax_evidence.receivables_payables.kpi_days', { n: kpis.dso?.avg_days ?? 0 }) }}</div>
          <div class="text-[11px] text-neutral-400">{{ t('tax_evidence.receivables_payables.kpi_sample', { n: kpis.dso?.sample_size ?? 0 }) }}</div>
        </div>
        <div v-if="kpis">
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.receivables_payables.kpi_dpo') }}</div>
          <div class="font-mono font-medium">{{ t('tax_evidence.receivables_payables.kpi_days', { n: kpis.dpo?.avg_days ?? 0 }) }}</div>
          <div class="text-[11px] text-neutral-400">{{ t('tax_evidence.receivables_payables.kpi_sample', { n: kpis.dpo?.sample_size ?? 0 }) }}</div>
        </div>
        <div v-if="kpis">
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.receivables_payables.kpi_punctuality') }}</div>
          <div class="font-mono font-medium">{{ (kpis.punctuality?.on_time_pct ?? 0) }} %</div>
          <div class="text-[11px] text-neutral-400">{{ t('tax_evidence.receivables_payables.kpi_sample', { n: kpis.punctuality?.total ?? 0 }) }}</div>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="!report || !hasData" class="text-center text-neutral-500 py-12 text-sm">
      {{ t('tax_evidence.receivables_payables.empty') }}
    </div>

    <template v-else>
      <!-- Pohledávky -->
      <div class="mb-6">
        <h2 class="text-sm font-semibold text-neutral-700 mb-2">{{ t('tax_evidence.receivables_payables.title_recv') }}</h2>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm" :class="tbl.densityClass.value">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th v-if="tbl.isVisible('currency')" class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.receivables_payables.col_currency') }}</th>
                  <th v-for="b in BUCKETS" v-show="tbl.isVisible(b)" :key="b" class="px-3 py-2 text-right font-medium">{{ t(BUCKET_KEY[b]) }}</th>
                  <th v-if="tbl.isVisible('total')" class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.receivables_payables.row_total') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in receivableRows" :key="row.currency" class="hover:bg-neutral-50">
                  <td v-if="tbl.isVisible('currency')" class="px-3 py-2 font-mono">{{ row.currency }}</td>
                  <td v-for="b in BUCKETS" v-show="tbl.isVisible(b)" :key="b" class="px-3 py-2 text-right font-mono">{{ row.cells[b] ? formatMoney(row.cells[b]) : '—' }}</td>
                  <td v-if="tbl.isVisible('total')" class="px-3 py-2 text-right font-mono font-semibold">{{ formatMoney(row.total) }}</td>
                </tr>
                <tr v-if="receivableRows.length === 0">
                  <td class="px-3 py-3 text-neutral-400 text-center" :colspan="6">{{ t('tax_evidence.receivables_payables.empty') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Závazky -->
      <div>
        <h2 class="text-sm font-semibold text-neutral-700 mb-2">{{ t('tax_evidence.receivables_payables.title_pay') }}</h2>
        <div class="mb-2 px-3 py-2 rounded-md bg-warning-50 border border-warning-500/30 text-warning-600 text-xs">
          {{ t('tax_evidence.receivables_payables.partial_payables_note') }}
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm" :class="tbl.densityClass.value">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th v-if="tbl.isVisible('currency')" class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.receivables_payables.col_currency') }}</th>
                  <th v-for="b in BUCKETS" v-show="tbl.isVisible(b)" :key="b" class="px-3 py-2 text-right font-medium">{{ t(BUCKET_KEY[b]) }}</th>
                  <th v-if="tbl.isVisible('total')" class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.receivables_payables.row_total') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in payableRows" :key="row.currency" class="hover:bg-neutral-50">
                  <td v-if="tbl.isVisible('currency')" class="px-3 py-2 font-mono">{{ row.currency }}</td>
                  <td v-for="b in BUCKETS" v-show="tbl.isVisible(b)" :key="b" class="px-3 py-2 text-right font-mono">{{ row.cells[b] ? formatMoney(row.cells[b]) : '—' }}</td>
                  <td v-if="tbl.isVisible('total')" class="px-3 py-2 text-right font-mono font-semibold">{{ formatMoney(row.total) }}</td>
                </tr>
                <tr v-if="payableRows.length === 0">
                  <td class="px-3 py-3 text-neutral-400 text-center" :colspan="6">{{ t('tax_evidence.receivables_payables.empty') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
