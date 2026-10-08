import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollPensionRequest } from '@/api/payroll'

const mocks = vi.hoisted(() => ({
  pensionRequests: vi.fn(),
  person: vi.fn(),
  createPensionRequest: vi.fn(),
  completePensionRequest: vi.fn(),
  recordPensionRequestCopy: vi.fn(),
  deletePensionRequest: vi.fn(),
  downloadPensionInsuranceCertificate: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    pensionRequests: mocks.pensionRequests,
    person: mocks.person,
    createPensionRequest: mocks.createPensionRequest,
    completePensionRequest: mocks.completePensionRequest,
    recordPensionRequestCopy: mocks.recordPensionRequestCopy,
    deletePensionRequest: mocks.deletePensionRequest,
    downloadPensionInsuranceCertificate: mocks.downloadPensionInsuranceCertificate,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: mocks.success, error: mocks.error }),
}))

// `useFormat` táhne @/i18n se skutečným `createI18n` — původní modul se rozprostře.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key, te: () => true }),
}))

import PayrollPersonPensionRequestsPanel from '@/pages/payroll/PayrollPersonPensionRequestsPanel.vue'

function request(overrides: Partial<PayrollPensionRequest> = {}): PayrollPensionRequest {
  return {
    id: 44,
    employee_id: 17,
    employment_id: 23,
    request_kind: 'eldp',
    legacy_kind: null,
    requester: 'ossz',
    requester_reference: 'SYN-OSSZ-1',
    received_on: '2026-08-20',
    period_year: 2025,
    period_from: null,
    period_to: null,
    death_on: null,
    stated_due_on: null,
    due_on: '2026-08-28',
    deadline_rule: 'cz-eldp-deadlines.authority-request.pre-2026.v1',
    deadline_source: 'Zákon č. 582/1991 Sb., § 39 odst. 3 ve znění účinném do 31. 12. 2025',
    eldp_statement_id: null,
    eldp_environment: null,
    status: 'open',
    completed_on: null,
    completion_kind: null,
    completion_reference: null,
    copy_delivered_on: null,
    note: null,
    row_version: 1,
    ...overrides,
  }
}

async function mounted(canWrite = true) {
  const wrapper = mount(PayrollPersonPensionRequestsPanel, {
    props: { personId: 17, canWrite },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('PayrollPersonPensionRequestsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.pensionRequests.mockResolvedValue([request()])
    mocks.person.mockResolvedValue({
      id: 17,
      employments: [{ id: 23, employee_id: 17, code: 'SYN-1', start_date: '2024-01-01', end_date: null }],
    })
    mocks.createPensionRequest.mockResolvedValue([request(), request({ id: 45, request_kind: 'jmh_correction' })])
    mocks.completePensionRequest.mockResolvedValue([request({ status: 'completed', completed_on: '2026-08-27' })])
    mocks.recordPensionRequestCopy.mockResolvedValue([request({ copy_delivered_on: '2026-08-27', status: 'statement_prepared', eldp_statement_id: 9 })])
    mocks.deletePensionRequest.mockResolvedValue([])
    mocks.downloadPensionInsuranceCertificate.mockResolvedValue(undefined)
  })

  it('ukáže výzvu k evidenčnímu listu s termínem a proklikem na obrazovku ELDP', async () => {
    const wrapper = await mounted()

    expect(mocks.pensionRequests).toHaveBeenCalledWith(17)
    expect(wrapper.find('[data-test="pension-requests-open-count"]').exists()).toBe(true)
    const row = wrapper.get('[data-test="pension-request-44"]')
    expect(row.text()).toContain('payroll.people.pension_requests.kind.eldp')
    expect(row.text()).toContain('§ 39 odst. 3')
    expect(JSON.parse(wrapper.get('[data-test="pension-request-eldp-44"]').attributes('data-to') ?? '{}')).toEqual({
      path: '/payroll/submissions/eldp',
      query: { person: '17', employment: '23', year: '2025', pension_request: '44' },
    })
  })

  it('zapíše výzvu k opravě měsíčního hlášení s měsícem', async () => {
    const wrapper = await mounted()

    await wrapper.get('[data-test="pension-request-kind"]').setValue('jmh_correction')
    await wrapper.get('[data-test="pension-request-month"]').setValue('2026-05')
    await wrapper.get('[data-test="pension-request-reference"]').setValue('SYN-38a')
    await wrapper.get('[data-test="pension-request-form"]').trigger('submit')
    await flushPromises()

    expect(mocks.createPensionRequest).toHaveBeenCalledWith(17, expect.objectContaining({
      request_kind: 'jmh_correction',
      requester: 'ossz',
      period_from: '2026-05-01',
      employment_id: null,
      requester_reference: 'SYN-38a',
    }))
    expect(mocks.success).toHaveBeenCalled()
  })

  it('u listu po úmrtí chce datum úmrtí, než dovolí zápis', async () => {
    const wrapper = await mounted()

    await wrapper.get('[data-test="pension-request-requester"]').setValue('survivor')
    expect(wrapper.get('[data-test="pension-request-missing"]').text())
      .toContain('payroll.people.pension_requests.missing.death_on')
    expect(wrapper.get('[data-test="pension-request-save"]').attributes('disabled')).toBeDefined()
  })

  it('zapíše předání stejnopisu a vyřízení s dokladem', async () => {
    const wrapper = await mounted()

    await wrapper.get('[data-test="pension-request-record-copy-44"]').trigger('click')
    await flushPromises()
    expect(mocks.recordPensionRequestCopy).toHaveBeenCalledWith(17, 44, expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/))
    expect(wrapper.get('[data-test="pension-request-copy-44"]').text()).toContain('payroll.people.pension_requests.copy_delivered')
    // Navázanou výzvu smazat nejde, jen vyřídit.
    expect(wrapper.find('[data-test="pension-request-delete-44"]').exists()).toBe(false)

    await wrapper.get('[data-test="pension-request-reference-44"]').setValue('CSSZ-SYN-1')
    await wrapper.get('[data-test="pension-request-complete-44"]').trigger('click')
    await flushPromises()
    expect(mocks.completePensionRequest).toHaveBeenCalledWith(17, 44, expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/), 'CSSZ-SYN-1')
  })

  it('potvrzení podle § 42 nabídne ke stažení', async () => {
    mocks.pensionRequests.mockResolvedValue([request({ id: 46, request_kind: 'insurance_period_confirmation', requester: 'employee' })])
    const wrapper = await mounted()

    await wrapper.get('[data-test="pension-request-certificate-46"]').trigger('click')
    await flushPromises()

    expect(mocks.downloadPensionInsuranceCertificate).toHaveBeenCalledWith(17, 46)
    expect(wrapper.find('[data-test="pension-request-eldp-46"]').exists()).toBe(false)
  })

  it('bez oprávnění k zápisu nenabízí formulář ani akce', async () => {
    const wrapper = await mounted(false)

    expect(wrapper.find('[data-test="pension-request-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="pension-request-complete-44"]').exists()).toBe(false)
  })
})
