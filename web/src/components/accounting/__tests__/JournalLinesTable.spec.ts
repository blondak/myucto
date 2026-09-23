import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import JournalLinesTable from '@/components/accounting/JournalLinesTable.vue'

// Dimenze (Firma → Dimenze) jsou u firmy vypnuté — jejich komponenty se nevykreslí.
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: false }, canEdit: { value: false }, loading: { value: false },
    types: { value: [] }, values: { value: [] }, documentTypes: { value: [] },
    valueById: { value: new Map() }, typeById: { value: new Map() },
    load: () => Promise.resolve(), reload: () => Promise.resolve(),
    options: () => [], treeOf: () => [], pathOf: () => [], labelOf: () => '', valueLabel: () => '',
  }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number) => String(value),
}))

const RouterLinkStub = {
  name: 'RouterLink',
  props: ['to'],
  template: '<a><slot /></a>',
}

describe('JournalLinesTable', () => {
  it('ukáže červené storno záporně v souvztažnosti i na samostatné straně', () => {
    const base = { entry_id: 10, supplier_id: 1, currency_code: null, fx_rate: null,
      amount_foreign: null, cost_center: null, amount: 100, is_red_storno: true }
    const debit = { ...base, id: 1, account_id: 1, account_code: '501', line_no: 1, side: 'debit' as const }
    const credit = { ...base, id: 2, account_id: 2, account_code: '321', line_no: 2, side: 'credit' as const }
    for (const lines of [[debit, credit], [debit]]) {
      const wrapper = mount(JournalLinesTable, { props: { lines }, global: { stubs: { RouterLink: RouterLinkStub } } })
      expect(wrapper.text()).toContain('-100')
      expect(wrapper.text()).toContain('accounting.journal.red_storno')
      wrapper.unmount()
    }
  })

  it('odkazuje z účtu přímo na jeho pohyby ve zvoleném rozsahu', () => {
    const wrapper = mount(JournalLinesTable, {
      props: {
        lines: [{
          id: 1,
          entry_id: 10,
          supplier_id: 1,
          account_id: 3138611,
          account_code: '221.400',
          account_name: 'Běžný účet',
          side: 'debit',
          amount: 100,
          currency_code: null,
          fx_rate: null,
          amount_foreign: null,
          cost_center: null,
          line_no: 1,
        }],
        dateFrom: '2027-01-01',
        dateTo: '2027-12-31',
      },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })

    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({
      name: 'accounting-account-statement',
      params: { accountId: 3138611 },
      query: { from: '2027-01-01', to: '2027-12-31' },
    })
  })
  it('ukazuje souvztažnosti — částku jednou a účty MD/DAL vedle sebe', () => {
    const base = {
      entry_id: 10, supplier_id: 1, currency_code: null, fx_rate: null,
      amount_foreign: null, cost_center: null,
    }
    const wrapper = mount(JournalLinesTable, {
      props: {
        lines: [
          { ...base, id: 1, account_id: 11, account_code: '311.100', account_name: 'Pohledávky', side: 'debit', amount: 101640, line_no: 1 },
          { ...base, id: 2, account_id: 12, account_code: '602.100', account_name: 'Tržby', side: 'credit', amount: 84000, line_no: 2 },
          { ...base, id: 3, account_id: 13, account_code: '343.200', account_name: 'DPH', side: 'credit', amount: 17640, line_no: 3 },
        ],
      },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })

    const rows = wrapper.findAll('table tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('311.100')
    expect(rows[0].text()).toContain('602.100')
    expect(rows[0].text()).toContain('84000')
    expect(rows[1].text()).toContain('17640')
    // Částka smí být v řádku jen jednou — to je celý smysl souvztažnosti.
    expect(rows[0].text().match(/84000/g)).toHaveLength(1)

    // Dělená pohledávka se vypíše jednou a buňka sahá přes obě protistrany.
    expect(wrapper.findAll('table tbody td').filter(td => td.text().includes('311.100'))).toHaveLength(1)
    expect(rows[0].findAll('td')[0].attributes('rowspan')).toBe('2')
    expect(rows[1].text()).not.toContain('311.100')
  })

  it('u zápisu s víc nohama na obou stranách zůstává rozpad po stranách', () => {
    const base = {
      entry_id: 10, supplier_id: 1, currency_code: null, fx_rate: null,
      amount_foreign: null, cost_center: null,
    }
    const wrapper = mount(JournalLinesTable, {
      props: {
        lines: [
          { ...base, id: 1, account_id: 11, account_code: '501.000', account_name: 'Spotřeba', side: 'debit', amount: 500, line_no: 1 },
          { ...base, id: 2, account_id: 12, account_code: '518.000', account_name: 'Služby', side: 'debit', amount: 500, line_no: 2 },
          { ...base, id: 3, account_id: 13, account_code: '321.000', account_name: 'Dodavatelé', side: 'credit', amount: 400, line_no: 3 },
          { ...base, id: 4, account_id: 14, account_code: '325.000', account_name: 'Jiné závazky', side: 'credit', amount: 600, line_no: 4 },
        ],
      },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })

    expect(wrapper.findAll('table tbody tr')).toHaveLength(4)
  })
})
