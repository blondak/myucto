<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import { cashApi, type CashRegister, type CashDocument } from '@/api/cash'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters } from '@/composables/useSavedFilters'
import CashRegisterManager from '@/components/cash/CashRegisterManager.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()

const registers = ref<CashRegister[]>([])
const documents = ref<CashDocument[]>([])
const loading = ref(false)
const managerOpen = ref(false)

const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const rangeFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * perPage.value + 1))
const rangeTo = computed(() => Math.min(page.value * perPage.value, total.value))

function defaultRange(): { from: string; to: string } {
  const year = new Date().getFullYear()
  return { from: `${year}-01-01`, to: `${year}-12-31` }
}

const filters = reactive({
  register_id: '' as number | '',
  doc_type: '' as '' | 'in' | 'out',
  purpose: '' as '' | 'sale' | 'purchase' | 'invoice_payment' | 'purchase_payment' | 'transfer' | 'other',
  status: '' as '' | 'posted' | 'reversed',
  from: defaultRange().from,
  to: defaultRange().to,
  q: '',
})

const selectedRegister = computed<CashRegister | null>(() => {
  if (registers.value.length === 0) return null
  if (filters.register_id !== '') return registers.value.find(r => r.id === filters.register_id) ?? null
  return registers.value.find(r => r.is_default) ?? registers.value[0]
})

async function loadRegisters() {
  try { registers.value = await cashApi.listRegisters() } catch { registers.value = [] }
}

async function load() {
  loading.value = true
  try {
    const r = await cashApi.listDocuments({
      page: page.value,
      register_id: filters.register_id || undefined,
      doc_type: filters.doc_type || undefined,
      purpose: filters.purpose || undefined,
      status: filters.status || undefined,
      from: filters.from || undefined,
      to: filters.to || undefined,
      q: filters.q || undefined,
    })
    documents.value = r.items
    total.value = r.total
    perPage.value = r.per_page
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    documents.value = []
  } finally {
    loading.value = false
  }
}

function applyFilters() { page.value = 1; load() }
function resetFilters() {
  filters.register_id = ''
  filters.doc_type = ''
  filters.purpose = ''
  filters.status = ''
  filters.from = defaultRange().from
  filters.to = defaultRange().to
  filters.q = ''
  applyFilters()
}

function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), totalPages.value)
  if (np !== page.value) { page.value = np; load(); expandedId.value = null }
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.register_id !== '') q.register_id = String(filters.register_id)
  if (filters.doc_type) q.doc_type = filters.doc_type
  if (filters.purpose) q.purpose = filters.purpose
  if (filters.status) q.status = filters.status
  if (filters.from) q.from = filters.from
  if (filters.to) q.to = filters.to
  if (filters.q) q.q = filters.q
  return q
}
function applyQueryToPage(q: Record<string, string>) {
  filters.register_id = q.register_id ? Number(q.register_id) : ''
  filters.doc_type = (q.doc_type === 'in' || q.doc_type === 'out') ? q.doc_type : ''
  filters.purpose = ['sale', 'purchase', 'invoice_payment', 'purchase_payment', 'transfer', 'other'].includes(q.purpose ?? '')
    ? (q.purpose as typeof filters.purpose) : ''
  filters.status = (q.status === 'posted' || q.status === 'reversed') ? q.status : ''
  filters.from = q.from ?? defaultRange().from
  filters.to = q.to ?? defaultRange().to
  filters.q = q.q ?? ''
  applyFilters()
}

const COLUMNS: ColumnDef[] = [
  { key: 'number', labelKey: 'cash.col.number', required: true },
  { key: 'date', labelKey: 'cash.col.date' },
  { key: 'type', labelKey: 'cash.col.type' },
  { key: 'partner', labelKey: 'cash.col.partner' },
  { key: 'description', labelKey: 'cash.col.description' },
  { key: 'amount', labelKey: 'cash.col.amount', required: true },
  { key: 'link', labelKey: 'cash.col.link' },
  { key: 'status', labelKey: 'cash.col.status' },
  { key: 'tax_date', labelKey: 'cash.col.tax_date', defaultHidden: true },
  { key: 'created_at', labelKey: 'cash.col.created_at', defaultHidden: true },
  { key: 'created_by', labelKey: 'cash.col.created_by', defaultHidden: true },
]
const tbl = useTablePrefs('cash_documents', COLUMNS)
const saved = useSavedFilters('cash_documents', { getQuery: buildQuery, applyQuery: applyQueryToPage })
const visibleColCount = computed(() => 2 + tbl.columns.filter(c => tbl.isVisible(c.key)).length)

