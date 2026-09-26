import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  remove: vi.fn(),
  canWrite: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/otherItems', () => ({
  otherItemsApi: { list: m.list, remove: m.remove },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string) => value,
  formatMoney: (value: number) => String(value),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import OtherItems from '../OtherItems.vue'

function item(status: 'draft' | 'posted') {
  return {
    id: 'manual:42', source_kind: 'manual', source_id: 42, side: 'payable', kind: 'rent',
    title: 'Syntetický závazek', partner_name: 'Syntetická protistrana', due_on: '2099-01-20',
    currency: 'CZK', amount: 1200, remaining_amount: 1200, status, certainty: 'confirmed',
    document_count: 0, source_url: '/other-items/42',
  }
}

describe('OtherItems actions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.remove.mockResolvedValue({})
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('ukáže detail, úpravu a smazání konceptu a po smazání znovu načte seznam', async () => {
    m.list.mockResolvedValueOnce({ items: [item('draft')], sources: [], total: 1, per_page: 50 })
    m.list.mockResolvedValueOnce({ items: [], sources: [], total: 0, per_page: 50 })
    const wrapper = shallowMount(OtherItems, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
    await flushPromises()

    expect(wrapper.text()).toContain('common.detail')
    expect(wrapper.text()).toContain('common.edit')
    const remove = wrapper.findAll('button').find(button => button.text().includes('common.delete'))
    expect(remove).toBeDefined()
    await remove!.trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalledWith('other_items.confirm_delete')
    expect(m.remove).toHaveBeenCalledWith(42)
    expect(m.list).toHaveBeenCalledTimes(2)
  })

  it('u zaúčtované položky nenabídne úpravu ani smazání', async () => {
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 1, per_page: 50 })
    const wrapper = shallowMount(OtherItems, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
    await flushPromises()

    expect(wrapper.text()).toContain('common.detail')
    expect(wrapper.text()).not.toContain('common.edit')
    expect(wrapper.text()).not.toContain('common.delete')
  })
})
