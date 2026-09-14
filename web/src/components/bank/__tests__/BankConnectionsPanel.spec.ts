import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { CurrencyAccount } from '@/api/settings'
import BankConnectionsPanel from '../BankConnectionsPanel.vue'

vi.mock('../BankConnectionAccount.vue', () => ({ default: { name: 'BankConnectionAccount', props: ['account'], template: '<div />' } }))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/api/bankConnections', () => ({ bankConnectionsApi: { list: async () => ({
  providers: [
    { code: 'fio', label: 'Fio', implemented: true, bank_codes: ['2010', '8330'], capabilities: { statement_import: true } },
    { code: 'csob', label: 'ČSOB', implemented: false, bank_codes: ['0300'], capabilities: { statement_import: false } },
  ], connections: [
    { currency_id: 5, has_token: true, enabled: false },
    { currency_id: 6, has_token: false, enabled: false },
  ],
}) } }))

describe('Bank connections panel', () => {
  it('keeps inactive accounts only when they have stored bank access', async () => {
    const wrapper = mount(BankConnectionsPanel, {
      props: { canManage: true, accounts: [
        { id: 1, bank_code: '2010', is_active: true },
        { id: 5, bank_code: '2010', is_active: false },
        { id: 6, bank_code: '2010', is_active: false },
        { id: 7, bank_code: '2010', is_active: false },
      ] as CurrencyAccount[] },
    })
    await flushPromises()
    expect(wrapper.findAllComponents({ name: 'BankConnectionAccount' }).map(row => row.props('account').id)).toEqual([1, 5])
  })

  it('filters unsupported accounts but keeps the provider overview and box', async () => {
    const wrapper = mount(BankConnectionsPanel, {
      props: { canManage: true, accounts: [
        { id: 1, bank_code: '2010', is_active: true }, { id: 2, bank_code: '0300', is_active: true }, { id: 3, bank_code: null, is_active: true }, { id: 4, bank_code: '8330', is_active: true },
      ] as CurrencyAccount[] },
      global: { stubs: { BankConnectionAccount: true } },
    })
    await flushPromises()
    expect(wrapper.find('section').exists()).toBe(true)
    expect(wrapper.findAllComponents({ name: 'BankConnectionAccount' }).map(row => row.props('account').id)).toEqual([1, 4])
    expect(wrapper.text()).toContain('ČSOB')
    expect(wrapper.text()).not.toContain('bank_connection.available')
    expect(wrapper.text()).not.toContain('bank_connection.unavailable')
    await wrapper.setProps({ accounts: [{ id: 2, bank_code: '0300' }] as CurrencyAccount[] })
    expect(wrapper.findAllComponents({ name: 'BankConnectionAccount' })).toHaveLength(0)
    expect(wrapper.find('section').exists()).toBe(true)
    expect(wrapper.text()).toContain('bank_connection.no_supported_accounts')
    expect(wrapper.text()).toContain('Fio')
  })
})
