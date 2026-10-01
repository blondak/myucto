import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  dimensionCostReport: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { dimensionCostReport: m.dimensionCostReport },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: (permission: string) => permission === 'payroll.reports' }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, te: () => true, locale: { value: 'cs-CZ' } }),
}))

import PayrollDimensionCostReportPanel from '@/pages/payroll/PayrollDimensionCostReportPanel.vue'

const totals = { wages_minor: 900000, insurance_minor: 304200, other_minor: 0, total_minor: 1204200 }

function row(overrides: Record<string, unknown>) {
  return {
    employment_id: 1,
    employee_id: 1,
    employee_name: 'Syntetický Zaměstnanec',
    employment_code: null,
    cost_center: null,
    dimensions: [],
    wages_minor: 900000,
    insurance_minor: 304200,
    other_minor: 0,
    total_minor: 1204200,
    unallocated_reason: null,
    ...overrides,
  }
}

describe('PayrollDimensionCostReportPanel', () => {
  beforeEach(() => {
    m.dimensionCostReport.mockReset()
  })

  it('stays hidden for a firm that does not use dimensions', async () => {
    m.dimensionCostReport.mockResolvedValue({
      year: 2026, enabled: false, rows: [], by_dimension: [], totals,
    })
    const wrapper = mount(PayrollDimensionCostReportPanel, { props: { initialYear: 2026 } })
    await flushPromises()

    expect(m.dimensionCostReport).toHaveBeenCalledWith(2026)
    expect(wrapper.find('[data-test="payroll-dimension-cost-report"]').exists()).toBe(false)
  })

  it('shows the report and explains what stayed unassigned', async () => {
    m.dimensionCostReport.mockResolvedValue({
      year: 2026,
      enabled: true,
      rows: [
        row({}),
        row({
          employment_id: null,
          employee_id: null,
          employee_name: null,
          wages_minor: 0,
          total_minor: 304200,
          unallocated_reason: 'employer_insurance_not_allocatable',
        }),
      ],
      by_dimension: [{ type_id: null, type_name: null, value_id: null, code: '', name: '', total_minor: 1508400 }],
      totals,
    })
    const wrapper = mount(PayrollDimensionCostReportPanel, { props: { initialYear: 2026 } })
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-dimension-cost-report"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="dimension-cost-report-unassigned"]').attributes('title'))
      .toBe('payroll.dimension_cost_report.unassigned_reason.employer_insurance_not_allocatable')
  })
})
