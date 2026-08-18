<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import {
  cashApi, type CashRegister, type CashVatLine, type CashPurpose,
  type CashDocType, type UnpaidDocumentOption, type CreateCashDocumentPayload,
  type CashRulePreset,
} from '@/api/cash'
import { accountingApi, type ChartAccount, type PostingRuleMap } from '@/api/accounting'
import { taxConstantsApi, type TaxConstantsYear } from '@/api/taxConstants'
import { clientsApi, type Client } from '@/api/clients'
import { cashErrorMessage, cashWarningMessage } from '@/api/cashErrors'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { formatMoney } from '@/composables/useFormat'
import CashVatBreakdown from '@/components/cash/CashVatBreakdown.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const supplierStore = useSupplierStore()

// Daňová evidence (Epic DE §6): pokladna běží no-journal path — MD/D náhled zaúčtování
// je akruální (podvojný) koncept a v tomto režimu nedává smysl, proto se skryje.
const isTaxEvidence = computed(() => supplierStore.currentSupplier?.accounting_mode === 'tax_evidence')

const today = new Date().toISOString().slice(0, 10)

const registers = ref<CashRegister[]>([])
const accounts = ref<ChartAccount[]>([])
const rules = ref<PostingRuleMap>({})
const taxYears = ref<TaxConstantsYear[]>([])
const clients = ref<Client[]>([])
const saving = ref(false)
const error = ref('')

const form = reactive({
  register_id: '' as number | '',
  doc_type: (route.query.doc_type === 'out' ? 'out' : 'in') as CashDocType,
  purpose: 'sale' as CashPurpose,
  issue_date: today,
  tax_date: today,
  partner_name: '',
  partner_ic: '',
  partner_dic: '',
  description: '',
  vat_mode: 'none' as 'none' | 'vat',
  total_amount: null as number | null,
  invoice_id: null as number | null,
  purchase_invoice_id: null as number | null,
  counter_account_code: '',
  rule_key: '',
})
const vatLines = ref<CashVatLine[]>([])

const selectedReg = computed<CashRegister | null>(() =>
  form.register_id !== '' ? registers.value.find(r => r.id === form.register_id) ?? null : null,
)
const registerAccount = computed(() => selectedReg.value?.account_code || '211')
// Valutová pokladna (§11): měna dokladu = měna pokladny; CZK ekvivalent počítá BE kurzem ČNB.
const registerCurrency = computed(() => (selectedReg.value?.currency_code || 'CZK').toUpperCase())
const isForeign = computed(() => registerCurrency.value !== 'CZK')

const purposeOptions = computed<CashPurpose[]>(() => {
  // Valutová pokladna v1: jen prodej/nákup/ostatní (úhrady faktur a převody = korunová pokladna).
  if (isForeign.value) return form.doc_type === 'in' ? ['sale', 'other'] : ['purchase', 'other']
  return form.doc_type === 'in'
    ? ['sale', 'invoice_payment', 'transfer', 'other']
    : ['purchase', 'purchase_payment', 'transfer', 'other']
})

const isTaxDoc = computed(() => form.purpose === 'sale' || form.purpose === 'purchase')
const isPayment = computed(() => form.purpose === 'invoice_payment' || form.purpose === 'purchase_payment')
const isTransfer = computed(() => form.purpose === 'transfer')
const isOther = computed(() => form.purpose === 'other')

// Sazby DPH z číselníku pro rok dokladu (jen > 0 — A4/O5c).
const availableRates = computed<number[]>(() => {
  const year = Number((form.tax_date || form.issue_date || '').slice(0, 4))
  let entry = taxYears.value.find(y => y.year === year)
  if (!entry && taxYears.value.length) entry = [...taxYears.value].sort((a, b) => b.year - a.year)[0]
  if (!entry) return [21, 12]
  const rates = [entry.data.vat_rate_standard, entry.data.vat_rate_reduced]
    .map(r => Math.round(Number(r) * 100) / 100)
    .filter(r => r > 0)
  return [...new Set(rates)].sort((a, b) => b - a)
})

