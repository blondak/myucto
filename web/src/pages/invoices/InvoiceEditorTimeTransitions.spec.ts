import { nextTick } from 'vue'
import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'

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

describe('InvoiceEditor time transitions', () => {
  it('převrátí s typem dobropisu množství i přesné minuty oběma směry', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/invoices/new', component: InvoiceEditor }],
    })
    await router.push('/invoices/new')
    await router.isReady()
    const wrapper = shallowMount(InvoiceEditor, {
      global: {
        plugins: [
          router,
          createPinia(),
          createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false }),
        ],
        directives: { math: {} },
      },
    })
    const vm = wrapper.vm as unknown as {
      form: {
        invoice_type: 'invoice' | 'credit_note'
        items: Array<{ quantity: number; duration_minutes: number | null }>
      }
    }
    const item = { quantity: 1 / 3, duration_minutes: 20 }
    vm.form.items.push(item)

    vm.form.invoice_type = 'credit_note'
    await nextTick()
    expect(item).toEqual({ quantity: -1 / 3, duration_minutes: -20 })

    vm.form.invoice_type = 'invoice'
    await nextTick()
    expect(item).toEqual({ quantity: 1 / 3, duration_minutes: 20 })
    wrapper.unmount()
  })
})
