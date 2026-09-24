import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  profit: vi.fn(),
  cashFlow: vi.fn(),
  exportProfit: vi.fn(),
  exportCashFlow: vi.fn(),
  responsibleCandidates: vi.fn(),
  replace: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true },
    types: { value: [
      { id: 1, name: 'Středisko', level: 'company', is_active: true },
      { id: 2, name: 'Projekt skupiny', level: 'global', is_active: true },
    ] },
    valueById: { value: new Map([[9, { id: 9, type_id: 2, code: 'G1', name: 'Stavba', is_active: true }]]) },
    load: () => Promise.resolve(),
  }),
}))
vi.mock('@/api/dimensions', () => ({
  dimensionsApi: {
    profit: m.profit,
    cashFlow: m.cashFlow,
    exportProfit: m.exportProfit,
    exportCashFlow: m.exportCashFlow,
    responsibleCandidates: m.responsibleCandidates,
  },
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.replace }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v) }))

import DimensionProfit from '../DimensionProfit.vue'

const stubs = {
  DimensionPicker: { name: 'DimensionPicker', props: ['typeId', 'modelValue'], template: '<div data-test="picker" />' },
  DimensionReportFilter: { name: 'DimensionReportFilter', props: ['valueId', 'descendants'], template: '<div data-test="report-filter" />' },
  EmptyState: { template: '<div data-test="empty" />' },
}

const profitReport = {
  type: { id: 1, name: 'Středisko' }, from: '2094-01-01', to: '2094-12-31', supplier_ids: [1], hidden_companies: 0,
  restricted: false,
  rows: [{ value_id: 5, parent_id: null, code: 'S1', name: 'Výroba', is_active: true, depth: 0, has_children: false,
           responsible_user_name: 'Jana', own: { revenue: 0, cost: 10, result: -10 }, total: { revenue: 0, cost: 10, result: -10 } }],
  unassigned: { revenue: 0, cost: 0, result: 0 },
  totals: { revenue: 0, cost: 10, result: -10 },
}

beforeEach(() => {
  vi.clearAllMocks()
  m.routeQuery = {}
  m.profit.mockResolvedValue(profitReport)
  m.cashFlow.mockResolvedValue({
    from: '2094-01-01', to: '2094-12-31', supplier_ids: [1, 3], hidden_companies: 0, dimension: null, profit: 0,
    non_cash: { total: 0, accounts: [] }, working_capital: { total: 0, accounts: [] }, operating: 0,
    investing: { total: 0, accounts: [] }, financing: { total: 0, accounts: [] },
    net_cash_flow: 0, cash_movement: 0, untagged_cash: 0, reconciles: true,
  })
  m.responsibleCandidates.mockResolvedValue([{ id: 4, name: 'Jana' }])
})

describe('Výkazy po dimenzi', () => {
  it('načte výsledovku prvního typu a pošle větev, odpovědnou osobu a rozpad po účtech z URL', async () => {
    m.routeQuery = { type_id: '1', value_id: '5', responsible_user_id: '4', accounts: '1', from: '2094-01-01', to: '2094-12-31' }
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(m.profit).toHaveBeenCalledWith({
      type_id: 1, from: '2094-01-01', to: '2094-12-31', value_id: 5, responsible_user_id: 4, accounts: 1,
    })
    expect(w.find('[data-test="profit-table"]').text()).toContain('Jana')
  })

  it('přepne na peněžní tok a u globální hodnoty pošle součet za skupinu', async () => {
    m.routeQuery = { tab: 'cash_flow', dimension_value_id: '9', scope: 'group', from: '2094-01-01', to: '2094-12-31' }
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(m.cashFlow).toHaveBeenCalledWith({
      from: '2094-01-01', to: '2094-12-31', dimension_value_id: 9, dimension_descendants: 1, scope: 'group',
    })
    expect(m.profit).not.toHaveBeenCalled()
    expect(w.find('[data-test="cash-flow-table"]').exists()).toBe(true)
    expect(w.text()).toContain('dimensions.profit_companies')

    await w.find('[data-test="tab-profit"]').trigger('click')
    await flushPromises()
    expect(m.profit).toHaveBeenCalledTimes(1)
  })

  it('export stáhne XLSX aktivní záložky', async () => {
    m.exportProfit.mockResolvedValue(new Blob(['x']))
    const createUrl = vi.fn(() => 'blob:x')
    Object.assign(URL, { createObjectURL: createUrl, revokeObjectURL: vi.fn() })
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    await w.find('[data-test="dimension-export"]').trigger('click')
    await flushPromises()
    expect(m.exportProfit).toHaveBeenCalledWith(expect.objectContaining({ type_id: 1 }), 'xlsx')
    expect(m.exportCashFlow).not.toHaveBeenCalled()
    expect(createUrl).toHaveBeenCalled()
    await w.find('[data-test="dimension-export-pdf"]').trigger('click')
    await flushPromises()
    expect(m.exportProfit).toHaveBeenCalledWith(expect.objectContaining({ type_id: 1 }), 'pdf')
  })
})
