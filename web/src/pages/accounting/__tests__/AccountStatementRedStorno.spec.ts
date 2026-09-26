import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const api = vi.hoisted(() => ({ getOpenItems: vi.fn() }))

vi.mock('@/api/accounting', () => ({ accountingApi: { getOpenItems: api.getOpenItems } }))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ params: { accountId: '11' }, query: { mode: 'open', to: '2026-09-26' } }),
  useRouter: () => ({ replace: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => false }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value, formatMoney: (value: number) => String(value) }))

import AccountStatement from '@/pages/accounting/AccountStatement.vue'

describe('AccountStatement red storno amounts', () => {
  it('shows a red debit as a negative open and posted amount', async () => {
    api.getOpenItems.mockResolvedValue({
      account: { id: 11, code: '261', name: 'Transfers', type: 'asset', normal_side: 'debit', is_synthetic: false },
      as_of: '2026-09-26', only_open: true, total: 1, page: 1, per_page: 100,
      line_count: 1, open_count: 1, open_md: -30, open_d: 0, open_total: -30, balance: -30, difference: 0,
      items: [{
        line_id: 1, line_no: 1, entry_id: 2, entry_date: '2026-09-25', document_no: 'TEST-1',
        description: null, source_type: 'manual', source_id: null, side: 'debit', is_red_storno: true,
        amount: 30, open_amount: 30, open_balance: -30, balance: -30,
        account_id: 11, account_code: '261', account_name: 'Transfers', is_reversed: false,
        source_statement_id: null, source_doc_number: null, source_register_id: null, source_asset_id: null,
        source_settlement_doc_type: null, source_settlement_doc_id: null, counter_accounts: null,
        partner: null, variable_symbol: null, currency: 'CZK', amount_foreign: null, pairing_id: null,
      }],
    })
    const wrapper = mount(AccountStatement, { global: { stubs: { JournalSourceDrawer: true, EmptyState: true, DateInput: true } } })
    await flushPromises()

    const row = wrapper.findAll('tbody tr').find(r => r.text().includes('TEST-1'))
    expect(row).toBeDefined()
    const cells = row!.findAll('td')
    expect(cells[4].text()).toBe('-30')
    expect(cells[7].text()).toBe('-30')
  })
})
