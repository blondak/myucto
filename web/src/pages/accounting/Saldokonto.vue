<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  accountingApi,
  type AccountingPeriod,
  type SaldoReport,
  type SaldoAccountBlock,
  type SaldoItem,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const toast = useToast()

const periods = ref<AccountingPeriod[]>([])
const report = ref<SaldoReport | null>(null)
const loading = ref(false)

const ACCOUNT_OPTIONS = ['all', '311', '321', '314', '324']

const filters = reactive({
  period_id: '' as number | '',
  as_of: '',
  account: 'all',
})

function queryParams() {
  return {
    period_id: Number(filters.period_id),
    as_of: filters.as_of || undefined,
    account: filters.account || 'all',
  }
}

async function load() {
  if (!filters.period_id) return
  loading.value = true
  try {
    report.value = await accountingApi.getSaldo(queryParams())
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

// Expand/collapse per partner (klíč = "accCode:partnerId").
const expanded = reactive<Record<string, boolean>>({})
function partnerKey(accCode: string, partnerId: number) {
  return `${accCode}:${partnerId}`
}
function toggle(accCode: string, partnerId: number) {
  const k = partnerKey(accCode, partnerId)
  expanded[k] = !expanded[k]
}

function docLink(it: SaldoItem) {
  return it.doc_type === 'purchase_invoice'
    ? { name: 'purchase-invoice-detail', params: { id: it.doc_id } }
    : { name: 'invoice-detail', params: { id: it.doc_id } }
}

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!filters.period_id || !report.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/saldo/export', { ...queryParams(), format })
    downloadBlob(r.data as unknown as Blob, `saldokonto-${report.value.as_of}.${format}`)
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

function accountBlockLabel(b: SaldoAccountBlock) {
  return `${b.account.code} — ${b.account.name}`
}

onMounted(async () => {
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
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
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.saldo.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.saldo.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.saldo.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.saldo.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_period') }}</label>
          <select v-model="filters.period_id" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_as_of') }}</label>
          <input v-model="filters.as_of" type="date" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_account') }}</label>
          <select v-model="filters.account" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="a in ACCOUNT_OPTIONS" :key="a" :value="a">
              {{ a === 'all' ? t('accounting.saldo.account_all') : t(`accounting.saldo.account_${a}`) }}
            </option>
          </select>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="!report || report.accounts.length === 0" class="text-center text-neutral-500 py-12 text-sm">
      {{ t('accounting.saldo.empty') }}
    </div>

    <div v-else class="space-y-6">
      <div v-for="b in report.accounts" :key="b.account.id"
        class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-neutral-200">
          <h2 class="text-base font-semibold">{{ accountBlockLabel(b) }}</h2>
        </div>

        <!-- Konfrontace -->
        <div class="px-4 py-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm border-b border-neutral-100 bg-neutral-50">
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.gl_balance') }}</div>
            <div class="font-mono font-semibold">{{ formatMoney(b.gl_balance) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.open_items_total') }}</div>
            <div class="font-mono font-semibold">{{ formatMoney(b.open_items_total) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.difference') }}</div>
            <div class="font-mono font-semibold flex items-center gap-1"
              :class="b.matches ? 'text-success-600' : 'text-danger-500'">
              <span>{{ b.matches ? '✓' : '✗' }}</span>
              <span>{{ formatMoney(b.difference) }}</span>
            </div>
          </div>
        </div>

        <div v-if="!b.matches" class="px-4 py-2 text-xs text-danger-600 bg-danger-50 border-b border-danger-500/20">
          {{ t('accounting.saldo.difference_hint') }}
        </div>

        <div v-if="b.partners.length === 0" class="px-4 py-6 text-center text-neutral-500 text-sm">
          {{ t('accounting.saldo.no_open_items') }}
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-8"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_partner') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_doc') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_issue') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_due') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_overdue') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_amount') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_paid') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_remaining') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="p in b.partners" :key="p.partner_id">
                <tr class="cursor-pointer hover:bg-neutral-50 font-medium bg-neutral-50/50"
                  @click="toggle(b.account.code, p.partner_id)">
                  <td class="px-3 py-2">
                    <span class="inline-block transition-transform" :class="{ 'rotate-90': expanded[partnerKey(b.account.code, p.partner_id)] }">▸</span>
                  </td>
                  <td class="px-3 py-2" colspan="7">{{ p.partner_name }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(p.total_remaining) }}</td>
                </tr>
                <template v-if="expanded[partnerKey(b.account.code, p.partner_id)]">
                  <tr v-for="it in p.items" :key="`${it.doc_type}-${it.doc_id}`" class="hover:bg-neutral-50">
                    <td class="px-3 py-2"></td>
                    <td class="px-3 py-2"></td>
                    <td class="px-3 py-2">
                      <RouterLink :to="docLink(it)" class="text-primary-600 hover:text-primary-700 hover:underline font-mono">
                        {{ it.doc_no }}
                      </RouterLink>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.issue_date) }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.due_date) }}</td>
                    <td class="px-3 py-2 text-right" :class="it.days_overdue > 0 ? 'text-danger-500 font-medium' : 'text-neutral-400'">
                      {{ it.days_overdue > 0 ? it.days_overdue : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono">
                      {{ formatMoney(it.booked_czk) }}
                      <span v-if="it.currency_code !== 'CZK'" class="block text-xs text-neutral-400">
                        {{ formatMoney(it.amount_foreign) }} {{ it.currency_code }}
                      </span>
                    </td>
                    <td class="px-3 py-2 text-right font-mono">{{ formatMoney(it.paid_czk) }}</td>
                    <td class="px-3 py-2 text-right font-mono">{{ formatMoney(it.remaining_czk) }}</td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
