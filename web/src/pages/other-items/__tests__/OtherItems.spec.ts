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
import SortableTh from '@/components/ui/SortableTh.vue'

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

  it('načítá další stránku bez ztráty prvních položek a řadí celý seznam přes API', async () => {
    m.list.mockResolvedValueOnce({ items: [item('posted')], sources: [], total: 2, per_page: 1 })
    m.list.mockResolvedValueOnce({ items: [{ ...item('posted'), id: 'manual:43', source_id: 43, title: 'Druhá položka' }], sources: [], total: 2, per_page: 1 })
    const wrapper = shallowMount(OtherItems, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
    await flushPromises()
    const more = wrapper.findAll('button').find(button => button.text().includes('common.load_more'))
    await more!.trigger('click')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
    expect(wrapper.text()).toContain('Syntetický závazek')
    expect(wrapper.text()).toContain('Druhá položka')
    m.list.mockResolvedValue({ items: [item('posted')], sources: [], total: 2, per_page: 1 })
    wrapper.findAllComponents(SortableTh).find(th => th.props('sortKey') === 'amount')!.vm.$emit('toggle', 'amount')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, sort_by: 'amount', sort_dir: 'desc' }))
    expect(wrapper.text()).not.toContain('Druhá položka')
    wrapper.unmount()
  })

  it('po změně řazení zahodí opožděnou odpověď načítání další stránky', async () => {
    let resolveMore!: (value: any) => void
    m.list.mockResolvedValueOnce({ items: [item('posted')], sources: [], total: 2, per_page: 1 })
    m.list.mockImplementationOnce(() => new Promise(resolve => { resolveMore = resolve }))
    const wrapper = shallowMount(OtherItems, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text().includes('common.load_more'))!.trigger('click')
    m.list.mockResolvedValueOnce({ items: [{ ...item('posted'), title: 'Nové pořadí' }], sources: [], total: 1, per_page: 50 })
    wrapper.findAllComponents(SortableTh)[0]!.vm.$emit('toggle', 'title')
    await flushPromises()
    resolveMore({ items: [{ ...item('posted'), title: 'Zastaralá odpověď' }], sources: [], total: 2, per_page: 1 })
    await flushPromises()
    expect(wrapper.text()).toContain('Nové pořadí')
    expect(wrapper.text()).not.toContain('Zastaralá odpověď')
    wrapper.unmount()
  })
})