onMounted(async () => {
  await loadRegisters()
  // Deep-link (drill-down z pokladní knihy) má přednost před defaultním filtrem.
  const q = Object.fromEntries(Object.entries(route.query).map(([k, v]) => [k, String(v ?? '')]))
  if (Object.keys(q).some(k => ['register_id', 'doc_type', 'purpose', 'status', 'from', 'to', 'q'].includes(k))) {
    applyQueryToPage(q)
    return
  }
  if (await saved.applyDefaultIfAny()) return
  await load()
})

// ── Detail expand ────────────────────────────────────────────────────────
const expandedId = ref<number | null>(null)
function toggleExpand(d: CashDocument) {
  expandedId.value = expandedId.value === d.id ? null : d.id
}

function purposeLabel(p: string): string { return t(`cash.purpose.${p}`) }

function linkFor(d: CashDocument): { label: string; to: any } | null {
  if (d.invoice_id) return { label: d.invoice_number || `#${d.invoice_id}`, to: { name: 'invoice-detail', params: { id: d.invoice_id } } }
  if (d.purchase_invoice_id) return { label: d.purchase_invoice_number || `#${d.purchase_invoice_id}`, to: { name: 'purchase-invoice-detail', params: { id: d.purchase_invoice_id } } }
  return null
}

function openPdf(d: CashDocument) {
  window.open(cashApi.documentPdfUrl(d.id), '_blank', 'noopener')
}

// ── Storno ────────────────────────────────────────────────────────────────
const reverseTarget = ref<CashDocument | null>(null)
const reverseReason = ref('')
const reverseDate = ref('')
const reverseSaving = ref(false)
const reverseError = ref('')

function openReverse(d: CashDocument) {
  reverseTarget.value = d
  reverseReason.value = ''
  reverseDate.value = ''
  reverseError.value = ''
}

async function submitReverse() {
  if (!reverseTarget.value) return
  if (reverseReason.value.trim().length < 3) { reverseError.value = t('cash.reverse.reason'); return }
  reverseSaving.value = true
  try {
    await cashApi.reverseDocument(reverseTarget.value.id, reverseReason.value.trim(), reverseDate.value || undefined)
    toast.success(t('common.saved'))
    reverseTarget.value = null
    await loadRegisters()
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code) {
      const key = code.startsWith('cash.') ? code : `cash.error.${code}`
      const localized = t(key)
      reverseError.value = localized !== key ? localized : (e?.response?.data?.error?.message || t('common.error'))
    } else {
      reverseError.value = e?.response?.data?.error?.message || t('common.error')
    }
  } finally {
    reverseSaving.value = false
  }
}

// ── Trvalé smazání (doklad + účetní zápisy) ───────────────────────────────
const deleteTarget = ref<CashDocument | null>(null)
const deleteSaving = ref(false)
const deleteError = ref('')

function openDelete(d: CashDocument) {
  deleteTarget.value = d
  deleteError.value = ''
}

async function submitDelete() {
  if (!deleteTarget.value) return
  deleteSaving.value = true
  try {
    await cashApi.deleteDocument(deleteTarget.value.id, true)
    toast.success(t('common.deleted'))
    deleteTarget.value = null
    await loadRegisters()
    await load()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code) {
      const key = code.startsWith('cash.') ? code : `cash.error.${code}`
      const localized = t(key)
      deleteError.value = localized !== key ? localized : (e?.response?.data?.error?.message || t('common.error'))
    } else {
      deleteError.value = e?.response?.data?.error?.message || t('common.error')
    }
  } finally {
    deleteSaving.value = false
  }
}

