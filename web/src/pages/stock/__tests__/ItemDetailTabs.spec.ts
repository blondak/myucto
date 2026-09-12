import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/api/stock', () => ({
  stockApi: {
    getItem: vi.fn(async () => ({
      id: 42,
      sku: 'ITEM-42',
      name: 'Synthetic item',
      item_type: 'goods',
      unit: 'pcs',
      tracking_mode: 'none',
      lifecycle_status: 'ready',
      is_active: true,
      is_stocked: true,
    })),
    itemMovements: vi.fn(async () => ({ opening_balance: '0', items: [] })),
    itemMovementsExportUrl: vi.fn(() => ''),
  },
}))
vi.mock('@/api/purchaseOrders', () => ({
  purchaseOrdersApi: { quantities: vi.fn(async () => ({ items: [] })) },
}))
vi.mock('@/api/productMasters', () => ({
  productMastersApi: { getProductContext: vi.fn(async () => null) },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => true, canWrite: () => true }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 1 }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '42' }, query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import ItemDetail from '../ItemDetail.vue'

describe('ItemDetail tabs', () => {
  it('přepíná záložky šipkami, Home a End a přesouvá fokus', async () => {
    const wrapper = mount(ItemDetail, {
      attachTo: document.body,
      global: {
        stubs: {
          ActionBar: true,
          EmptyState: true,
          ItemDuplicateDialog: true,
          ItemTemplatesPanel: true,
          ProductRelationsPanel: true,
          RouterLink: { template: '<a><slot /></a>' },
        },
      },
    })
    await flushPromises()

    let tabs = wrapper.findAll('button[role="tab"]')
    expect(tabs[0]!.attributes('aria-selected')).toBe('true')

    await tabs[0]!.trigger('keydown', { key: 'ArrowRight' })
    await flushPromises()
    tabs = wrapper.findAll('button[role="tab"]')
    expect(tabs[1]!.attributes('aria-selected')).toBe('true')
    expect(document.activeElement).toBe(tabs[1]!.element)

    await tabs[1]!.trigger('keydown', { key: 'Home' })
    await flushPromises()
    tabs = wrapper.findAll('button[role="tab"]')
    expect(tabs[0]!.attributes('aria-selected')).toBe('true')
    expect(document.activeElement).toBe(tabs[0]!.element)

    await tabs[0]!.trigger('keydown', { key: 'End' })
    await flushPromises()
    tabs = wrapper.findAll('button[role="tab"]')
    expect(tabs[1]!.attributes('aria-selected')).toBe('true')

    await tabs[1]!.trigger('keydown', { key: 'ArrowLeft' })
    await flushPromises()
    expect(wrapper.findAll('button[role="tab"]')[0]!.attributes('aria-selected')).toBe('true')
    wrapper.unmount()
  })
})
