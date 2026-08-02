<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { invoicesApi, type MonthGroup, type InvoiceListItem } from '@/api/invoices'
import { formatMoney, formatDate, formatMonth, statusLabel, typeLabel, statusBadgeClass, isOverdue, invoiceRowClass, displayStatus, taxDateClass } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useRowLink } from '@/composables/useRowLink'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type Currency } from '@/api/codebooks'
import { useYearOptions } from '@/composables/useYearOptions'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import BulkActionBar from '@/components/ui/BulkActionBar.vue'
import WorkReportModal from '@/components/modals/WorkReportModal.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters } from '@/composables/useSavedFilters'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import PostingBadge from '@/components/ui/PostingBadge.vue'
import { accountingApi, postingErrorI18nKey } from '@/api/accounting'

const { t, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const thanksEnabled = computed(() => supplierStore.currentSupplier?.payment_thanks_enabled ?? false)
// Účetní badge „Zaúčtováno / Nezaúčtováno" jen v podvojném účetnictví (daňová evidence doklady neúčtuje).
const isDoubleEntry = computed(() => auth.hasCommercialFeatures && supplierStore.currentSupplier?.accounting_mode === 'double_entry')

useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/invoices/new') })

const router = useRouter()
const route = useRoute()

const groups = ref<MonthGroup[]>([])
/**
 * Postupné nabíhání řádků (.stagger-in) běží JEN u prvního vykreslení seznamu.
 * Při filtrování by animace zdržovala práci — a filtr je tady to nejpoužívanější,
 * co uživatel dělá. Vypínáme ho hned, jak dorazí první dávka dat.
 */
const staggerRows = ref(true)
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const statusFilter = ref<string>('')
const typeFilter = ref<string>('')
const clientFilter = ref<number | ''>('')
const yearFilter = ref<number | ''>(new Date().getFullYear())
const monthFilter = ref<number | ''>('')
const dateFrom = ref<string>('')
const dateTo = ref<string>('')
const overdueOnly = ref(false)
const unpaidOnly = ref(false)
// Zaúčtováno/nezaúčtováno (0.9) — jen podvojné účetnictví. '' = vše, '1' = zaúčtováno, '0' = nezaúčtováno.
const bookedFilter = ref<'' | '1' | '0'>('')
const currencyFilter = ref<string>('')
const clients = ref<Client[]>([])
const currencies = ref<Currency[]>([])

// Počet aktivních filtrů pro odznáček na mobilním tlačítku „Filtry" (rok i hledání se nepočítají — rok má výchozí hodnotu, hledání je vždy vidět)
const activeFilterCount = computed(() => {
  let n = 0
  if (statusFilter.value) n++
  if (typeFilter.value) n++
  if (clientFilter.value !== '') n++
  if (currencyFilter.value) n++
  if (monthFilter.value !== '') n++
  if (dateFrom.value || dateTo.value) n++
  if (overdueOnly.value) n++
  if (unpaidOnly.value) n++
  if (bookedFilter.value) n++
  return n
})

/**
 * Aktivní filtry jako odstranitelné chipy pod lištou.
 *
 * Why: filtry jsou nově sbalené za tlačítko „Filtry (N)" i na desktopu — deset
 * trvale rozbalených selectů zabíralo dva řádky nad každým seznamem, i když
 * uživatel nefiltroval. Chipy nesou tutéž informaci čitelněji: rovnou vidíš CO
 * je zapnuté, místo abys v řadě ovládacích prvků hledal, který není na výchozí
 * hodnotě. Rok se nezobrazuje ze stejného důvodu, proč se nepočítá do
 * `activeFilterCount` — má výchozí hodnotu a byl by v chipech pořád.
 */
const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (statusFilter.value) chips.push({ key: 'status', value: statusLabel(statusFilter.value) })
  if (typeFilter.value) chips.push({ key: 'type', value: typeLabel(typeFilter.value) })
  if (clientFilter.value !== '') {
    const c = clients.value.find(x => x.id === clientFilter.value)
    if (c) chips.push({ key: 'client', value: c.company_name })
  }
  if (currencyFilter.value) chips.push({ key: 'currency', value: currencyFilter.value })
  if (monthFilter.value !== '') {
    chips.push({ key: 'month', value: monthOptions.value[Number(monthFilter.value) - 1] ?? String(monthFilter.value) })
  }
  if (dateFrom.value || dateTo.value) {
    chips.push({ key: 'dates', value: `${dateFrom.value ? formatDate(dateFrom.value) : '…'} – ${dateTo.value ? formatDate(dateTo.value) : '…'}` })
  }
  if (overdueOnly.value) chips.push({ key: 'overdue', value: t('invoice.overdue_only') })
  if (unpaidOnly.value) chips.push({ key: 'unpaid', value: t('invoice.unpaid_only') })
  if (bookedFilter.value) {
    chips.push({ key: 'booked', value: bookedFilter.value === '1' ? t('common.booked_badge') : t('common.unbooked_badge') })
  }
  return chips
})

function clearFilter(key: string) {
  switch (key) {
    case 'status': statusFilter.value = ''; break
    case 'type': typeFilter.value = ''; break
    case 'client': clientFilter.value = ''; break
    case 'currency': currencyFilter.value = ''; break
    case 'month': monthFilter.value = ''; break
    case 'dates': dateFrom.value = ''; dateTo.value = ''; break
    case 'overdue': overdueOnly.value = false; break
    case 'unpaid': unpaidOnly.value = false; break
    case 'booked': bookedFilter.value = ''; break
  }
}

function clearAllFilters() {
  for (const chip of filterChips.value) clearFilter(chip.key)
}

