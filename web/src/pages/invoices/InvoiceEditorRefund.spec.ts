import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import type { SupplierBrief } from '@/api/auth'
import { useSupplierStore } from '@/stores/supplier'

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    vatRates: () => new Promise(() => {}),
    currencies: vi.fn(),
    units: vi.fn(),
  },
}))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: vi.fn() } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn() } }))

import InvoiceEditor from './InvoiceEditor.vue'

type Vm = {
  form: { invoice_type: string; currency: string; payment_method: string; rounding_mode: string; items: Array<Record<string, unknown>> }
  computed_totals: { amount_to_pay: number }
  isRefundInvoice: boolean
  hasNonPositiveAmountToPay: boolean
}

async function mountEditor(allowRefunds: boolean, prices: number[], type = 'invoice') {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSupplierStore().setAvailable([{ id: 1, is_vat_payer: false, allow_refund_invoices: allowRefunds } as SupplierBrief], 1, true)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices/new', component: InvoiceEditor }] })
  await router.push('/invoices/new')
  await router.isReady()
  const wrapper = shallowMount(InvoiceEditor, {
    global: {
      plugins: [router, pinia, createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      directives: { math: {} },
    },
  })
  const vm = wrapper.vm as unknown as Vm
  Object.assign(vm.form, {
    invoice_type: type,
    currency: 'CZK',
    payment_method: 'cash',
    rounding_mode: 'auto',
    items: prices.map(p => ({ quantity: 1, unit: 'ks', unit_price_without_vat: p, vat_rate_id: 0, vat_rate_snapshot: 0 })),
  })
  await wrapper.vm.$nextTick()
  return { wrapper, vm }
}

describe('InvoiceEditor refund settlement', () => {
  it('allows a negative invoice with a positive line when the supplier enabled refunds', async () => {
    const { wrapper, vm } = await mountEditor(true, [892, -1499.6])
    expect(vm.computed_totals.amount_to_pay).toBe(-608)
    expect(vm.isRefundInvoice).toBe(true)
    expect(vm.hasNonPositiveAmountToPay).toBe(false)
    wrapper.unmount()
  })

  it('keeps the error for an invoice with only negative lines', async () => {
    const { wrapper, vm } = await mountEditor(true, [-1500])
    expect(vm.isRefundInvoice).toBe(false)
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })

  it('keeps the error without the supplier switch', async () => {
    const { wrapper, vm } = await mountEditor(false, [892, -1499.6])
    expect(vm.isRefundInvoice).toBe(false)
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })

  it('never treats a proforma as a refund', async () => {
    const { wrapper, vm } = await mountEditor(true, [892, -1499.6], 'proforma')
    expect(vm.isRefundInvoice).toBe(false)
    expect(vm.hasNonPositiveAmountToPay).toBe(true)
    wrapper.unmount()
  })
})
