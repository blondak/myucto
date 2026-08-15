import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { PayrollQuickInputRow } from '@/api/payroll'

const m = vi.hoisted(() => ({
  quickInputs: vi.fn(),
  absences: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { quickInputs: m.quickInputs },
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: { absences: m.absences },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: ref('cs-CZ'),
  }),
}))

import PayrollEmployeeCards from '@/pages/payroll/PayrollEmployeeCards.vue'

function row(overrides: Partial<PayrollQuickInputRow> = {}): PayrollQuickInputRow {
  return {
    employee_id: 5,
    employment_id: 12,
    employment_row_version: 1,
    full_name: 'Alfa Aktivní',
    birth_number_masked: null,
    employment_code: 'SYNTH-HPP',
    relation_type: 'employment',
    effective_status: 'active',
    suspended_in_month: false,
    base_amount_minor: 4_500_000,
    base_managed_elsewhere: false,
    base_conflict: false,
    partial_month: false,
    base_requires_entry: false,
    overtime_mode: 'amount',
    overtime_hours_milli: null,
    overtime_amount_minor: 0,
    overtime_hourly_rate_minor: null,
    overtime_average_snapshot_id: null,
    overtime_average_snapshot_version: null,
    overtime_hours_available: true,
    overtime_hours_relation_supported: true,
    overtime_managed_elsewhere: false,
    overtime_conflict: false,
    bonus_amount_minor: 0,
    bonus_managed_elsewhere: false,
    bonus_conflict: false,
    other_amount_minor: 0,
    non_monetary_amount_minor: 0,
    excluded_from_gross_amount_minor: 0,
    gross_preview_minor: 4_500_000,
    inputs: { base: null, overtime: null, bonus: null },
    blockers: [],
    ...overrides,
  }
}

function mountCards(period = '2026-08') {
  return mount(PayrollEmployeeCards, {
    props: { period },
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  })
}

describe('PayrollEmployeeCards', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.quickInputs.mockResolvedValue({ period: '2026-08', items: [row()] })
    m.absences.mockResolvedValue([])
  })

  it('shows name, employment type, status and pay on one scannable card', async () => {
    const wrapper = mountCards()
    await flushPromises()

    const card = wrapper.get('[data-test="employee-card-12"]')
    expect(card.text()).toContain('Alfa Aktivní')
    expect(card.text()).toContain('payroll.people.relations.employment')
    expect(card.text()).toContain('SYNTH-HPP')
    expect(card.text()).toContain('payroll.people.employment_status.active')
    expect(wrapper.get('[data-test="employee-gross-12"]').text()).toContain('45')
  })

  it('reads the whole month in one request pair, not per employee', async () => {
    mountCards('2026-02')
    await flushPromises()

    expect(m.quickInputs).toHaveBeenCalledTimes(1)
    expect(m.quickInputs).toHaveBeenCalledWith('2026-02')
    // Únor 2026 má 28 dnů — rozsah se počítá, nenatvrdo 30/31.
    expect(m.absences).toHaveBeenCalledWith('2026-02-01', '2026-02-28')
  })

  it('links the quick actions to the existing absence flow with the person preselected', async () => {
    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.get('[data-test="employee-vacation-12"]').attributes('data-to'))
      .toBe('{"name":"payroll-absences","query":{"employment":"12","type":"vacation"}}')
    expect(wrapper.get('[data-test="employee-absence-12"]').attributes('data-to'))
      .toBe('{"name":"payroll-absences","query":{"employment":"12"}}')
    expect(wrapper.get('[data-test="employee-detail-12"]').attributes('data-to'))
      .toBe('{"name":"payroll-people","query":{"person":"5"}}')
  })

  it('marks who is away this month from approved and requested absences', async () => {
    m.absences.mockResolvedValue([
      { id: 1, employment_id: 12, absence_type: 'vacation', date_from: '2026-08-05', date_to: '2026-08-09', status: 'approved' },
      { id: 2, employment_id: 12, absence_type: 'dpn', date_from: '2026-08-20', date_to: '2026-08-20', status: 'cancelled' },
    ])
    const wrapper = mountCards()
    await flushPromises()

    const card = wrapper.get('[data-test="employee-card-12"]')
    expect(card.text()).toContain('payroll_absence.types.vacation 5. 8. – 9. 8.')
    expect(card.text()).not.toContain('payroll_absence.types.dpn')
  })

  it('filters to people who need attention and renders their blockers', async () => {
    m.quickInputs.mockResolvedValue({
      period: '2026-08',
      items: [
        row(),
        row({
          employee_id: 6,
          employment_id: 13,
          full_name: 'Beta Rozpracovaná',
          employment_code: 'SYNTH-DPC',
          base_requires_entry: true,
          blockers: ['partial_month_base_required'],
        }),
      ],
    })
    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.get('[data-test="employee-card-13"]').text())
      .toContain('payroll.quick_inputs.blockers.partial_month_base_required')
    expect(wrapper.get('[data-test="employee-gross-13"]').text())
      .toBe('payroll.employee_cards.base_missing')

    await wrapper.get('[data-test="employee-filter-attention"]').trigger('click')
    expect(wrapper.find('[data-test="employee-card-13"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="employee-card-12"]').exists()).toBe(false)
  })

  it('searches by name and by employment code', async () => {
    m.quickInputs.mockResolvedValue({
      period: '2026-08',
      items: [row(), row({ employment_id: 13, employee_id: 6, full_name: 'Beta Druhá', employment_code: 'SYNTH-DPC' })],
    })
    const wrapper = mountCards()
    await flushPromises()

    await wrapper.get('[data-test="employee-search"]').setValue('dpc')
    expect(wrapper.find('[data-test="employee-card-13"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="employee-card-12"]').exists()).toBe(false)

    await wrapper.get('[data-test="employee-search"]').setValue('alfa aktivni')
    expect(wrapper.find('[data-test="employee-card-12"]').exists()).toBe(true)
  })

  it('still renders the cards when absences cannot be read', async () => {
    m.absences.mockRejectedValue(new Error('403'))
    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.find('[data-test="employee-card-12"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="employee-cards-failed"]').exists()).toBe(false)
  })

  it('explains the failure instead of showing an empty list', async () => {
    m.quickInputs.mockRejectedValue(new Error('500'))
    const wrapper = mountCards()
    await flushPromises()

    expect(wrapper.get('[data-test="employee-cards-failed"]').text())
      .toBe('payroll.employee_cards.load_failed')
  })
})