function onManagerChanged() { loadRegisters() }
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('cash.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('cash.book_title') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <select v-if="registers.length > 1" v-model="filters.register_id" @change="applyFilters"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
          <option value="">{{ t('common.all') }}</option>
          <option v-for="r in registers" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
        <button v-if="auth.canWrite('cash.document.write')" @click="managerOpen = true" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
          {{ t('cash.registers_manage') }}
        </button>
        <RouterLink to="/accounting/cash/book" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          {{ t('cash.book_title') }}
        </RouterLink>
        <RouterLink v-if="auth.canWrite('cash.document.write') && selectedRegister"
          :to="{ path: '/accounting/cash/new', query: { doc_type: 'in', register_id: selectedRegister.id } }"
          :class="btnFilled('success')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('cash.type.in_short') }}
        </RouterLink>
        <RouterLink v-if="auth.canWrite('cash.document.write') && selectedRegister"
          :to="{ path: '/accounting/cash/new', query: { doc_type: 'out', register_id: selectedRegister.id } }"
          :class="btnFilled('warning')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('cash.type.out_short') }}
        </RouterLink>
      </div>
    </div>

    <!-- Hero zůstatek -->
    <div v-if="selectedRegister" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <div class="text-xs text-neutral-500">{{ selectedRegister.name }} · {{ t('cash.balance_as_of', { date: formatDate(selectedRegister.balance_date) }) }}</div>
        <div class="text-2xl font-semibold font-mono" :class="selectedRegister.balance < 0 ? 'text-danger-500' : 'text-neutral-800'">
          {{ formatMoney(selectedRegister.balance) }}
        </div>
        <div class="text-xs text-neutral-400 font-mono mt-0.5">{{ t('cash.register_account') }} {{ selectedRegister.account_code }}</div>
      </div>
      <span v-if="selectedRegister.balance < 0" class="text-xs px-2 py-1 rounded font-medium bg-danger-50 text-danger-500">
        {{ t('cash.warning.negative_balance') }}
      </span>
    </div>

    <!-- Bez pokladny -->
    <div v-if="!loading && registers.length === 0" class="text-center py-12">
      <p class="text-neutral-500 text-sm mb-3">{{ t('cash.empty.registers') }}</p>
      <button v-if="auth.canWrite('cash.document.write')" @click="managerOpen = true" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('cash.register_create') }}
      </button>
    </div>

    <template v-else>
      <!-- Filtry -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.col.date') }}</label>
            <input v-model="filters.from" type="date" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">&nbsp;</label>
            <input v-model="filters.to" type="date" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.col.type') }}</label>
            <select v-model="filters.doc_type" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="">{{ t('common.all') }}</option>
              <option value="in">{{ t('cash.type.in_short') }}</option>
              <option value="out">{{ t('cash.type.out_short') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.status.posted') }}</label>
            <select v-model="filters.status" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="">{{ t('common.all') }}</option>
              <option value="posted">{{ t('cash.status.posted') }}</option>
              <option value="reversed">{{ t('cash.status.reversed') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.col.description') }}</label>
            <input v-model="filters.q" type="text" @keyup.enter="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 mt-2">
          <button @click="resetFilters" class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700">{{ t('accounting.journal.reset_filters') }}</button>
          <SavedFiltersMenu :ctrl="saved" />
          <ColumnPicker class="hidden md:block" :ctrl="tbl" />
          <DensityToggle class="hidden md:block" :ctrl="tbl" />
        </div>
      </div>

      <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

      <div v-else-if="documents.length === 0" class="text-center text-neutral-500 py-12 text-sm">{{ t('cash.empty.documents') }}</div>

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm" :class="tbl.densityClass.value">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 w-8"></th>
                <th v-if="tbl.isVisible('number')" class="px-3 py-2 text-left font-medium w-32">{{ t('cash.col.number') }}</th>
                <th v-if="tbl.isVisible('date')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.date') }}</th>
                <th v-if="tbl.isVisible('type')" class="px-3 py-2 text-left font-medium w-20">{{ t('cash.col.type') }}</th>
                <th v-if="tbl.isVisible('partner')" class="px-3 py-2 text-left font-medium">{{ t('cash.col.partner') }}</th>
                <th v-if="tbl.isVisible('description')" class="px-3 py-2 text-left font-medium">{{ t('cash.col.description') }}</th>
                <th v-if="tbl.isVisible('amount')" class="px-3 py-2 text-right font-medium w-32">{{ t('cash.col.amount') }}</th>
                <th v-if="tbl.isVisible('link')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.link') }}</th>
                <th v-if="tbl.isVisible('status')" class="px-3 py-2 text-center font-medium w-24">{{ t('cash.col.status') }}</th>
                <th v-if="tbl.isVisible('tax_date')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.tax_date') }}</th>
                <th v-if="tbl.isVisible('created_at')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.created_at') }}</th>
                <th v-if="tbl.isVisible('created_by')" class="px-3 py-2 text-left font-medium w-32">{{ t('cash.col.created_by') }}</th>
                <th class="px-3 py-2 text-right font-medium w-24"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="d in documents" :key="d.id">
                <tr class="hover:bg-neutral-50 cursor-pointer" :class="{ 'opacity-60': d.status === 'reversed' }" @click="toggleExpand(d)">
                  <td class="px-3 py-2 text-neutral-400">
                    <span class="inline-block transition-transform" :class="{ 'rotate-90': expandedId === d.id }">▸</span>
                  </td>
                  <td v-if="tbl.isVisible('number')" class="px-3 py-2 font-mono text-xs" :class="{ 'line-through': d.status === 'reversed' }">{{ d.doc_number || '—' }}</td>
                  <td v-if="tbl.isVisible('date')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(d.issue_date) }}</td>
                  <td v-if="tbl.isVisible('type')" class="px-3 py-2">
                    <span class="text-xs px-2 py-0.5 rounded font-medium"
                      :class="d.doc_type === 'in' ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
                      {{ d.doc_type === 'in' ? t('cash.type.in_short') : t('cash.type.out_short') }}
                    </span>
                  </td>
                  <td v-if="tbl.isVisible('partner')" class="px-3 py-2 truncate max-w-[16rem]">{{ d.partner_name || '—' }}</td>
                  <td v-if="tbl.isVisible('description')" class="px-3 py-2 truncate max-w-[20rem]">{{ d.description }}</td>
                  <td v-if="tbl.isVisible('amount')" class="px-3 py-2 text-right font-mono"
                    :class="d.doc_type === 'in' ? 'text-success-600' : 'text-warning-600'">
                    {{ d.doc_type === 'in' ? '+' : '−' }}{{ formatMoney(d.total_amount) }}
                  </td>
                  <td v-if="tbl.isVisible('link')" class="px-3 py-2">
                    <RouterLink v-if="linkFor(d)" :to="linkFor(d)!.to" @click.stop
                      class="font-mono text-xs text-primary-600 hover:text-primary-700">{{ linkFor(d)!.label }}</RouterLink>
                    <span v-else class="text-xs text-neutral-400">{{ purposeLabel(d.purpose) }}</span>
                  </td>
                  <td v-if="tbl.isVisible('status')" class="px-3 py-2 text-center">
                    <span class="text-xs px-2 py-0.5 rounded font-medium"
                      :class="d.status === 'posted' ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                      {{ t(`cash.status.${d.status}`) }}
                    </span>
                  </td>
                  <td v-if="tbl.isVisible('tax_date')" class="px-3 py-2 whitespace-nowrap">{{ d.tax_date ? formatDate(d.tax_date) : '—' }}</td>
                  <td v-if="tbl.isVisible('created_at')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(d.created_at) }}</td>
                  <td v-if="tbl.isVisible('created_by')" class="px-3 py-2 truncate max-w-[10rem]">{{ d.created_by_name || '—' }}</td>
                  <td class="px-3 py-2 text-right whitespace-nowrap" @click.stop>
                    <button type="button" @click="openPdf(d)" :title="t('cash.print')"
                      class="cursor-pointer text-neutral-400 hover:text-primary-600 px-1">⭳</button>
                    <button v-if="auth.canWrite('cash.document.write') && d.status === 'posted'" type="button" @click="openReverse(d)" :title="t('cash.reverse.title')"
                      class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">⟲</button>
                    <button v-if="auth.canWrite('cash.document.write')" type="button" @click="openDelete(d)" :title="t('cash.delete.title')"
                      class="cursor-pointer text-neutral-400 hover:text-danger-600 px-1 align-middle">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                    </button>
                  </td>
                </tr>
                <!-- Detail -->
                <tr v-if="expandedId === d.id">
                  <td :colspan="visibleColCount" class="px-4 py-3 bg-neutral-50">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                      <div class="space-y-1">
                        <div><span class="text-neutral-500">{{ t('cash.col.link') }}:</span> {{ purposeLabel(d.purpose) }}</div>
                        <div v-if="d.partner_ic"><span class="text-neutral-500">{{ t('common.ic') }}:</span> {{ d.partner_ic }}</div>
                        <div v-if="d.partner_dic"><span class="text-neutral-500">{{ t('cash.form.partner_dic') }}:</span> {{ d.partner_dic }}</div>
                        <div v-if="d.tax_date"><span class="text-neutral-500">{{ t('cash.col.date') }} (DUZP):</span> {{ formatDate(d.tax_date) }}</div>
                        <div v-if="d.register"><span class="text-neutral-500">{{ t('cash.register') }}:</span> {{ d.register.name }} ({{ d.register.account_code }})</div>
                      </div>
                      <div v-if="d.vat_mode === 'vat' && d.vat_lines.length" class="space-y-1">
                        <div class="text-neutral-500 text-xs uppercase tracking-wide">{{ t('cash.form.vat_mode_vat') }}</div>
                        <table class="w-full text-xs">
                          <thead class="text-neutral-500">
                            <tr>
                              <th class="text-left font-medium py-0.5">{{ t('cash.form.vat_rate') }}</th>
                              <th class="text-right font-medium py-0.5">{{ t('cash.form.vat_base') }}</th>
                              <th class="text-right font-medium py-0.5">{{ t('cash.form.vat_amount') }}</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="(l, i) in d.vat_lines" :key="i">
                              <td class="py-0.5">{{ l.vat_rate }} %</td>
                              <td class="py-0.5 text-right font-mono">{{ formatMoney(l.base_amount) }}</td>
                              <td class="py-0.5 text-right font-mono">{{ formatMoney(l.vat_amount) }}</td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <!-- Proklik do deníku na zápis tohoto pokladního dokladu (§4). -->
                    <div v-if="d.journal_entry_id" class="mt-3 pt-3 border-t border-neutral-200">
                      <RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(d.journal_entry_id) } }"
                        class="inline-flex items-center gap-1.5 text-sm text-primary-600 hover:text-primary-700 hover:underline">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
                        {{ t('common.view_in_journal') }}
                      </RouterLink>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <nav v-if="!loading && total > perPage" class="mt-4 flex items-center justify-between gap-3 text-sm">
        <span class="text-neutral-500">{{ t('common.pagination_range', { from: rangeFrom, to: rangeTo, total }) }}</span>
        <div class="flex items-center gap-1">
          <button type="button" :disabled="page <= 1" @click="goToPage(page - 1)"
            class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
          <span class="px-2 text-neutral-600">{{ page }} / {{ totalPages }}</span>
          <button type="button" :disabled="page >= totalPages" @click="goToPage(page + 1)"
            class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">›</button>
        </div>
      </nav>
    </template>

    <!-- Modal správy pokladen -->
    <CashRegisterManager v-if="managerOpen" @close="managerOpen = false" @changed="onManagerChanged" />

    <!-- Modal storna -->
    <div v-if="reverseTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="reverseTarget = null">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('cash.reverse.title') }}</h3>
        <p class="text-sm text-neutral-500 mb-3">{{ reverseTarget.doc_number }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.reverse.reason') }}</label>
            <textarea v-model="reverseReason" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.date') }}</label>
            <input v-model="reverseDate" type="date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <p class="text-xs text-neutral-500">{{ t('cash.help.reverse_after_filing') }}</p>
          <div v-if="reverseError" class="text-sm text-danger-500">{{ reverseError }}</div>
          <div class="flex justify-end gap-2 pt-1">
            <button @click="reverseTarget = null" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="submitReverse" :disabled="reverseSaving" :class="btnFilled('danger')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
              {{ reverseSaving ? t('common.saving') : t('cash.reverse.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal trvalého smazání -->
    <div v-if="deleteTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="deleteTarget = null">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('cash.delete.title') }}</h3>
        <p class="text-sm text-neutral-500 mb-3">{{ deleteTarget.doc_number }}</p>
        <div class="space-y-3">
          <p class="text-sm text-neutral-700">{{ t('cash.delete.warning') }}</p>
          <p class="text-xs text-neutral-500">{{ t('cash.delete.hint') }}</p>
          <div v-if="deleteError" class="text-sm text-danger-500">{{ deleteError }}</div>
          <div class="flex justify-end gap-2 pt-1">
            <button @click="deleteTarget = null" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="submitDelete" :disabled="deleteSaving" :class="btnFilled('danger')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ deleteSaving ? t('common.saving') : t('cash.delete.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
