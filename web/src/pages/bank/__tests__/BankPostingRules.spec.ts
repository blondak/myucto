import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { reactive, ref } from 'vue'

const mocks = vi.hoisted(() => ({ list: vi.fn(), accounts: vi.fn(), error: vi.fn(), promote: vi.fn() }))
const supplier = reactive({ currentSupplierId: 1, currentSupplier: { company_name: 'Synthetic A' } })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key, locale: ref('cs') }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: mocks.error, success: vi.fn() }) }))
vi.mock('@/api/bankPosting', () => ({ bankPostingApi: { listRules: mocks.list, promoteRule: mocks.promote }, bankPostingErrorMessage: () => 'error' }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: mocks.accounts } }))
import BankPostingRules from '../BankPostingRules.vue'

const rule = (id: number) => ({ id, supplier_id: id, name: `Synthetic rule ${id}`, is_active: true, direction: 'outgoing', debit_account_code: '518', credit_account_code: '221', mode: 'suggest', amount_min: null, amount_max: null })
const result = (id: number) => ({ items: [rule(id)], total: 1, page: 1, per_page: 50 })

describe('posting rules company scope', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    supplier.currentSupplierId = 1
    mocks.accounts.mockResolvedValue([])
    mocks.list.mockResolvedValue(result(1))
  })

  it('pins the request to the displayed company and reloads after a company change', async () => {
    const wrapper = shallowMount(BankPostingRules)
    await flushPromises()
    expect(mocks.list).toHaveBeenCalledWith({ page: 1, per_page: 50 }, 1)
    expect(wrapper.text()).toContain('Synthetic rule 1')
    mocks.list.mockResolvedValue(result(2))
    supplier.currentSupplierId = 2
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith({ page: 1, per_page: 50 }, 2)
    expect(wrapper.text()).toContain('Synthetic rule 2')
    expect(wrapper.text()).not.toContain('Synthetic rule 1')
    wrapper.unmount()
  })

  it('ignores a late response from the previous company', async () => {
    let resolve!: (value: ReturnType<typeof result>) => void
    mocks.list.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    const wrapper = shallowMount(BankPostingRules)
    mocks.list.mockResolvedValue(result(2))
    supplier.currentSupplierId = 2
    await flushPromises()
    resolve(result(1))
    await flushPromises()
    expect(wrapper.text()).toContain('Synthetic rule 2')
    expect(wrapper.text()).not.toContain('Synthetic rule 1')
    wrapper.unmount()
  })
})

describe('posting rules manual promotion', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    supplier.currentSupplierId = 1
    mocks.accounts.mockResolvedValue([])
    mocks.promote.mockResolvedValue({})
  })

  it('offers promotion for every active suggest rule and marks non-candidates as forced', async () => {
    mocks.list.mockResolvedValue({
      items: [
        { ...rule(1), name: 'Candidate', promotion_candidate: true, amount_min: 1, amount_max: 2, hit_count: 5, approved_streak: 5 },
        { ...rule(1), id: 2, name: 'Fresh', promotion_candidate: false, hit_count: 0, approved_streak: 0 },
        { ...rule(1), id: 3, name: 'Inactive', is_active: false, promotion_candidate: false, hit_count: 0, approved_streak: 0 },
        { ...rule(1), id: 4, name: 'Auto', mode: 'auto', promotion_candidate: false, hit_count: 0, approved_streak: 0 },
      ],
      total: 4, page: 1, per_page: 50,
    })
    const wrapper = shallowMount(BankPostingRules)
    await flushPromises()
    expect(wrapper.findAll('[data-test="rule-promote"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test="rule-promote-forced"]')).toHaveLength(1)

    const confirmSpy = vi.fn().mockReturnValue(true)
    vi.stubGlobal('confirm', confirmSpy)
    await wrapper.find('[data-test="rule-promote-forced"]').trigger('click')
    await flushPromises()
    const message = String(confirmSpy.mock.calls[0]![0])
    expect(message).toContain('automation.rules.promote_forced_confirm')
    expect(message).toContain('automation.rules.promote_no_band_note')
    expect(mocks.promote).toHaveBeenCalledWith(2)

    confirmSpy.mockClear()
    await wrapper.find('[data-test="rule-promote"]').trigger('click')
    await flushPromises()
    expect(String(confirmSpy.mock.calls[0]![0])).toBe('automation.rules.promote_confirm:{"name":"Candidate"}')
    expect(mocks.promote).toHaveBeenLastCalledWith(1)
    vi.unstubAllGlobals()
    wrapper.unmount()
  })
})
