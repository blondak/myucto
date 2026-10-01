import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollWarningSuppression } from '@/api/payroll'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  restore: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    listWarningSuppressions: m.list,
    restoreWarnings: m.restore,
  },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, te: () => true }),
}))

import PayrollHiddenWarningsPanel from '@/pages/payroll/PayrollHiddenWarningsPanel.vue'

function item(overrides: Partial<PayrollWarningSuppression> = {}): PayrollWarningSuppression {
  return {
    id: 1,
    code: 'tax_declaration_not_signed_summary',
    subject_type: 'employee',
    subject_id: 7,
    subject_label: 'Syntetická Osoba',
    reason: 'Student',
    created_by: 2,
    created_by_name: 'Syntetická Účetní',
    created_at: '2026-09-30 10:00:00',
    ...overrides,
  }
}

describe('PayrollHiddenWarningsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.restore.mockResolvedValue({ restored: 2 })
  })

  it('seskupí skrytí podle typu a obnoví celou skupinu najednou', async () => {
    m.list.mockResolvedValue({
      items: [
        item(),
        item({ id: 2, subject_id: 8, subject_label: 'Syntetický Člověk' }),
        item({ id: 3, code: 'time_month_missing', subject_type: 'supplier', subject_id: null, subject_label: null, reason: null }),
      ],
      hideable_codes: [],
    })

    const wrapper = mount(PayrollHiddenWarningsPanel, { props: { canWrite: true } })
    await flushPromises()

    const group = wrapper.get('[data-test="hidden-warnings-group-tax_declaration_not_signed_summary"]')
    expect(group.text()).toContain('Syntetický Člověk')
    expect(wrapper.get('[data-test="hidden-warning-3"]').text()).toContain('payroll.warning_suppressions.scope_company')

    await wrapper.get('[data-test="hidden-warnings-restore-all-tax_declaration_not_signed_summary"]').trigger('click')
    await flushPromises()

    expect(m.restore).toHaveBeenCalledWith([1, 2])
    expect(m.list).toHaveBeenCalledTimes(2)
  })

  it('bez práva schvalovat běhy obnovení nenabídne', async () => {
    m.list.mockResolvedValue({ items: [item()], hideable_codes: [] })

    const wrapper = mount(PayrollHiddenWarningsPanel, { props: { canWrite: false } })
    await flushPromises()

    expect(wrapper.find('[data-test="hidden-warning-restore-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="hidden-warnings-no-permission"]').exists()).toBe(true)
  })
})
