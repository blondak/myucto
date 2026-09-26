import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  revoke: vi.fn(),
  complete: vi.fn(),
  locale: { value: 'cs' },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzDeferrals: m.list,
    revokeJmhzDeferral: m.revoke,
    completeJmhzDeferral: m.complete,
  },
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: (key: string) => key.startsWith('payroll.jmhz_gate.codes.'),
    locale: m.locale,
  }),
}))

import PayrollJmhzDeferralList from '@/pages/payroll/PayrollJmhzDeferralList.vue'

function deferral(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    row_version: 1,
    status: 'active',
    state: 'pending',
    run_id: 8,
    source_revision_id: 18,
    revision_no: 2,
    office_id: 4,
    employee_id: 13,
    employee_name: 'Dana Testovací',
    employment_id: 12,
    reason: 'Průměr se doplní do konce měsíce.',
    blocker_codes: ['jmhz_average_hourly_earning_missing'],
    created_at: '2026-09-02 10:00:00',
    created_by: 1,
    revoked_at: null,
    revoke_reason: null,
    regular_submission_id: null,
    regular_status: null,
    correction_submission_id: null,
    correction_status: null,
    can_revoke: true,
    can_complete: false,
    ...overrides,
  }
}

function listOf(rows: unknown[], overdue = false) {
  return {
    environment: 'production',
    run_id: 8,
    period_start: '2026-08-01',
    due_on: '2026-09-21',
    overdue,
    open_count: rows.length,
    deferrals: rows,
  }
}

const stubs = { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } }

describe('PayrollJmhzDeferralList', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.locale.value = 'cs'
  })

  it('ukáže odložený vztah jako nesplněnou povinnost s lhůtou a důvodem', async () => {
    m.list.mockResolvedValue(listOf([deferral()]))
    const wrapper = mount(PayrollJmhzDeferralList, {
      props: { revisionId: 18, environment: 'production', canWrite: true },
      global: { stubs },
    })
    await flushPromises()

    expect(m.list).toHaveBeenCalledWith(18, 'production')
    expect(wrapper.get('[data-test="jmhz-deferral-obligation"]').text())
      .toContain('payroll.jmhz_gate.deferral.obligation')
    expect(wrapper.text()).toContain('Dana Testovací')
    expect(wrapper.text()).toContain('Průměr se doplní do konce měsíce.')
    expect(wrapper.text()).toContain('payroll.jmhz_gate.codes.jmhz_average_hourly_earning_missing')
    expect(wrapper.get('[data-test="jmhz-deferral-state-5"]').text()).toBe('payroll.jmhz_gate.deferral.state.pending')
  })

  it('po lhůtě varuje důrazněji', async () => {
    m.list.mockResolvedValue(listOf([deferral({ state: 'to_complete', can_revoke: false, can_complete: true })], true))
    const wrapper = mount(PayrollJmhzDeferralList, {
      props: { revisionId: 18, environment: 'production', canWrite: true },
      global: { stubs },
    })
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-deferral-obligation"]').text())
      .toContain('payroll.jmhz_gate.deferral.obligation_overdue')
  })

  it('zruší odložení jen s důvodem', async () => {
    m.list.mockResolvedValue(listOf([deferral()]))
    m.revoke.mockResolvedValue({ revoked_ids: [5], employee_id: 13, source_revision_id: 18 })
    const wrapper = mount(PayrollJmhzDeferralList, {
      props: { revisionId: 18, environment: 'production', canWrite: true },
      global: { stubs },
    })
    await flushPromises()

    await wrapper.get('[data-test="jmhz-deferral-revoke-5"]').trigger('click')
    await wrapper.get('[data-test="jmhz-deferral-revoke-confirm-5"]').trigger('click')
    expect(m.revoke).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('payroll.jmhz_gate.deferral.reason_required')

    await wrapper.get('[data-test="jmhz-deferral-revoke-input-5"]').setValue('Data jsou doplněná.')
    await wrapper.get('[data-test="jmhz-deferral-revoke-confirm-5"]').trigger('click')
    await flushPromises()

    expect(m.revoke).toHaveBeenCalledWith(5, 1, 'Data jsou doplněná.')
    expect(m.list).toHaveBeenCalledTimes(2)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('doplní vztah opravným hlášením a odkáže na stav odeslání', async () => {
    m.list.mockResolvedValue(listOf([deferral({ state: 'to_complete', can_revoke: false, can_complete: true, regular_submission_id: 70, regular_status: 'partially_accepted' })]))
    m.complete.mockResolvedValue({ submission_id: 71, created: true })
    const wrapper = mount(PayrollJmhzDeferralList, {
      props: { revisionId: 18, environment: 'production', canWrite: true },
      global: { stubs },
    })
    await flushPromises()

    await wrapper.get('[data-test="jmhz-deferral-complete-5"]').trigger('click')
    await flushPromises()

    expect(m.complete).toHaveBeenCalledWith(5, 'production')
    expect(wrapper.get('[data-test="jmhz-deferral-success"]').text()).toContain('71')
  })

  it('čtenáři akce nenabídne', async () => {
    m.list.mockResolvedValue(listOf([deferral({ state: 'to_complete', can_complete: true })]))
    const wrapper = mount(PayrollJmhzDeferralList, {
      props: { revisionId: 18, environment: 'production', canWrite: false },
      global: { stubs },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-deferral-complete-5"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="jmhz-deferral-revoke-5"]').exists()).toBe(false)
  })

  it('v angličtině přeloží chybu serveru podle kódu', async () => {
    m.locale.value = 'en'
    m.list.mockResolvedValue(listOf([deferral({ state: 'to_complete', can_revoke: false, can_complete: true })]))
    m.complete.mockRejectedValue({
      response: { data: { error: { code: 'jmhz_deferral_still_blocked', message: 'Odložený vztah zatím doplnit nejde.' } } },
    })
    const wrapper = mount(PayrollJmhzDeferralList, {
      props: { revisionId: 18, environment: 'production', canWrite: true },
      global: { stubs },
    })
    await flushPromises()

    await wrapper.get('[data-test="jmhz-deferral-complete-5"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="jmhz-deferral-error"]').text())
      .toBe('payroll.jmhz_gate.codes.jmhz_deferral_still_blocked')
  })

  it('bez odložení se neukáže', async () => {
    m.list.mockResolvedValue(listOf([]))
    const wrapper = mount(PayrollJmhzDeferralList, {
      props: { revisionId: 18, environment: 'production', canWrite: true },
      global: { stubs },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="jmhz-deferral-list"]').exists()).toBe(false)
  })
})