const selectedIds = ref<number[]>([])
const bulkBusy = ref(false)
const bulkPdfOpen = ref(false)
const bulkPdfSign = ref(false)

const selectedPdfIds = computed(() => {
  const selected = new Set(selectedIds.value)
  const visible = groups.value.flatMap(group => group.invoices)
    .map(invoice => invoice.id)
    .filter(id => selected.has(id))
  const visibleSet = new Set(visible)
  return [...visible, ...selectedIds.value.filter(id => !visibleSet.has(id))]
})

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function hasPositiveAmountToPay(inv: InvoiceListItem): boolean {
  if (!['invoice', 'proforma'].includes(inv.invoice_type)) return true
  return Number(inv.amount_to_pay ?? 0) > 0
}

// Zámek dokladu (F6) — jen z BE pole `locked`, FE nic neodvozuje.
function rowLockedForMe(inv: InvoiceListItem): boolean {
  return auth.isClientRole && !!inv.locked?.is_locked
}

function lockTitle(inv: InvoiceListItem): string {
  const reasons = (inv.locked?.reasons ?? []).map(r => t(`lock.reason.${r}`)).join(', ')
  return reasons ? `${t('lock.badge')}: ${reasons}` : (t('lock.badge') as string)
}

/**
 * Šířka mikro-baru za částkou = podíl na nejvyšší faktuře TÉŽE měny v měsíci.
 *
 * Why: seznam je zeď čísel, ve které se řádově velká faktura ztrácí stejně jako
 * drobná. Proužek pod textem (viz .amount-cell v styles/main.css) dá řádu velikosti
 * tvar, aniž by zabral jediný pixel navíc.
 *
 * Měny se nemíchají — 100 EUR a 100 CZK nejsou porovnatelné, takže každá měna má
 * ve skupině vlastní maximum. Minimum 4 % drží proužek viditelný i u drobných částek.
 */
function amountBarWidth(inv: InvoiceListItem, g: MonthGroup): string {
  const value = Math.abs(inv.amount_to_pay ?? inv.total_with_vat ?? 0)
  if (value === 0) return '0%'
  let max = 0
  for (const other of g.invoices) {
    if (other.currency !== inv.currency) continue
    const v = Math.abs(other.amount_to_pay ?? other.total_with_vat ?? 0)
    if (v > max) max = v
  }
  if (max === 0) return '0%'
  return `${Math.max(4, Math.round((value / max) * 100))}%`
}

function toggleSelected(id: number) {
  const i = selectedIds.value.indexOf(id)
  if (i === -1) selectedIds.value.push(id)
  else selectedIds.value.splice(i, 1)
}

function isGroupSelected(group: MonthGroup): boolean {
  return group.invoices.length > 0 && group.invoices.every(invoice => selectedIds.value.includes(invoice.id))
}

function isGroupSelectionPartial(group: MonthGroup): boolean {
  const count = group.invoices.filter(invoice => selectedIds.value.includes(invoice.id)).length
  return count > 0 && count < group.invoices.length
}

function toggleGroupSelected(group: MonthGroup) {
  const groupIds = group.invoices.map(invoice => invoice.id)
  const selected = new Set(selectedIds.value)
  if (groupIds.every(id => selected.has(id))) {
    selectedIds.value = selectedIds.value.filter(id => !groupIds.includes(id))
    return
  }
  for (const id of groupIds) selected.add(id)
  selectedIds.value = Array.from(selected)
}

function openBulkPdfExport() {
  if (selectedPdfIds.value.length === 0) return
  bulkPdfSign.value = false
  bulkPdfOpen.value = true
}

async function responseErrorMessage(error: any, fallback: string): Promise<string> {
  const data = error?.response?.data
  if (data instanceof Blob) {
    try {
      const parsed = JSON.parse(await data.text())
      return parsed?.error?.message || fallback
    } catch {
      return fallback
    }
  }
  return data?.error?.message || fallback
}

async function bulkExportPdf() {
  const ids = selectedPdfIds.value
  if (ids.length === 0 || ids.length > 100) return
  bulkBusy.value = true
  try {
    const response = await invoicesApi.exportSelectedPdf(ids, bulkPdfSign.value)
    const disposition = response.headers['content-disposition'] || ''
    const match = disposition.match(/filename="?([^";]+)"?/)
    const filename = match?.[1] || `myucto-vybrane-faktury-${new Date().toISOString().slice(0, 10)}.pdf`
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
    bulkPdfOpen.value = false
    toast.success(t('invoice.bulk_pdf_done', { n: ids.length }))
  } catch (error: any) {
    toast.error(await responseErrorMessage(error, t('invoice.bulk_pdf_failed') as string))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkReissue() {
  if (selectedIds.value.length === 0) return
  if (!confirm(t('invoice.bulk_clone_confirm', { n: selectedIds.value.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkReissue(selectedIds.value, { increment_month_in_descriptions: true })
    selectedIds.value = []
    if (r.errors.length) {
      toast.warning(t('invoice.bulk_reissue_partial', { ok: r.created.length, err: r.errors.length }))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: r.created.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reissue_failed'))
  } finally {
    bulkBusy.value = false
  }
}

// Hromadné odeslání klientům — pouze faktury se status issued/sent/reminded/paid + ne cancellation
const sendableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded', 'paid'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
    )
})

// Hromadné vystavení — jen drafty. Řadíme podle issue_date asc, pak id asc, aby varsymboly šly sekvenčně.
const issuableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => ids.has(inv.id) && inv.status === 'draft' && !rowLockedForMe(inv))
    .sort((a, b) => (a.issue_date || '').localeCompare(b.issue_date || '') || (a.id - b.id))
})

