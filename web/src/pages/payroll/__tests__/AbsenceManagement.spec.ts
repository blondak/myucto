import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  context: vi.fn(),
  absences: vi.fn(),
  averages: vi.fn(),
  leaveLedger: vi.fn(),
  decide: vi.fn(),
  createAbsence: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: {
    context: m.context,
    absences: m.absences,
    averages: m.averages,
    leaveLedger: m.leaveLedger,
    decide: m.decide,
    createAbsence: m.createAbsence,
    cancel: vi.fn(),
    createAverage: vi.fn(),
    approveAverage: vi.fn(),
    createLeaveEntry: vi.fn(),
    createEntitlement: vi.fn(),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: ref('cs-CZ'),
  }),
}))

import AbsenceManagement from '@/pages/payroll/AbsenceManagement.vue'

describe('AbsenceManagement', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.context.mockResolvedValue([{
      id: 12,
      employee_id: 5,
      code: 'SYNTH-HPP',
      relation_type: 'employment',
      status: 'active',
      full_name: 'Syntetická osoba',
    }])
    m.absences.mockResolvedValue([{
      id: 44,
      employment_id: 12,
      full_name: 'Syntetická osoba',
      employment_code: 'SYNTH-HPP',
      absence_type: 'dpn',
      date_from: '2026-06-15',
      date_to: '2026-06-28',
      partial_first_minutes: null,
      partial_last_minutes: null,
      average_snapshot_id: 8,
      average_hourly_minor: 50_000,
      note: null,
      support_status: 'manual_review',
      status: 'requested',
      correction_pending: false,
      row_version: 1,
    }])
    m.averages.mockResolvedValue([{
      id: 8,
      employment_id: 12,
      applicable_year: 2026,
      applicable_quarter: 2,
      source_kind: 'actual',
      average_hourly_minor: 50_000,
      rationale: null,
      support_status: 'manual_review',
      status: 'approved',
      row_version: 2,
    }])
    m.leaveLedger.mockResolvedValue({ entries: [], balance_minutes: 0 })
    m.decide.mockResolvedValue({ id: 44, status: 'approved' })
  })

  it('renders a responsive DPN card and sends explicit review flags', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll_absence.types.dpn')
    expect(wrapper.text()).toContain('Syntetická osoba')
    const checks = wrapper.findAll('input[type="checkbox"]')
    expect(checks).toHaveLength(3)
    await checks[0].setValue(true)
    await checks[1].setValue(true)
    const approve = wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.actions.approve'))
    await approve!.trigger('click')
    await flushPromises()

    expect(m.decide).toHaveBeenCalledWith(44, {
      row_version: 1,
      decision: 'approved',
      first_day_fully_worked: false,
      insurance_eligibility_confirmed: true,
      conflicting_benefit_excluded: true,
    })
    wrapper.unmount()
  })

  it('exposes all three agenda tabs on the same mobile-safe page', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()
    expect(wrapper.text()).toContain('payroll_absence.tabs.absences')
    expect(wrapper.text()).toContain('payroll_absence.tabs.averages')
    expect(wrapper.text()).toContain('payroll_absence.tabs.leave')
    const activeTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll_absence.tabs.absences')
    expect(activeTab!.classes()).toContain('border-payroll-600')
    expect(activeTab!.classes()).not.toContain('bg-payroll-600')
    wrapper.unmount()
  })

  it('uses searchable selectors and visibly bordered controls in forms', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    expect(wrapper.findAll('[role="combobox"]').length).toBeGreaterThan(0)
    const averagesTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll_absence.tabs.averages')
    await averagesTab!.trigger('click')

    const formInputs = wrapper.findAll('input[type="number"], input[type="date"], input[type="text"]')
    expect(formInputs.length).toBeGreaterThan(0)
    for (const input of formInputs) {
      expect(input.classes()).toContain('border-neutral-300')
      expect(input.classes()).toContain('bg-surface')
    }
    wrapper.unmount()
  })

  it('does not submit an absence requiring an approved average without one', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const create = wrapper.findAll('button')
      .find(button => button.text().includes('payroll_absence.absences.create'))
    expect(create!.attributes('disabled')).toBeDefined()
    await create!.trigger('click')

    expect(m.createAbsence).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('loads through the actual last local day of the month', async () => {
    const wrapper = mount(AbsenceManagement)
    await flushPromises()

    const today = new Date()
    const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0)
    const expected = `${lastDay.getFullYear()}-${String(lastDay.getMonth() + 1).padStart(2, '0')}-${String(lastDay.getDate()).padStart(2, '0')}`
    expect(m.absences.mock.calls[0][1]).toBe(expected)
    wrapper.unmount()
  })
})
