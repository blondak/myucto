import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { payrollApi, type PayrollWageStatementList } from '@/api/payroll'

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    wageStatements: vi.fn(),
    generateWageStatement: vi.fn(),
    downloadDocument: vi.fn(),
  },
}))

const toastMocks = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMocks,
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import EmploymentWageStatementPanel from '@/pages/payroll/EmploymentWageStatementPanel.vue'

function list(overrides: Partial<PayrollWageStatementList['readiness']> = {}): PayrollWageStatementList {
  return {
    employment_id: 10,
    readiness: {
      available: true,
      readiness_code: null,
      message: null,
      effective_from: '2026-01-01',
      payment_place: 'Bezhotovostně na platební účet zaměstnance',
      ...overrides,
    },
    items: [],
  }
}

async function mountPanel(canWrite = true) {
  const wrapper = mount(EmploymentWageStatementPanel, {
    props: { employmentId: 10, canWrite },
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(payrollApi.wageStatements).mockResolvedValue(list())
  vi.mocked(payrollApi.generateWageStatement).mockResolvedValue({
    id: 7,
    run_id: null,
    revision_id: null,
    employee_id: 3,
    document_kind: 'wage_statement',
    file_sha256: 'a'.repeat(64),
    size_bytes: 100,
    mime_type: 'application/pdf',
    suggested_filename: 'mzdovy-vymer-abc.pdf',
    created_at: '2026-09-26 10:00:00',
  } as never)
})

describe('EmploymentWageStatementPanel', () => {
  it('předvyplní formulář z připravenosti a vydá výměr', async () => {
    const wrapper = await mountPanel()
    await wrapper.get('[data-test="open-wage-statement-form"]').trigger('click')

    const place = wrapper.get('[data-test="wage-statement-payment-place"]')
    expect((place.element as HTMLInputElement).value).toBe('Bezhotovostně na platební účet zaměstnance')

    await wrapper.get('[data-test="wage-statement-note"]').setValue('Při změně pracoviště')
    await wrapper.get('[data-test="wage-statement-form"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.generateWageStatement).toHaveBeenCalledWith(
      10,
      {
        effective_from: '2026-01-01',
        payment_place: 'Bezhotovostně na platební účet zaměstnance',
        note: 'Při změně pracoviště',
      },
      expect.stringMatching(/^wage-statement-10-/),
    )
    expect(toastMocks.success).toHaveBeenCalled()
    expect(payrollApi.wageStatements).toHaveBeenCalledTimes(2)
  })

  it('bez místa výplaty nevydá a řekne proč', async () => {
    vi.mocked(payrollApi.wageStatements).mockResolvedValue(list({ payment_place: '' }))
    const wrapper = await mountPanel()
    await wrapper.get('[data-test="open-wage-statement-form"]').trigger('click')

    expect(wrapper.get('[data-test="generate-wage-statement"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="wage-statement-block-reason"]').text())
      .toBe('payroll.people.wage_statement.payment_place_required')
    await wrapper.get('[data-test="wage-statement-form"]').trigger('submit')
    expect(payrollApi.generateWageStatement).not.toHaveBeenCalled()
  })

  it('chybějící termín výplaty vysvětlí a nabídne proklik do nastavení', async () => {
    vi.mocked(payrollApi.wageStatements).mockResolvedValue(list({
      available: false,
      readiness_code: 'wage_statement_payday_missing',
      message: 'K 2026-01-01 chybí zaměstnavatelská mzdová politika.',
    }))
    const wrapper = await mountPanel()

    const blocker = wrapper.get('[data-test="wage-statement-blocker"]')
    expect(blocker.text()).toContain('payroll.people.wage_statement.readiness.payday_missing')
    expect(blocker.text()).toContain('chybí zaměstnavatelská mzdová politika')
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'payroll-settings' })
    expect(wrapper.find('[data-test="open-wage-statement-form"]').exists()).toBe(false)
  })

  it('bez práva zápisu jen vypíše vydané výměry', async () => {
    vi.mocked(payrollApi.wageStatements).mockResolvedValue({
      ...list(),
      items: [{
        id: 7,
        run_id: null,
        revision_id: null,
        employee_id: 3,
        document_kind: 'wage_statement',
        file_sha256: 'a'.repeat(64),
        size_bytes: 100,
        mime_type: 'application/pdf',
        suggested_filename: 'mzdovy-vymer-abc.pdf',
        created_at: '2026-09-26 10:00:00',
        effective_from: '2026-01-01',
        wage_statement_revision_no: 2,
      } as never],
    })
    const wrapper = await mountPanel(false)

    expect(wrapper.find('[data-test="open-wage-statement-form"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test="wage-statement-document"]')).toHaveLength(1)
    await wrapper.get('[data-test="download-wage-statement"]').trigger('click')
    expect(payrollApi.downloadDocument).toHaveBeenCalled()
  })
})