// Hromadné označení za zaplacené — jen issued/sent/reminded (ne paid, ne cancelled, ne draft, ne cancellation)
const markPayableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
      && hasPositiveAmountToPay(inv)
      && !rowLockedForMe(inv)
    )
})

// Hromadná upomínka — jen běžné faktury (ne proforma/dobropis/storno) ve stavu issued/sent/reminded,
// po splatnosti a placené bankovním převodem (kartové/hotovostní úhrady se neupomínají).
const reminderSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => {
      if (!ids.has(inv.id)) return false
      if (inv.invoice_type !== 'invoice') return false
      if (!['issued', 'sent', 'reminded'].includes(inv.status)) return false
      if (!hasPositiveAmountToPay(inv)) return false
      if ((inv.payment_method ?? 'bank_transfer') !== 'bank_transfer') return false
      const due = new Date(inv.due_date)
      return due < today
    })
})

async function bulkSendReminders() {
  const list = reminderSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_reminder_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_reminder_confirm', { n: list.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkSendReminders(list.map(i => i.id))
    selectedIds.value = []
    if (r.errors.length) {
      const detail = r.errors.map(e => `#${e.invoice_id}: ${e.error}`).join('\n')
      toast.warning(t('invoice.bulk_reminder_partial', { ok: r.sent.length, err: r.errors.length }) + '\n' + detail)
    } else {
      toast.success(t('invoice.bulk_reminder_success', { n: r.sent.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reminder_failed'))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkMarkPaid() {
  const list = markPayableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_mark_paid_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_mark_paid_confirm', { n: list.length }))) return
  // Volitelně i poděkování za úhradu (issue #57) — jen pokud má dodavatel funkci zapnutou.
  const sendThanks = thanksEnabled.value && confirm(t('invoice.bulk_send_thanks_confirm', { n: list.length }))
  const today = new Date().toISOString().slice(0, 10)
  bulkBusy.value = true
  let okCount = 0
  let thanksSent = 0
  let thanksFailed = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        const updated = await invoicesApi.markPaid(inv.id, today, sendThanks ? { sendThanks: true, thanksTrigger: 'bulk' } : undefined)
        okCount++
        const pt = updated.payment_thanks
        if (pt?.status === 'sent') thanksSent++
        else if (pt?.status === 'failed') thanksFailed++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    let msg = errors.length
      ? t('invoice.bulk_mark_paid_partial', { ok: okCount, err: errors.length })
      : t('invoice.bulk_mark_paid_success', { n: okCount })
    if (sendThanks) {
      msg += '\n' + t('invoice.bulk_thanks_summary', { sent: thanksSent, failed: thanksFailed })
    }
    if (errors.length) {
      toast.warning(msg + '\n' + errors.join('\n'))
    } else {
      toast.success(msg)
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function bulkIssue() {
  const list = issuableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_issue_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_issue_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await invoicesApi.issue(inv.id)
        okCount++
      } catch (e: any) {
        errors.push(`#${inv.id}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_issue_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_issue_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

// Hromadné zaúčtování (A2) — jen podvojné účetnictví, jen nezaúčtované (booked_at NULL)
// vystavené doklady (drafty/storna nemají co účtovat). Report ok/fail řeší bulkPost.
const postableSelected = computed(() => {
  if (!isDoubleEntry.value) return []
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && !inv.booked_at
      && ['issued', 'sent', 'reminded', 'paid'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
    )
})

async function bulkPost() {
  const list = postableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_post_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_post_confirm', { n: list.length }))) return
  bulkBusy.value = true
  try {
    const r = await accountingApi.postInvoicesBulk(list.map(i => i.id))
    selectedIds.value = []
    if (r.failed.length) {
      const detail = r.failed.map(f => `#${f.id}: ${t(postingErrorI18nKey(f.error_code))}`).join('\n')
      toast.warning(t('invoice.bulk_post_partial', { ok: r.posted.length, err: r.failed.length }) + '\n' + detail)
    } else {
      toast.success(t('invoice.bulk_post_success', { n: r.posted.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_post_failed'))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkSend() {
  const list = sendableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_send_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_send_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await invoicesApi.send(inv.id)
        okCount++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_send_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

function mergeGroups(existing: MonthGroup[], incoming: MonthGroup[]): MonthGroup[] {
  const byMonth = new Map<string, MonthGroup>()
  for (const g of existing) byMonth.set(g.month, g)
  for (const g of incoming) {
    const cur = byMonth.get(g.month)
    if (!cur) {
      byMonth.set(g.month, g)
      continue
    }
    cur.invoices.push(...g.invoices)
    cur.count += g.count
    // Merge totals_per_currency
    for (const t of g.totals_per_currency) {
      const found = cur.totals_per_currency.find(x => x.currency === t.currency)
      if (found) {
        found.without_vat = Math.round((found.without_vat + t.without_vat) * 100) / 100
        found.vat         = Math.round((found.vat         + t.vat)         * 100) / 100
        found.with_vat    = Math.round((found.with_vat    + t.with_vat)    * 100) / 100
        found.draft_without_vat = Math.round((found.draft_without_vat + t.draft_without_vat) * 100) / 100
        found.draft_vat         = Math.round((found.draft_vat         + t.draft_vat)         * 100) / 100
        found.draft_with_vat    = Math.round((found.draft_with_vat    + t.draft_with_vat)    * 100) / 100
      } else {
        cur.totals_per_currency.push({ ...t })
      }
    }
  }
  return Array.from(byMonth.values()).sort((a, b) => b.month.localeCompare(a.month))
}

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const result = await invoicesApi.listGrouped({
      q: search.value || undefined,
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      client_id: clientFilter.value === '' ? undefined : Number(clientFilter.value),
      year: dateFrom.value || dateTo.value ? undefined : (yearFilter.value === '' ? undefined : Number(yearFilter.value)),
      month: dateFrom.value || dateTo.value || yearFilter.value === '' || monthFilter.value === '' ? undefined : Number(monthFilter.value),
      date_from: dateFrom.value || undefined,
      date_to:   dateTo.value || undefined,
      currency:  currencyFilter.value || undefined,
      overdue: overdueOnly.value || undefined,
      unpaid_only: unpaidOnly.value || undefined,
      booked: bookedFilter.value || undefined,
      page: page.value,
    })
    if (reset) {
      groups.value = result.data
    } else {
      groups.value = mergeGroups(groups.value, result.data)
    }
    total.value = result.meta.total
    pages.value = result.meta.pages ?? 1
  } finally {
    loading.value = false
    loadingMore.value = false
    // Po první dávce dat je stagger odbytý — další načtení (filtr, stránkování,
    // „načíst další") už musí být okamžité.
    if (staggerRows.value) {
      window.setTimeout(() => { staggerRows.value = false }, 600)
    }
  }
}

// Sync filtrů s URL query (stejný pattern jako PurchaseInvoiceList) — detekuje menu
// link click přes route.query change z !empty na empty → reset.
const DEFAULT_YEAR = new Date().getFullYear()

const COLUMNS: ColumnDef[] = [
  { key: 'number', labelKey: 'invoice.varsymbol', required: true },
  { key: 'client', labelKey: 'invoice.client_project' },
  { key: 'type', labelKey: 'invoice.type' },
  { key: 'issued', labelKey: 'invoice.tax_date' },
  { key: 'due', labelKey: 'invoice.due_date' },
  { key: 'amount', labelKey: 'invoice.amount_to_pay', required: true },
  { key: 'status', labelKey: 'invoice.status_label' },
  // Doplňkové sloupce — defaultně skryté, uživatel si je zapne přes ColumnPicker.
  { key: 'paid_at', labelKey: 'invoice.col_paid_at', defaultHidden: true },
  { key: 'payment_method', labelKey: 'payment_method.label', defaultHidden: true },
  { key: 'booked_at', labelKey: 'invoice.col_booked_at', defaultHidden: true },
  { key: 'exchange_rate', labelKey: 'invoice.col_exchange_rate', defaultHidden: true },
  { key: 'amount_czk', labelKey: 'invoice.col_amount_czk', defaultHidden: true },
  { key: 'locked', labelKey: 'lock.column' },
]
const tbl = useTablePrefs('invoices', COLUMNS)

// Kurz do tabulky — 3 desetinná místa (ČNB konvence), lokalizovaný zápis.
function formatRate(rate: number): string {
  return new Intl.NumberFormat('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 3 }).format(rate)
}
const saved = useSavedFilters('invoices', { getQuery: buildQuery, applyQuery: applyQueryToPage })

onMounted(async () => {
  // Načti seznam klientů + měn pro select (paralelně s prvním load)
  clientsApi.list({ archived: false, per_page: 200, role: 'customers' }).then(r => { clients.value = r.data }).catch(() => {})
  codebooksApi.currencies().then(r => {
    const seen = new Set<string>()
    currencies.value = r.filter(c => c.is_active && !seen.has(c.code) && seen.add(c.code))
  }).catch(() => {})
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  loadFiltersFromQuery(route.query)
  await load(true)
})

function loadFiltersFromQuery(q: typeof route.query) {
  statusFilter.value = typeof q.status === 'string' ? q.status : ''
  typeFilter.value   = typeof q.type === 'string' ? q.type : ''
  clientFilter.value = typeof q.client_id === 'string' && q.client_id !== '' ? Number(q.client_id) : ''
  overdueOnly.value  = q.overdue === '1' || q.overdue === 'true'
  unpaidOnly.value   = q.unpaid === '1' || q.unpaid === 'true'
  bookedFilter.value = q.booked === '1' ? '1' : (q.booked === '0' ? '0' : '')
  yearFilter.value   = typeof q.year === 'string' && q.year !== ''
    ? (q.year === 'all' ? '' : Number(q.year))
    : ((overdueOnly.value || unpaidOnly.value || bookedFilter.value === '0') ? '' : DEFAULT_YEAR)
  monthFilter.value  = typeof q.month === 'string' && q.month !== '' ? Number(q.month) : ''
  dateFrom.value     = typeof q.from === 'string' ? q.from : ''
  dateTo.value       = typeof q.to === 'string' ? q.to : ''
  currencyFilter.value = typeof q.currency === 'string' ? q.currency : ''
  search.value       = typeof q.q === 'string' ? q.q : ''
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (statusFilter.value) q.status = statusFilter.value
  if (typeFilter.value) q.type = typeFilter.value
  if (clientFilter.value !== '') q.client_id = String(clientFilter.value)
  if (yearFilter.value === '') q.year = 'all'
  else if (yearFilter.value !== DEFAULT_YEAR) q.year = String(yearFilter.value)
  if (monthFilter.value !== '') q.month = String(monthFilter.value)
  if (dateFrom.value) q.from = dateFrom.value
  if (dateTo.value) q.to = dateTo.value
  if (currencyFilter.value) q.currency = currencyFilter.value
  if (overdueOnly.value) q.overdue = '1'
  if (unpaidOnly.value) q.unpaid = '1'
  if (bookedFilter.value) q.booked = bookedFilter.value
  if (search.value) q.q = search.value
  return q
}

let suppressUrlSync = false
function syncFiltersToUrl() {
  if (suppressUrlSync) return
  router.replace({ query: buildQuery() })
}

function applyQueryToPage(q: Record<string, string>) {
  suppressUrlSync = true
  loadFiltersFromQuery(q)
  router.replace({ query: q })
  setTimeout(() => { suppressUrlSync = false }, 0)
  load(true)
}

watch([statusFilter, typeFilter, clientFilter, yearFilter, monthFilter, dateFrom, dateTo,
       overdueOnly, unpaidOnly, bookedFilter, currencyFilter], () => {
  syncFiltersToUrl()
  load(true)
})
// Když se vyčistí rok (vše/range), automaticky zrušit i měsíční filtr.
watch(yearFilter, (y) => { if (y === '') monthFilter.value = '' })
watch([dateFrom, dateTo], ([f, to]) => { if (f || to) monthFilter.value = '' })
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { syncFiltersToUrl(); load(true) }, 300)
})

// Reset filtrů při menu link click (route.query je prázdná).
watch(() => route.query, (newQ) => {
  if (Object.keys(newQ).length === 0) {
    suppressUrlSync = true
    statusFilter.value = ''
    typeFilter.value = ''
    clientFilter.value = ''
    yearFilter.value = DEFAULT_YEAR
    monthFilter.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    overdueOnly.value = false
    unpaidOnly.value = false
    bookedFilter.value = ''
    currencyFilter.value = ''
    search.value = ''
    setTimeout(() => { suppressUrlSync = false }, 0)
  }
})

const loadedCount = computed(() => groups.value.reduce((s, g) => s + g.count, 0))

const navigateRow = useRowLink()
function openInvoice(inv: InvoiceListItem, e?: MouseEvent) {
  navigateRow(`/invoices/${inv.id}`, e)
}

// Work Report modal: otevíráno z buttonu "Výkaz" v sloupci Stav.
const wrModalOpen = ref(false)
const wrModalInvoiceId = ref(0)
function openWorkReport(id: number) {
  wrModalInvoiceId.value = id
  wrModalOpen.value = true
}

// Year dropdown — distinct roky z `invoices` aktuálního supplier (issue #33).
// Composable doplňuje aktuální + minulý rok + aktuálně zvolený rok z URL.
const yearOptions = useYearOptions('invoices', yearFilter)

// `tm()` vrací raw translation message (pole), kdežto `t()` na poli vrátí stringified verzi.
// `rt()` zformátuje jednotlivé položky pole (pro případnou interpolaci).
const monthOptions = computed(() => (tm('common.months_short') as unknown as string[]).map(m => rt(m)))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('invoice.subtitle_grouping') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap justify-end">
        <RouterLink
          v-if="auth.canWrite('invoices.create') || auth.isDemo"
          to="/invoices/new"
          :class="btnFilled('primary')"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('invoice.new') }}
        </RouterLink>
      </div>
    </div>

    <!-- Hromadné akce jedou v plovoucí liště u spodní hrany: uživatel zaškrtává
         řádky dole v tabulce, kdežto v hlavičce byly akce mimo zorné pole a
         navíc při každém výběru odsouvaly tlačítko „Nová faktura". -->
    <BulkActionBar :count="selectedIds.length" @clear="selectedIds = []">
        <button v-if="selectedIds.length > 0 && auth.canRead('utilities.export')"
          @click="openBulkPdfExport"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 12l-4-4m4 4l4-4M4 20h16" /></svg>
          {{ t('invoice.bulk_pdf', { n: selectedIds.length }) }}
        </button>
        <button v-if="(issuableSelected.length > 0) && auth.canWrite('invoices.issue')"
          @click="bulkIssue"
          :disabled="bulkBusy"
          :class="btnFilled('success')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_issue', { n: issuableSelected.length }) }}
        </button>
        <button v-if="(selectedIds.length > 0) && auth.canWrite('invoices.issue') && !auth.isClientRole"
          @click="bulkReissue"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_reissue', { n: selectedIds.length }) }}
        </button>
        <button v-if="(markPayableSelected.length > 0) && auth.canWrite('invoices.mark_paid')"
          @click="bulkMarkPaid"
          :disabled="bulkBusy"
          :class="btnOutline('success')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_mark_paid', { n: markPayableSelected.length }) }}
        </button>
        <button v-if="(sendableSelected.length > 0) && auth.canWrite('invoices.send')"
          @click="bulkSend"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_send', { n: sendableSelected.length }) }}
        </button>
        <button v-if="(reminderSelected.length > 0) && auth.canWrite('invoices.reminder') && !auth.isClientRole"
          @click="bulkSendReminders"
          :disabled="bulkBusy"
          :class="btnOutline('warning')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_reminder', { n: reminderSelected.length }) }}
        </button>
        <button v-if="(postableSelected.length > 0) && auth.canWrite('accounting.journal.post') && !auth.isClientRole"
          @click="bulkPost"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.clipboardCheck" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_post', { n: postableSelected.length }) }}
        </button>
    </BulkActionBar>

    <!-- Filtry -->
    <FilterBar
      :active-count="activeFilterCount"
      collapsible
      :chips="filterChips"
      @clear="clearFilter"
      @clear-all="clearAllFilters"
    >
      <template #primary>
        <!-- Hledání je nejpoužívanější prvek lišty, takže dostává nejvíc místa
             a ikonu lupy uvnitř — dřív bylo nejmenší z deseti ovládacích prvků. -->
        <div class="relative flex-1 min-w-56">
          <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
          </svg>
          <input
            v-model="search"
            type="search"
            :placeholder="t('invoice.search_placeholder')"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
          />
        </div>
      </template>
        <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('invoice.all_statuses') }}</option>
          <option value="draft">{{ t('status.draft') }}</option>
          <option value="issued">{{ t('status.issued') }}</option>
          <option value="sent">{{ t('status.sent') }}</option>
          <option value="reminded">{{ t('status.reminded') }}</option>
          <option value="paid">{{ t('status.paid') }}</option>
          <option value="cancelled">{{ t('status.cancelled') }}</option>
        </select>
        <select v-model="typeFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('invoice.all_types') }}</option>
          <option value="invoice">{{ t('type.invoice') }}</option>
          <option value="proforma">{{ t('type.proforma') }}</option>
          <option value="credit_note">{{ t('type.credit_note') }}</option>
        </select>
        <div class="min-w-48 flex-1 max-w-xs">
          <SearchableSelect
            :model-value="clientFilter === '' ? null : clientFilter"
            @update:model-value="(v) => clientFilter = v === null ? '' : v"
            :options="clients.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
            :placeholder="t('project.all_clients')"
          />
        </div>
        <select v-model="currencyFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('invoice.all_currencies') }}</option>
          <option v-for="c in currencies" :key="c.id" :value="c.code">{{ c.code }}</option>
        </select>
        <select v-model="yearFilter" :disabled="!!dateFrom || !!dateTo"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50">
          <option value="">{{ t('invoice.all_years') }}</option>
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="monthFilter" :disabled="!!dateFrom || !!dateTo || yearFilter === ''"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50"
          :title="t('invoice.month_filter')">
          <option :value="''">{{ t('invoice.all_months') }}</option>
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <input v-model="dateFrom" type="date" placeholder="Od"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" title="Datum od" />
        <input v-model="dateTo" type="date" placeholder="Do"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" title="Datum do" />
        <button v-if="dateFrom || dateTo" @click="dateFrom = ''; dateTo = ''"
          class="cursor-pointer h-9 px-2 text-xs text-neutral-500 hover:text-neutral-700">{{ t('invoice.clear_date_filter') }}</button>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="overdueOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('invoice.overdue_only') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="unpaidOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('invoice.unpaid_only') }}
        </label>
        <select v-if="isDoubleEntry" v-model="bookedFilter"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
          :title="t('common.booked_filter_all')">
          <option value="">{{ t('common.booked_filter_all') }}</option>
          <option value="1">{{ t('common.booked_badge') }}</option>
          <option value="0">{{ t('common.unbooked_badge') }}</option>
        </select>
      <template #actions>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </template>
    </FilterBar>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="8" :cols="7" />
    </div>

    <div v-else-if="!groups.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState
        :title="auth.isClientRole ? t('invoice.empty_client') : t('invoice.no_data')"
        :cta="auth.canWrite('invoices.create') || auth.isDemo ? t('invoice.issue_first') : undefined"
        to="/invoices/new" />
    </div>

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('invoice.summary_count', { n: total, m: groups.length }) }}</span>
        <span v-if="total > loadedCount">{{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
      </div>

      <!-- Skupiny po měsících -->
      <section v-for="g in groups" :key="g.month" class="mb-5">
        <!-- Měsíční rozdělovník ve stylu účetní knihy: název měsíce vlevo, součet
             v mono vpravo, mezi tím vzduch. Sticky, protože při dvaceti řádcích
             na obrazovce je „ve kterém měsíci jsem" ta nejčastější otázka. -->
        <header class="sticky top-16 z-[5] flex flex-wrap items-center justify-between gap-x-4 gap-y-1 bg-neutral-50/92 backdrop-blur-md border border-neutral-200 rounded-t-lg px-4 py-2.5 mb-0">
          <div class="flex items-baseline gap-2.5 shrink-0">
            <h2 class="text-[13px] font-semibold uppercase tracking-[0.16em] text-neutral-800">{{ formatMonth(g.month) }}</h2>
            <span class="text-[11px] text-neutral-500 tabular-nums">{{ g.count }} {{ g.count === 1 ? t('invoice.doc_1') : (g.count < 5 ? t('invoice.doc_2_4') : t('invoice.doc_5plus')) }}</span>
          </div>
          <span class="hidden sm:block flex-1 h-px bg-gradient-to-r from-neutral-200 to-transparent" aria-hidden="true"></span>
          <!-- Na mobilu se součty musí umět zalomit — `shrink-0` by je vytlačilo
               za pravou hranu a vyrobilo vodorovný scroll celé stránky. -->
          <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-0.5 min-w-0 text-xs">
            <span v-for="tot in g.totals_per_currency" :key="tot.currency" class="font-mono">
              <span class="text-neutral-500">{{ tot.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoney(tot.with_vat, tot.currency) }}</span>
              <span v-if="tot.draft_with_vat !== 0" class="ml-1 text-primary-600"
                :title="t('invoice.prediction_hint', { amount: formatMoney(tot.draft_with_vat, tot.currency) })">
                → {{ formatMoney(tot.with_vat + tot.draft_with_vat, tot.currency) }}
                <span class="text-[10px] uppercase tracking-wide text-primary-500">{{ t('invoice.prediction') }}</span>
              </span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-surface border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first" :class="tbl.densityClass.value">
            <thead class="bg-neutral-50/70 text-neutral-500 text-[11px] uppercase tracking-[0.11em] border-b border-neutral-200">
              <tr>
                <th class="px-2 py-2 w-10 text-center">
                  <input
                    type="checkbox"
                    :checked="isGroupSelected(g)"
                    :indeterminate="isGroupSelectionPartial(g)"
                    @change="toggleGroupSelected(g)"
                    :aria-label="t('invoice.select_month', { month: formatMonth(g.month) })"
                    :title="t('invoice.select_month', { month: formatMonth(g.month) })"
                    class="w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                  />
                </th>
                <th v-if="tbl.isVisible('number')" class="text-left px-4 py-2 font-medium w-32">Var. symbol</th>
                <th v-if="tbl.isVisible('client')" class="text-left px-4 py-2 font-medium">{{ t('invoice.client_project') }}</th>
                <th v-if="tbl.isVisible('type')" class="text-center px-4 py-2 font-medium">Typ</th>
                <th v-if="tbl.isVisible('issued')" class="text-center px-4 py-2 font-medium">DUZP / Vystaveno</th>
                <th v-if="tbl.isVisible('due')" class="text-center px-4 py-2 font-medium">Splatnost</th>
                <th v-if="tbl.isVisible('amount')" class="text-right px-4 py-2 font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th v-if="tbl.isVisible('status')" class="text-center px-4 py-2 font-medium">Stav</th>
                <th v-if="tbl.isVisible('paid_at')" class="text-center px-4 py-2 font-medium">{{ t('invoice.col_paid_at') }}</th>
                <th v-if="tbl.isVisible('payment_method')" class="text-center px-4 py-2 font-medium">{{ t('payment_method.label') }}</th>
                <th v-if="tbl.isVisible('booked_at')" class="text-center px-4 py-2 font-medium">{{ t('invoice.col_booked_at') }}</th>
                <th v-if="tbl.isVisible('exchange_rate')" class="text-right px-4 py-2 font-medium">{{ t('invoice.col_exchange_rate') }}</th>
                <th v-if="tbl.isVisible('amount_czk')" class="text-right px-4 py-2 font-medium">{{ t('invoice.col_amount_czk') }}</th>
                <th v-if="tbl.isVisible('locked')" class="text-center px-2 py-2 font-medium w-8">
                  <span class="sr-only">{{ t('lock.column') }}</span>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100" :class="staggerRows ? 'stagger-in' : ''">
              <tr
                v-for="(inv, ri) in g.invoices"
                :key="inv.id"
                @click="openInvoice(inv, $event)"
                @auxclick.prevent="openInvoice(inv, $event)"
                class="cursor-pointer hover:bg-neutral-50 transition"
                :class="invoiceRowClass(inv.due_date, inv.status)"
                :style="staggerRows ? { '--i': ri } : undefined"
              >
                <td class="px-2 py-2.5 text-center" @click.stop>
                  <input
                    type="checkbox"
                    :checked="selectedIds.includes(inv.id)"
                    @change="toggleSelected(inv.id)"
                    class="w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                  />
                </td>
                <td v-if="tbl.isVisible('number')" class="px-4 py-2.5 font-mono text-xs">
                  <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                  <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                </td>
                <td v-if="tbl.isVisible('client')" class="px-4 py-2.5">
                  <div class="font-medium text-neutral-900">{{ inv.client_company_name }}</div>
                  <div v-if="inv.project_name" class="text-xs text-neutral-500 truncate max-w-md">{{ inv.project_name }}</div>
                </td>
                <td v-if="tbl.isVisible('type')" class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ typeLabel(inv.invoice_type) }}</td>
                <td v-if="tbl.isVisible('issued')" class="px-4 py-2.5 text-center text-xs">
                  <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                </td>
                <td v-if="tbl.isVisible('due')" class="px-4 py-2.5 text-center text-xs">
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : 'text-neutral-600'">
                    {{ formatDate(inv.due_date) }}
                  </span>
                </td>
                <td
                  v-if="tbl.isVisible('amount')"
                  class="amount-cell px-4 py-2.5 text-right font-mono font-semibold text-neutral-900"
                  :style="{ '--bar': amountBarWidth(inv, g) }"
                >
                  {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                </td>
                <td v-if="tbl.isVisible('status')" class="px-4 py-2.5 text-center" @click.stop>
                  <!-- Pro koncepty (s právem editace) zobraz tlačítko "Výkaz" místo "KONCEPT" badge — rychlý přístup k modalu. -->
                  <button v-if="inv.status === 'draft' && inv.invoice_type !== 'tax_document' && auth.canWrite('invoices')"
                    @click="openWorkReport(inv.id)"
                    class="cursor-pointer text-xs px-2 py-0.5 rounded border border-primary-500/40 text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1"
                    :title="t('invoice.wr_btn')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                    {{ t('invoice.wr_btn') }}
                  </button>
                  <span v-else class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(displayStatus(inv.status, inv.payment_status))">
                    {{ statusLabel(displayStatus(inv.status, inv.payment_status)) }}
                  </span>
                  <span v-if="inv.sent_at" class="ml-1 text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                    :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                  <span v-if="inv.reminder_count > 0" class="ml-1 text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600 font-semibold"
                    :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                </td>
                <td v-if="tbl.isVisible('paid_at')" class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  <span v-if="inv.paid_at">{{ formatDate(inv.paid_at) }}</span>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td v-if="tbl.isVisible('payment_method')" class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  {{ t(`payment_method.${inv.payment_method || 'bank_transfer'}`) }}
                </td>
                <td v-if="tbl.isVisible('booked_at')" class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  <span v-if="inv.booked_at">{{ formatDate(inv.booked_at) }}</span>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td v-if="tbl.isVisible('exchange_rate')" class="px-4 py-2.5 text-right font-mono text-xs text-neutral-600">
                  <span v-if="inv.currency !== 'CZK' && inv.exchange_rate">{{ formatRate(inv.exchange_rate) }}</span>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td v-if="tbl.isVisible('amount_czk')" class="px-4 py-2.5 text-right font-mono text-xs text-neutral-600">
                  <!-- BE konvence: CZK dokladům kurz vždy 1 (i kdyby byl v datech); bez kurzu nepočítat -->
                  <span v-if="inv.currency === 'CZK'">{{ formatMoney(inv.total_with_vat, 'CZK') }}</span>
                  <span v-else-if="inv.exchange_rate">{{ formatMoney(inv.total_with_vat * inv.exchange_rate, 'CZK') }}</span>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td v-if="tbl.isVisible('locked')" class="px-2 py-2.5 text-center">
                  <PostingBadge v-if="inv.locked?.journal_entry_id"
                    :booked-at="inv.booked_at" :journal-entry-id="inv.locked.journal_entry_id" />
                  <svg v-else-if="inv.locked?.is_locked" class="w-4 h-4 inline-block text-neutral-400"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                    role="img" :aria-label="lockTitle(inv)">
                    <title>{{ lockTitle(inv) }}</title>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" />
                  </svg>
                </td>
              </tr>
            </tbody>
          </table>
          </div>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden bg-surface border border-t-0 border-neutral-200 rounded-b-lg divide-y divide-neutral-100 overflow-hidden">
          <div
            v-for="inv in g.invoices"
            :key="`m-${inv.id}`"
            @click="openInvoice(inv, $event)"
            @auxclick.prevent="openInvoice(inv, $event)"
            class="cursor-pointer hover:bg-neutral-50 transition px-3 py-3"
            :class="invoiceRowClass(inv.due_date, inv.status)"
          >
            <div class="flex items-start gap-3">
              <input
                type="checkbox"
                :checked="selectedIds.includes(inv.id)"
                @change="toggleSelected(inv.id)"
                @click.stop
                class="mt-0.5 w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                  <div class="font-medium text-neutral-900 truncate">{{ inv.client_company_name }}</div>
                  <div class="font-mono text-sm font-semibold whitespace-nowrap">
                    {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
                  <div class="truncate">
                    <span class="font-mono">
                      <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                      <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                    </span>
                    <span class="text-neutral-400"> · </span>
                    <span>{{ typeLabel(inv.invoice_type) }}</span>
                    <span v-if="inv.project_name" class="text-neutral-400"> · </span>
                    <span v-if="inv.project_name" class="truncate">{{ inv.project_name }}</span>
                  </div>
                </div>
                <div class="flex items-center justify-between gap-2 mt-2">
                  <div class="text-xs text-neutral-600 whitespace-nowrap">
                    <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                    <span class="text-neutral-400"> → </span>
                    <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                      {{ formatDate(inv.due_date) }}
                    </span>
                  </div>
                  <div class="flex items-center gap-1 flex-wrap justify-end" @click.stop>
                    <PostingBadge v-if="inv.locked?.journal_entry_id"
                      :booked-at="inv.booked_at" :journal-entry-id="inv.locked.journal_entry_id" />
                    <svg v-else-if="inv.locked?.is_locked" class="w-3.5 h-3.5 text-neutral-400"
                      fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                      role="img" :aria-label="lockTitle(inv)">
                      <title>{{ lockTitle(inv) }}</title>
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" />
                    </svg>
                    <span v-if="inv.sent_at" class="text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                      :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                    <span v-if="inv.reminder_count > 0" class="text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600 font-semibold"
                      :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                    <!-- Pro koncepty (s právem editace) zobraz tlačítko "Výkaz" místo "KONCEPT" badge — stejně jako v desktop tabulce. -->
                    <button v-if="inv.status === 'draft' && inv.invoice_type !== 'tax_document' && auth.canWrite('invoices')"
                      @click="openWorkReport(inv.id)"
                      class="cursor-pointer text-xs px-2 py-0.5 rounded border border-primary-500/40 text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1"
                      :title="t('invoice.wr_btn')">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                      {{ t('invoice.wr_btn') }}
                    </button>
                    <span v-else class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                      {{ statusLabel(inv.status) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div v-if="page < pages" class="text-center mt-3">
        <button @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-2 shadow-sm">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>

    <div v-if="bulkPdfOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="bulkPdfOpen = false">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md p-5 space-y-4">
        <div>
          <h2 class="text-lg font-semibold">{{ t('invoice.bulk_pdf_title') }}</h2>
          <p class="text-sm text-neutral-500 mt-1">{{ t('invoice.bulk_pdf_hint', { n: selectedPdfIds.length }) }}</p>
        </div>
        <label class="flex items-start gap-3 cursor-pointer rounded-md border border-neutral-200 bg-neutral-50 p-3">
          <input v-model="bulkPdfSign" type="checkbox"
            class="mt-0.5 w-4 h-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          <span>
            <span class="block text-sm font-medium text-neutral-800">{{ t('invoice.bulk_pdf_sign') }}</span>
            <span class="block text-xs text-neutral-500 mt-0.5">{{ t('invoice.bulk_pdf_sign_hint') }}</span>
          </span>
        </label>
        <p v-if="selectedPdfIds.length > 100" class="text-sm text-danger-500">
          {{ t('invoice.bulk_pdf_limit') }}
        </p>
        <div class="flex flex-wrap justify-end gap-2 pt-1">
          <button type="button" @click="bulkPdfOpen = false" :disabled="bulkBusy"
            :class="btnOutline('neutral')">
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="bulkExportPdf" :disabled="bulkBusy || selectedPdfIds.length > 100"
            :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 12l-4-4m4 4l4-4M4 20h16" /></svg>
            {{ bulkBusy ? t('common.loading') : t('invoice.bulk_pdf_download') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Work report modal — otevřený z buttonu "Výkaz" v sloupci Stav. -->
    <WorkReportModal v-if="wrModalInvoiceId > 0"
      v-model="wrModalOpen"
      :invoice-id="wrModalInvoiceId"
      @saved="load(true)" />
  </div>
</template>
