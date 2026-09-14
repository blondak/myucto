import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import PaymentOrders from '../PaymentOrders.vue'
import type { PaymentOrderListItem } from '@/api/paymentOrders'

const m = vi.hoisted(() => ({ list: vi.fn(), candidates: vi.fn(), remove: vi.fn(), archive: vi.fn(), success: vi.fn(), error: vi.fn() }))
vi.mock('@/api/paymentOrders', () => ({ paymentOrdersApi: { list: m.list, candidates: m.candidates, delete: m.remove, archiveAfterBankCancellation: m.archive } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ push: vi.fn() }) }))

const order: PaymentOrderListItem = {
  id: 1, currency: 'CZK', payment_date: '2026-09-14', total_amount: 10, item_count: 1, mark_paid: false,
  note: null, created_at: '2026-09-14', payer_account_label: 'Synthetic account', payer_account_number: '1000000005',
  payer_bank_code: '5500', payer_iban: null,
}
const page = (data: PaymentOrderListItem[]) => ({ data, meta: { total: data.length, page: 1, pages: 1, per_page: 50 } })
type State = { history: PaymentOrderListItem[]; bankOrderId: number | null; loadHistory: () => Promise<void>; deleteOrder: (item: PaymentOrderListItem) => Promise<void> }

beforeEach(() => {
  vi.clearAllMocks()
  m.candidates.mockResolvedValue({ ...page([]), payer_accounts: [] })
  m.list.mockResolvedValue(page([order]))
  m.archive.mockResolvedValue(undefined)
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})

describe('Payment order history', () => {
  it('removes an archived order and its selection even when a refresh returns stale data', async () => {
    const wrapper = shallowMount(PaymentOrders)
    await flushPromises()
    const vm = wrapper.vm as unknown as State
    vm.bankOrderId = order.id
    m.remove.mockRejectedValue({ response: { data: { error: { code: 'payment_order_delete_protected' } } } })
    await vm.deleteOrder(order)
    expect(m.archive).toHaveBeenCalledWith(order.id)
    expect(vm.history).toEqual([])
    expect(vm.bankOrderId).toBeNull()
    expect(m.error).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('ignores an older history request that finishes after the newest one', async () => {
    const wrapper = shallowMount(PaymentOrders)
    await flushPromises()
    const vm = wrapper.vm as unknown as State
    let resolve!: (value: ReturnType<typeof page>) => void
    m.list.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    const oldLoad = vm.loadHistory()
    m.list.mockResolvedValueOnce(page([]))
    await vm.loadHistory()
    resolve(page([order]))
    await oldLoad
    expect(vm.history).toEqual([])
    wrapper.unmount()
  })
})
