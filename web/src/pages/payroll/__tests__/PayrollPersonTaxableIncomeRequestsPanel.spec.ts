import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollTaxableIncomeRequest } from '@/api/payroll'

const mocks = vi.hoisted(() => ({
  taxableIncomeRequests: vi.fn(),
  createTaxableIncomeRequest: vi.fn(),
  completeTaxableIncomeRequest: vi.fn(),
  deleteTaxableIncomeRequest: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    taxableIncomeRequests: mocks.taxableIncomeRequests,
    createTaxableIncomeRequest: mocks.createTaxableIncomeRequest,
    completeTaxableIncomeRequest: mocks.completeTaxableIncomeRequest,
    deleteTaxableIncomeRequest: mocks.deleteTaxableIncomeRequest,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: mocks.success, error: mocks.error }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import PayrollPersonTaxableIncomeRequestsPanel from '@/pages/payroll/PayrollPersonTaxableIncomeRequestsPanel.vue'

function request(overrides: Partial<PayrollTaxableIncomeRequest> = {}): PayrollTaxableIncomeRequest {
  return {
    id: 5,
    employee_id: 17,
    employment_id: null,
    requested_on: '2026-08-12',
    income_year: 2025,
    due_on: '2026-08-22',
    deadline_source: '§ 38j odst. 3 zákona č. 586/1992 Sb. — do 10 dnů od žádosti ze dne 12. 8. 2026',
    status: 'open',
    completed_on: null,
    completion_kind: null,
    note: null,
    row_version: 1,
    ...overrides,
  }
}

async function mounted(canWrite = true) {
  const wrapper = mount(PayrollPersonTaxableIncomeRequestsPanel, {
    props: { personId: 17, canWrite, employmentId: 23 },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('PayrollPersonTaxableIncomeRequestsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.taxableIncomeRequests.mockResolvedValue([request()])
    mocks.createTaxableIncomeRequest.mockResolvedValue([request(), request({ id: 6 })])
    mocks.completeTaxableIncomeRequest.mockResolvedValue([request({ status: 'completed', completed_on: '2026-08-19' })])
    mocks.deleteTaxableIncomeRequest.mockResolvedValue([])
  })

  it('ukáže nevyřízenou žádost s termínem a citací § 38j', async () => {
    const wrapper = await mounted()

    expect(mocks.taxableIncomeRequests).toHaveBeenCalledWith(17)
    expect(wrapper.find('[data-test="taxable-income-requests-open-count"]').exists()).toBe(true)
    const row = wrapper.get('[data-test="taxable-income-request-5"]')
    expect(row.text()).toContain('payroll.people.taxable_income_requests.status.open')
    expect(row.text()).toContain('§ 38j odst. 3')
    expect(JSON.parse(wrapper.get('[data-test="taxable-income-request-issue"]').attributes('data-to') ?? '{}'))
      .toEqual({ name: 'payroll-documents', query: { person: '17' } })
  })

  it('zapíše novou žádost i s vazbou na pracovní vztah', async () => {
    const wrapper = await mounted()

    await wrapper.get('[data-test="taxable-income-request-date"]').setValue('2026-08-12')
    await wrapper.get('[data-test="taxable-income-request-note"]').setValue('Žádost e-mailem')
    await wrapper.get('[data-test="taxable-income-request-form"]').trigger('submit')
    await flushPromises()

    expect(mocks.createTaxableIncomeRequest).toHaveBeenCalledWith(17, expect.objectContaining({
      requested_on: '2026-08-12',
      employment_id: 23,
      note: 'Žádost e-mailem',
    }))
    expect(wrapper.findAll('[data-test^="taxable-income-request-"][data-test$="5"], [data-test="taxable-income-request-6"]').length)
      .toBeGreaterThan(0)
    expect(mocks.success).toHaveBeenCalled()
  })

  it('označí žádost jako vyřízenou mimo aplikaci', async () => {
    const wrapper = await mounted()

    await wrapper.get('[data-test="taxable-income-request-complete"]').trigger('click')
    await flushPromises()

    expect(mocks.completeTaxableIncomeRequest).toHaveBeenCalledWith(17, 5, expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/))
    expect(wrapper.get('[data-test="taxable-income-request-5"]').text())
      .toContain('payroll.people.taxable_income_requests.status.completed')
    expect(wrapper.find('[data-test="taxable-income-request-complete"]').exists()).toBe(false)
  })

  it('ukáže chybu validace ze serveru', async () => {
    mocks.createTaxableIncomeRequest.mockRejectedValue({
      response: { data: { error: { code: 'validation_failed', message: 'Den žádosti nemůže být v budoucnosti.' } } },
    })
    const wrapper = await mounted()

    await wrapper.get('[data-test="taxable-income-request-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-test="taxable-income-request-error"]').text())
      .toContain('Den žádosti nemůže být v budoucnosti.')
  })

  it('bez oprávnění k zápisu nenabízí formulář ani akce', async () => {
    const wrapper = await mounted(false)

    expect(wrapper.find('[data-test="taxable-income-request-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="taxable-income-request-complete"]').exists()).toBe(false)
  })
})
