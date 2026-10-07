import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  record: vi.fn(),
  download: vi.fn(),
}))

vi.mock('@/api/payrollHealthInsurerNotices', () => ({
  payrollHealthInsurerNoticesApi: {
    list: m.list,
    record: m.record,
    confirmationUrl: (personId: number, coverageId: number) =>
      `/payroll/people/${personId}/health-insurer-notices/${coverageId}/confirmation`,
  },
}))

vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: m.download }))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: () => true,
    locale: { value: 'cs' },
  }),
}))

import PayrollHealthInsurerNoticePanel
  from '@/components/payroll/PayrollHealthInsurerNoticePanel.vue'

function notice(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 7,
    insurer_code: '205',
    insurer_name: 'Česká průmyslová zdravotní pojišťovna (ČPZP)',
    insurer_status: 'verified',
    effective_from: '2026-03-01',
    effective_to: null,
    employee_notified_on: null,
    employer_confirmed_on: null,
    confirmation_available: false,
    ...overrides,
  }
}

function button(wrapper: ReturnType<typeof mount>, label: string) {
  return wrapper.findAll('button').find(item => item.text().includes(label))
}

describe('PayrollHealthInsurerNoticePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.list.mockResolvedValue([])
  })

  it('u osoby bez pojišťovny ukáže prázdný stav', async () => {
    const wrapper = mount(PayrollHealthInsurerNoticePanel, { props: { personId: 42, canWrite: true } })
    await flushPromises()

    expect(m.list).toHaveBeenCalledWith(42)
    expect(wrapper.find('[data-test="health-insurer-notice-empty"]').exists()).toBe(true)
  })

  it('zapíše sdělení a potvrzení a nabídne potvrzení ke stažení', async () => {
    m.list
      .mockResolvedValueOnce([notice()])
      .mockResolvedValueOnce([notice({
        employee_notified_on: '2026-03-02',
        employer_confirmed_on: '2026-03-04',
        confirmation_available: true,
      })])
    m.record.mockResolvedValue(notice())

    const wrapper = mount(PayrollHealthInsurerNoticePanel, { props: { personId: 42, canWrite: true } })
    await flushPromises()

    expect(button(wrapper, 'payroll.healthInsurerNotice.actions.confirmation')?.attributes('disabled'))
      .toBeDefined()

    await wrapper.get('[data-test="health-insurer-notified-on-7"]').setValue('2026-03-02')
    await wrapper.get('[data-test="health-insurer-confirmed-on-7"]').setValue('2026-03-04')
    await button(wrapper, 'payroll.healthInsurerNotice.actions.save')?.trigger('click')
    await flushPromises()

    expect(m.record).toHaveBeenCalledWith(42, 7, {
      employee_notified_on: '2026-03-02',
      employer_confirmed_on: '2026-03-04',
    })
    expect(wrapper.find('[data-test="health-insurer-notice-success"]').exists()).toBe(true)

    const confirmation = button(wrapper, 'payroll.healthInsurerNotice.actions.confirmation')
    expect(confirmation?.attributes('disabled')).toBeUndefined()
    await confirmation?.trigger('click')
    await flushPromises()

    expect(m.download).toHaveBeenCalledWith(
      '/payroll/people/42/health-insurer-notices/7/confirmation',
      'potvrzeni-zp-7.pdf',
    )
  })

  it('bez práva zápisu nenabízí uložení', async () => {
    m.list.mockResolvedValue([notice()])

    const wrapper = mount(PayrollHealthInsurerNoticePanel, { props: { personId: 42, canWrite: false } })
    await flushPromises()

    expect(button(wrapper, 'payroll.healthInsurerNotice.actions.save')?.attributes('disabled'))
      .toBeDefined()
    expect(wrapper.text()).toContain('payroll.healthInsurerNotice.hints.readOnly')
  })

  it('chybu serveru ukáže tak, jak ji server popsal', async () => {
    m.list.mockResolvedValue([notice()])
    const failure = Object.assign(new Error('422'), {
      isAxiosError: true,
      response: { data: { error: { message: 'Zaměstnavatel nemůže přijetí sdělení potvrdit dřív, než ho přijal.' } } },
    })
    m.record.mockRejectedValue(failure)

    const wrapper = mount(PayrollHealthInsurerNoticePanel, { props: { personId: 42, canWrite: true } })
    await flushPromises()
    await button(wrapper, 'payroll.healthInsurerNotice.actions.save')?.trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="health-insurer-notice-error"]').text())
      .toContain('dřív, než ho přijal')
  })
})
