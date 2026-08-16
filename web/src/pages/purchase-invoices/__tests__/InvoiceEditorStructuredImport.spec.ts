import { flushPromises, shallowMount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  importStructured: vi.fn(),
  get: vi.fn(),
  expenseSuggestions: vi.fn(),
}))

// `locale` musí být v mocku taky: editor ho čte v computed formátování data,
// takže bez něj render spadne na `locale.value` a wrapper zůstane prázdný.
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs-CZ') }),
}))

vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    importStructured: m.importStructured,
    get: m.get,
    expenseSuggestions: m.expenseSuggestions,
    pdfUrl: () => '',
  },
}))

vi.mock('@/api/invoices', () => ({ PAYMENT_METHODS: [] }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: vi.fn().mockResolvedValue([{ id: 1, rate_percent: 21, is_default: true }]),
    currencies: vi.fn().mockResolvedValue([{ id: 1, code: 'CZK', is_default: true }]),
    units: vi.fn().mockResolvedValue([{ id: 1, code: 'ks', is_default: true }]),
  },
}))
vi.mock('@/api/stock', () => ({ stockApi: { searchItems: vi.fn() } }))
vi.mock('@/api/expenseCategories', () => ({ expenseCategoriesApi: { list: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/settings', () => ({ settingsApi: {} }))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/clients', () => ({ clientsApi: { getVatStatus: vi.fn() } }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))
vi.mock('@/directives/vMath', () => ({ evalMath: () => null }))
vi.mock('@/composables/useRowFocus', () => ({ focusLastRow: vi.fn() }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), info: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (error: unknown) => String(error) }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    isClientRole: true,
    hasCommercialFeatures: false,
    isDemo: false,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { stock_enabled: false } }),
}))

import InvoiceEditor from '@/pages/purchase-invoices/InvoiceEditor.vue'

function importedInvoice() {
  return {
    id: 42,
    vendor_id: 7,
    vendor_invoice_number: 'SYNTHETIC-42',
    varsymbol: '42',
    document_kind: 'invoice',
    issue_date: '2026-08-01',
    tax_date: '2026-08-01',
    due_date: '2026-08-15',
    received_at: '2026-08-02',
    currency_id: 1,
    exchange_rate: null,
    exchange_rate_date: null,
    exchange_rate_source: 'manual',
    reverse_charge: false,
    prices_include_vat: false,
    is_fixed_asset: false,
    vat_deduction: 'full',
    vat_deduction_percent: 100,
    tax_deductible: true,
    language: 'cs',
    note_above_items: null,
    note_below_items: null,
    payment_account_number: null,
    payment_bank_code: null,
    payment_iban: null,
    payment_bic: null,
    payment_variable_symbol: null,
    payment_method: 'bank_transfer',
    advance_paid_amount: 0,
    rounding: 0,
    payment_currency_id: null,
    payment_exchange_rate: null,
    paid_amount_payment_ccy: null,
    paid_amount_invoice_ccy: null,
    exchange_diff_base: null,
    expense_category_id: null,
    vat_classification_code: null,
    parent_purchase_invoice_id: null,
    items: [{
      id: 5,
      description: 'Syntetická položka',
      quantity: 1,
      unit: 'ks',
      unit_price_without_vat: 100,
      vat_rate_id: 1,
      order_index: 0,
      expense_kind: null,
      accrual_from: null,
      accrual_to: null,
      stock_item_id: null,
    }],
    extraction_warning: null,
    vat_overrides: [],
    vat_allocations: [],
    vendor_is_vat_payer: true,
    ai_posting_suggestion: null,
    pdf_path: null,
  }
}

describe('InvoiceEditor — strukturovaný import', () => {
  beforeEach(() => {
    m.importStructured.mockReset().mockResolvedValue({
      purchase_invoice_id: 42,
      purchase_invoice_ids: [42],
      source: 'isdoc',
      duplicate: false,
    })
    m.get.mockReset().mockResolvedValue(importedInvoice())
    m.expenseSuggestions.mockReset().mockResolvedValue({ items: {} })
  })

  it('po přechodu z /new načte importovaný koncept bez reloadu stránky', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/purchase-invoices/new', component: InvoiceEditor },
        { path: '/purchase-invoices/:id/edit', component: InvoiceEditor },
      ],
    })
    await router.push('/purchase-invoices/new')
    await router.isReady()

    const wrapper = shallowMount(InvoiceEditor, {
      global: {
        plugins: [router],
        directives: { math: {} },
      },
    })
    await flushPromises()

    const file = new File(['<Invoice/>'], 'synthetic.isdoc', { type: 'application/xml' })
    wrapper.findComponent({ name: 'PdfDropzone' }).vm.$emit('file-dropped', file)
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/purchase-invoices/42/edit')
    expect(m.get).toHaveBeenCalledWith(42)
    expect(wrapper.find('input[maxlength="50"]').element).toHaveProperty('value', 'SYNTHETIC-42')
  })
})
