import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  routerPush: vi.fn(),
  routerReplace: vi.fn(),
}))

vi.mock('@/api/otherItems', () => ({
  otherItemsApi: { get: m.get, update: m.update, create: vi.fn() },
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence' } }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), success: vi.fn() }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '42' }, query: {} }),
  useRouter: () => ({ push: m.routerPush, replace: m.routerReplace }),
  RouterLink: { template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import OtherItemEditor from '../OtherItemEditor.vue'

describe('OtherItemEditor partner', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.get.mockResolvedValue({
      id: 42,
      status: 'draft',
      side: 'payable',
      kind: 'rent',
      title: 'Syntetický nájem',
      partner_id: 17,
      partner_name: 'Syntetický dodavatel',
      issued_on: '2099-01-01',
      accounting_on: '2099-01-01',
      due_on: '2099-01-20',
      currency: 'CZK',
      exchange_rate: null,
      amount: 1200,
      variable_symbol: null,
      account_code: null,
      counter_account_code: null,
      note: null,
    })
    m.update.mockResolvedValue({ id: 42 })
    m.routerPush.mockResolvedValue(undefined)
  })

  it('při uložení konceptu zachová vazbu na načteného partnera', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('input[maxlength="255"]').setValue('Upravený nájem')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: 17,
      partner_name: 'Syntetický dodavatel',
      title: 'Upravený nájem',
    }))
  })

  it('při výslovném vymazání protistrany odstraní i vazbu', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('input[maxlength="190"]').setValue('')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: null,
      partner_name: null,
    }))
  })
})