const counterAccounts = computed(() =>
  accounts.value
    .filter(a => a.is_active && a.account_code !== registerAccount.value)
    .sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

// ── Předvolby „co to je" pro purpose=other ─────────────────────────────────
// Server nabízí kontace s nohou na 211 (a bez vlastního purpose). Volba předvolby
// posílá `rule_key`, ne protiúčet — doklad si tak zachová vazbu na kontaci včetně
// per-tenant override. Backend vyžaduje PRÁVĚ JEDNO z rule_key / counter_account_code,
// proto se v `pickPreset()` druhé pole vždy vyprázdní.
const rulePresets = ref<CashRulePreset[]>([])
const presetsForDocType = computed(() => rulePresets.value.filter(p => p.doc_type === form.doc_type))

function pickPreset(key: string) {
  form.rule_key = key
  if (key) form.counter_account_code = ''
}
function pickCounterAccount(code: string) {
  form.counter_account_code = code
  if (code) form.rule_key = ''
}

/** Popisek předvolby: kontace je globální (bez i18n), proto se zobrazí i protiúčet. */
function presetLabel(p: CashRulePreset): string {
  const name = accounts.value.find(a => a.account_code === p.counter_account_code)?.name
  return name ? `${p.description} (${p.counter_account_code} — ${name})` : `${p.description} (${p.counter_account_code})`
}

function ruleAccount(key: string, side: 'debit' | 'credit', fallback: string): string {
  const r = rules.value[key]
  const code = r ? (side === 'debit' ? r.debit_account_code : r.credit_account_code) : null
  return code || fallback
}

onMounted(async () => {
  try {
    // G6 (audit 2026-07): osnova/kontace jsou double_entry-only (gatované 403 pro
    // tax_evidence) — MD/D náhled je v DE stejně skrytý (isTaxEvidence výše), takže
    // se ani nefetchuje, jinak by Promise.all shodil i načtení pokladen (registers).
    const [regs, accs, ruleMap, years, presets] = await Promise.all([
      cashApi.listRegisters(),
      isTaxEvidence.value ? Promise.resolve<ChartAccount[]>([]) : accountingApi.listAccounts(),
      isTaxEvidence.value ? Promise.resolve<PostingRuleMap>({}) : accountingApi.listPostingRules(),
      taxConstantsApi.list(),
      // Předvolby stojí na osnově a kontacích → v tax_evidence nedávají smysl (stejný
      // důvod jako u listPostingRules výše).
      isTaxEvidence.value ? Promise.resolve<CashRulePreset[]>([]) : cashApi.listRulePresets(),
    ])
    registers.value = regs
    accounts.value = accs
    rules.value = ruleMap
    taxYears.value = years
    rulePresets.value = presets
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
  // Výběr pokladny z query, jinak výchozí.
  const qReg = Number(route.query.register_id || 0)
  if (qReg > 0 && registers.value.some(r => r.id === qReg)) form.register_id = qReg
  else form.register_id = (registers.value.find(r => r.is_default) ?? registers.value[0])?.id ?? ''
  try { clients.value = (await clientsApi.list({ per_page: 100 })).data } catch { clients.value = [] }
})

// Přepínač typu / pokladny → resetuj účel na první platný (valutová pokladna nabízí méně účelů).
watch([() => form.doc_type, () => form.register_id], () => {
  if (!purposeOptions.value.includes(form.purpose)) form.purpose = purposeOptions.value[0]
  // Předvolby jsou směrové (211 na MD = příjem, na D = výdej) — po přepnutí
  // PPD/VPD by zvolená kontace mířila na opačnou stranu.
  form.rule_key = ''
})

// Účel bez DPH → vynuť vat_mode none; při KAŽDÉ změně účelu vyčisti výběr úhrady
// (přepnutí FV→PF by jinak drželo starou fakturu a zamčenou částku).
watch(() => form.purpose, () => {
  if (!isTaxDoc.value) form.vat_mode = 'none'
  form.rule_key = ''
  form.counter_account_code = ''
  form.invoice_id = null
  form.purchase_invoice_id = null
  selectedUnpaid.value = null
  unpaidQuery.value = ''
  unpaidOptions.value = []
})

// ── Našeptávač nezaplacených FV/PF ──────────────────────────────────────────
const unpaidQuery = ref('')
const unpaidOptions = ref<UnpaidDocumentOption[]>([])
const unpaidLoading = ref(false)
const selectedUnpaid = ref<UnpaidDocumentOption | null>(null)
let unpaidTimer: ReturnType<typeof setTimeout> | null = null

function onUnpaidSearch() {
  if (unpaidTimer) clearTimeout(unpaidTimer)
  unpaidTimer = setTimeout(async () => {
    const kind = form.purpose === 'invoice_payment' ? 'invoice' : 'purchase_invoice'
    unpaidLoading.value = true
    try { unpaidOptions.value = await cashApi.searchUnpaid(kind, unpaidQuery.value, 20) }
    catch { unpaidOptions.value = [] }
    finally { unpaidLoading.value = false }
  }, 300)
}

function pickUnpaid(o: UnpaidDocumentOption) {
  selectedUnpaid.value = o
  unpaidOptions.value = []
  unpaidQuery.value = o.number
  if (form.purpose === 'invoice_payment') { form.invoice_id = o.id; form.purchase_invoice_id = null }
  else { form.purchase_invoice_id = o.id; form.invoice_id = null }
  form.partner_name = o.partner_name
  form.description = t(`cash.purpose.${form.purpose}`) + ' ' + o.number
  form.total_amount = o.remaining
}
// PF: úhrada jen v plné výši (R4) → částka readonly.
const amountReadonly = computed(() => form.purpose === 'purchase_payment' && selectedUnpaid.value !== null)

// ── Klientský hint / validace ───────────────────────────────────────────────
// Práh KH je inkluzivní (>= 10 000 → A.4/B.2), proto >= i tady. U valutové pokladny
// je zadaná částka v cizí měně a FE nezná kurz → práh 10 000 CZK vyhodnotí BE (CZK
// ekvivalent); klientský hint se pro cizí měnu vypne, ať nesrovnává EUR s 10 000 CZK.
const purchaseOverLimit = computed(() =>
  !isForeign.value && form.purpose === 'purchase' && form.vat_mode === 'vat' && Number(form.total_amount) >= 10000,
)
const saleOver10k = computed(() =>
  !isForeign.value && form.purpose === 'sale' && form.vat_mode === 'vat' && Number(form.total_amount) >= 10000 && !form.partner_dic.trim(),
)

// ── Live náhled zaúčtování (MD/D) ────────────────────────────────────────────
interface PreviewLine { account_code: string; side: 'debit' | 'credit'; amount: number }
const previewLines = computed<PreviewLine[]>(() => {
  const total = Number(form.total_amount) || 0
  const cash = registerAccount.value
  const vat = form.vat_mode === 'vat'
  const baseSum = vat ? vatLines.value.reduce((s, l) => s + l.base_amount, 0) : total
  const lines: PreviewLine[] = []
  const push = (code: string, side: 'debit' | 'credit', amount: number) => lines.push({ account_code: code, side, amount })
  switch (form.purpose) {
    case 'sale':
      push(cash, 'debit', total)
      push(ruleAccount('cash.revenue', 'credit', '602'), 'credit', baseSum)
      if (vat) for (const l of vatLines.value) push('343', 'credit', l.vat_amount)
      break
    case 'purchase':
      push(ruleAccount('cash.purchase', 'debit', '501'), 'debit', baseSum)
      if (vat) for (const l of vatLines.value) push('343', 'debit', l.vat_amount)
      push(cash, 'credit', total)
      break
    case 'invoice_payment':
      push(cash, 'debit', total)
      push(ruleAccount('payment.receivable.cash', 'credit', '311'), 'credit', total)
      break
    case 'purchase_payment':
      push(ruleAccount('payment.payable.cash', 'debit', '321'), 'debit', total)
      push(cash, 'credit', total)
      break
    case 'transfer':
      if (form.doc_type === 'in') {
        push(cash, 'debit', total)
        push(ruleAccount('cash.transfer.frombank', 'credit', '261'), 'credit', total)
      } else {
        push(ruleAccount('cash.deposit.cashtobank', 'debit', '261'), 'debit', total)
        push(cash, 'credit', total)
      }
      break
    case 'other': {
      // Při zvolené předvolbě protiúčet odvodí server z kontace — v náhledu ho
      // vezmeme z předvolby, ať MD/D sedí s tím, co se doopravdy zaúčtuje.
      const fromPreset = form.rule_key
        ? rulePresets.value.find(p => p.rule_key === form.rule_key)?.counter_account_code
        : ''
      const counter = form.counter_account_code || fromPreset || '—'
      if (form.doc_type === 'in') { push(cash, 'debit', total); push(counter, 'credit', total) }
      else { push(counter, 'debit', total); push(cash, 'credit', total) }
      break
    }
  }
  return lines
})
const previewDebit = computed(() => previewLines.value.filter(l => l.side === 'debit').reduce((s, l) => s + l.amount, 0))
const previewCredit = computed(() => previewLines.value.filter(l => l.side === 'credit').reduce((s, l) => s + l.amount, 0))

function accountName(code: string): string {
  return accounts.value.find(a => a.account_code === code)?.name ?? ''
}

// ── Uložení ──────────────────────────────────────────────────────────────────
const canSubmit = computed(() =>
  form.register_id !== '' && Number(form.total_amount) > 0 && form.description.trim() !== '' && !purchaseOverLimit.value && !saving.value,
)

async function save() {
  error.value = ''
  if (form.register_id === '') { error.value = t('cash.validation.register'); return }
  if (!(Number(form.total_amount) > 0)) { error.value = t('cash.validation.amount'); return }
  if (!form.description.trim()) { error.value = t('cash.validation.description'); return }
  if (purchaseOverLimit.value) { error.value = t('cash.form.purchase_over_10k_hint'); return }

  const payload: CreateCashDocumentPayload = {
    register_id: Number(form.register_id),
    doc_type: form.doc_type,
    purpose: form.purpose,
    issue_date: form.issue_date,
    description: form.description.trim(),
    total_amount: Number(form.total_amount),
    post: true,
  }
  // Valutová pokladna: zadaná částka je v měně pokladny; CZK ekvivalent dopočítá BE kurzem ČNB.
  if (isForeign.value) payload.amount_foreign = Number(form.total_amount)
  if (isTaxDoc.value) {
    payload.partner_name = form.partner_name.trim() || undefined
    payload.partner_ic = form.partner_ic.trim() || undefined
    payload.partner_dic = form.partner_dic.trim() || undefined
    if (form.vat_mode === 'vat') {
      payload.vat_mode = 'vat'
      payload.tax_date = form.tax_date || form.issue_date
      payload.vat_lines = vatLines.value
    }
  }
  if (form.purpose === 'invoice_payment' && form.invoice_id) payload.invoice_id = form.invoice_id
  if (form.purpose === 'purchase_payment' && form.purchase_invoice_id) payload.purchase_invoice_id = form.purchase_invoice_id
  if (isOther.value) {
    if (form.counter_account_code) payload.counter_account_code = form.counter_account_code
    else if (form.rule_key) payload.rule_key = form.rule_key
  }

  saving.value = true
  try {
    const res = await cashApi.createDocument(payload)
    toast.success(t('cash.new_document') + ' ' + (res.doc_number ?? ''))
    for (const w of res.warnings) toast.warning(cashWarningMessage(w, t))
    router.push('/accounting/cash')
  } catch (e: any) {
    error.value = cashErrorMessage(e, t)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('cash.new_document') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ form.doc_type === 'in' ? t('cash.type.in') : t('cash.type.out') }}</p>
      </div>
      <RouterLink to="/accounting/cash" class="text-sm text-neutral-500 hover:text-neutral-700">{{ t('common.back') }}</RouterLink>
    </div>

    <datalist id="cash-partners">
      <option v-for="c in clients" :key="c.id" :value="c.company_name" />
    </datalist>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Formulář -->
      <div :class="isTaxEvidence ? 'lg:col-span-3' : 'lg:col-span-2'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
        <!-- Hlavička -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.type') }}</label>
            <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden w-full">
              <button type="button" @click="form.doc_type = 'in'"
                class="cursor-pointer flex-1 h-10 text-sm font-medium"
                :class="form.doc_type === 'in' ? 'bg-success-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-50'">
                {{ t('cash.type.in_short') }}
              </button>
              <button type="button" @click="form.doc_type = 'out'"
                class="cursor-pointer flex-1 h-10 text-sm font-medium border-l border-neutral-300"
                :class="form.doc_type === 'out' ? 'bg-warning-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-50'">
                {{ t('cash.type.out_short') }}
              </button>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.register') }}</label>
            <select v-model="form.register_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
              <option v-for="r in registers" :key="r.id" :value="r.id">{{ r.name }} ({{ r.account_code }})</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.date') }}</label>
            <input v-model="form.issue_date" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <p class="text-xs text-neutral-400 -mt-2">{{ t('cash.form.number_hint') }}</p>

        <!-- Účel -->
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-2">{{ t('cash.col.link') }}</label>
          <div class="flex flex-wrap gap-2">
            <button v-for="p in purposeOptions" :key="p" type="button" @click="form.purpose = p"
              class="cursor-pointer px-3 h-9 text-sm rounded-md border"
              :class="form.purpose === p ? 'border-primary-500 bg-primary-50 text-primary-700 font-medium' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'">
              {{ t(`cash.purpose.${p}`) }}
            </button>
          </div>
        </div>

        <!-- (a) Prodej / Nákup -->
        <template v-if="isTaxDoc">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="sm:col-span-1">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.partner') }}</label>
              <input v-model="form.partner_name" list="cash-partners" type="text"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.partner_ic') }}</label>
              <input v-model="form.partner_ic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.partner_dic') }}</label>
              <input v-model="form.partner_dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <p v-if="form.purpose === 'sale'" class="text-xs text-neutral-500 bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2">
            {{ t('cash.form.duplicate_vat_hint') }}
          </p>
        </template>

        <!-- (b) Úhrada faktury -->
        <template v-if="isPayment">
          <div class="relative">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.pick_invoice') }}</label>
            <input v-model="unpaidQuery" @input="onUnpaidSearch" type="text"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <div v-if="unpaidLoading" class="text-xs text-neutral-400 mt-1">{{ t('common.loading') }}</div>
            <ul v-if="unpaidOptions.length" class="absolute z-10 mt-1 w-full bg-surface border border-neutral-200 rounded-md shadow-lg max-h-64 overflow-y-auto">
              <li v-for="o in unpaidOptions" :key="o.id" @click="pickUnpaid(o)"
                class="cursor-pointer px-3 py-2 text-sm hover:bg-neutral-50 flex items-center justify-between gap-2">
                <span><span class="font-mono">{{ o.number }}</span> · {{ o.partner_name }}</span>
                <span class="text-neutral-500 font-mono">{{ t('cash.form.remaining') }} {{ formatMoney(o.remaining) }}</span>
              </li>
            </ul>
          </div>
        </template>

        <!-- (c) Převod -->
        <p v-if="isTransfer" class="text-xs text-neutral-500 bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2">
          {{ t('cash.form.transfer_leg_hint') }}
        </p>

        <!-- (d) Ostatní -->
        <template v-if="isOther">
          <!-- Předvolba kontace („co to je") — vyplní protiúčet za uživatele. -->
          <div v-if="presetsForDocType.length">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.rule_key') }}</label>
            <select :value="form.rule_key" @change="pickPreset(($event.target as HTMLSelectElement).value)"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="">{{ t('cash.form.rule_key_custom') }}</option>
              <option v-for="p in presetsForDocType" :key="p.rule_key" :value="p.rule_key">{{ presetLabel(p) }}</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('cash.form.rule_key_hint') }}</p>
          </div>
          <!-- Volný protiúčet zůstává pro případy, které kontace nepokrývá. -->
          <div v-if="!form.rule_key">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.counter_account') }}</label>
            <select :value="form.counter_account_code" @change="pickCounterAccount(($event.target as HTMLSelectElement).value)"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="">—</option>
              <option v-for="a in counterAccounts" :key="a.id" :value="a.account_code">{{ a.account_code }} — {{ a.name }}</option>
            </select>
          </div>
        </template>

        <!-- Popis -->
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.description') }}</label>
          <input v-model="form.description" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>

        <!-- Částka + DPH přepínač -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">
              {{ t('cash.form.total_incl') }}
              <span v-if="isForeign" class="font-mono text-primary-600">({{ registerCurrency }})</span>
            </label>
            <input v-model.number="form.total_amount" type="number" step="0.01" min="0" :readonly="amountReadonly"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right"
              :class="{ 'bg-neutral-100': amountReadonly }" />
            <p v-if="isForeign" class="mt-1 text-xs text-neutral-500">{{ t('cash.form.foreign_hint') }}</p>
          </div>
          <div v-if="isTaxDoc">
            <label class="block text-sm font-medium text-neutral-700 mb-1">&nbsp;</label>
            <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden w-full">
              <button type="button" @click="form.vat_mode = 'none'"
                class="cursor-pointer flex-1 h-10 text-sm"
                :class="form.vat_mode === 'none' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-50'">
                {{ t('cash.form.vat_mode_none') }}
              </button>
              <button type="button" @click="form.vat_mode = 'vat'"
                class="cursor-pointer flex-1 h-10 text-sm border-l border-neutral-300"
                :class="form.vat_mode === 'vat' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-50'">
                {{ t('cash.form.vat_mode_vat') }}
              </button>
            </div>
          </div>
        </div>

        <!-- DUZP + DPH rozpad -->
        <template v-if="isTaxDoc && form.vat_mode === 'vat'">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.date') }} (DUZP)</label>
              <input v-model="form.tax_date" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <CashVatBreakdown v-model="vatLines" :total="Number(form.total_amount) || 0" :rates="availableRates" />
          <p v-if="form.purpose === 'purchase'" class="text-xs px-3 py-2 rounded-md"
            :class="purchaseOverLimit ? 'bg-danger-50 text-danger-600' : 'bg-neutral-50 text-neutral-500 border border-neutral-200'">
            {{ t('cash.form.purchase_over_10k_hint') }}
          </p>
          <p v-if="saleOver10k" class="text-xs px-3 py-2 rounded-md bg-warning-50 text-warning-600">
            {{ t('cash.warning.dic_missing_over_10k') }}
          </p>
          <p class="text-xs text-neutral-400">{{ t('cash.form.simplified_limit_hint') }}</p>
        </template>

        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>

        <div class="flex justify-end gap-2 border-t border-neutral-200 pt-3">
          <RouterLink to="/accounting/cash" :class="btnOutline('neutral')">{{ t('common.cancel') }}</RouterLink>
          <button @click="save" :disabled="!canSubmit" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </div>

      <!-- Live náhled zaúčtování (jen podvojné účetnictví — v daňové evidenci není journal) -->
      <div v-if="!isTaxEvidence" class="lg:col-span-1">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 lg:sticky lg:top-20">
          <h3 class="text-sm font-semibold text-neutral-700 mb-3">{{ t('cash.preview.title') }}</h3>
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left font-medium py-1">{{ t('cash.col.number') }}</th>
                <th class="text-right font-medium py-1 w-24">MD</th>
                <th class="text-right font-medium py-1 w-24">D</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(l, i) in previewLines" :key="i">
                <td class="py-1">
                  <span class="font-mono">{{ l.account_code }}</span>
                  <span class="text-neutral-500 text-xs ml-1 truncate">{{ accountName(l.account_code) }}</span>
                </td>
                <td class="py-1 text-right font-mono">
                  <template v-if="l.side === 'debit'">{{ formatMoney(l.amount) }}</template>
                </td>
                <td class="py-1 text-right font-mono">
                  <template v-if="l.side === 'credit'">{{ formatMoney(l.amount) }}</template>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-neutral-300 font-semibold">
                <td class="py-1">{{ t('cash.col.amount') }}</td>
                <td class="py-1 text-right font-mono">{{ formatMoney(previewDebit) }}</td>
                <td class="py-1 text-right font-mono">{{ formatMoney(previewCredit) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
