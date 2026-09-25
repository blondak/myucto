import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StatementAccountsTable from '@/components/accounting/StatementAccountsTable.vue'
import type { StatementAccountsReport } from '@/api/accounting'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

const RouterLinkStub = {
  name: 'RouterLink',
  props: ['to'],
  template: '<a><slot /></a>',
}

function report(profit: number): StatementAccountsReport {
  const bank = {
    account_id: 20, account_code: '221', name: 'Bankovní účty', account_type: 'asset', md: 5000, d: 2000,
    analytics: [
      { account_id: 21, account_code: '221001', name: 'Běžný účet', md: 5000, d: 0 },
      { account_id: 22, account_code: '221002', name: 'Kontokorent', md: 0, d: 2000 },
    ],
  }
  return {
    version_code: 'test',
    as_of: '2099-06-30',
    entity: { name: 'Test', ico: null, address: null, legal_form: null, prepared_at: '2099-07-01 10:00' },
    period: { id: 1, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31' },
    closed: false,
    balance: { classes: [{ class: '2', accounts: [bank], md: 5000, d: 2000 }], md: 5000, d: 2000, profit },
    profit_loss: {
      sections: [
        { key: 'operating', expenses: [{ account_id: 50, account_code: '518', name: 'Ostatní služby', md: 2000, d: 0, analytics: [] }],
          revenues: [], expense_total: 2000, revenue_total: 0, result: -2000 },
        { key: 'financial', expenses: [], revenues: [], expense_total: 0, revenue_total: 0, result: 0 },
      ],
      operating_profit: -2000, financial_profit: 0, profit_before_tax: -2000, profit_after_tax: -2000, profit,
    },
    checks: { profit_balance: profit, profit_loss: profit, profit_matches: true, technical_residual: 0, unassigned_count: 0 },
  }
}

function mountTable(part: 'balance' | 'profit_loss', profit = -2000) {
  return mount(StatementAccountsTable, {
    props: { report: report(profit), part, format: (v: number | null | undefined) => String(v) },
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
}

describe('StatementAccountsTable', () => {
  it('odkazuje ze syntetiky i analytiky na opis účtu k rozvahovému dni', () => {
    const links = mountTable('balance').findAllComponents(RouterLinkStub)
    expect(links.map(l => l.props('to').params.accountId)).toEqual([20, 21, 22])
    expect(links[0].props('to')).toEqual({
      name: 'accounting-account-statement',
      params: { accountId: 20 },
      query: { from: '2099-01-01', to: '2099-06-30' },
    })
  })

  it('skryje analytiky po vypnutí přepínače', async () => {
    const wrapper = mountTable('balance')
    await wrapper.get('[data-test="accounts-analytics-toggle"]').setValue(false)
    expect(wrapper.findAllComponents(RouterLinkStub)).toHaveLength(1)
  })

  it('zvýrazní výsledek hospodaření podle znaménka', () => {
    const loss = mountTable('profit_loss').get('[data-test="accounts-profit-row"]')
    expect(loss.classes()).toContain('bg-danger-50')
    expect(loss.text()).toContain('accounting.statement_accounts.loss')
    const gain = mountTable('balance', 1500).get('[data-test="accounts-profit-row"]')
    expect(gain.classes()).toContain('bg-success-50')
    expect(gain.text()).toContain('1500')
  })

  it('výsledovka vynechá prázdné skupiny', () => {
    const text = mountTable('profit_loss').text()
    expect(text).toContain('accounting.statement_accounts.section_operating')
    expect(text).not.toContain('accounting.statement_accounts.section_financial')
  })
})
