import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollOpeningMonth } from '@/api/payroll'

const m = vi.hoisted(() => ({
  load: vi.fn(),
  save: vi.fn(),
  success: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    statutoryOpenings: m.load,
    saveStatutoryOpenings: m.save,
  },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import PayrollOpeningBalancesPanel from '@/pages/payroll/PayrollOpeningBalancesPanel.vue'

describe('PayrollOpeningBalancesPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.load.mockResolvedValue({
      locked: false,
      months: [],
      openings: { social_insurance: null, income_tax: null },
      source_reference: '',
    })
    m.save.mockResolvedValue({
      locked: false,
      months: [],
      openings: { social_insurance: 1, income_tax: 2 },
      source_reference: '',
    })
  })

  it('starts a transferred employee opening with the actual employment month', async () => {
    const wrapper = mount(PayrollOpeningBalancesPanel, {
      props: {
        personId: 8,
        startPeriod: '2026-08',
        canWrite: true,
        includePriorMonths: true,
        firstIncludedMonth: 3,
      },
    })
    await flushPromises()

    for (const month of [3, 4, 5, 6, 7]) {
      await wrapper.get(`[data-test="opening-${month}-confirmed-zero"]`).setValue(true)
    }
    const save = wrapper.get('[data-test="openings-save"]')
    expect(save.attributes('disabled')).toBeUndefined()
    await save.trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledWith(8, {
      year: 2026,
      source_reference: '',
      months: expect.arrayContaining([
        expect.objectContaining({ month: 3, social_assessment_base_minor_units: 0, confirmed_zero: true }),
        expect.objectContaining({ month: 7, advance_base_minor_units: 0, confirmed_zero: true }),
      ]),
    })
    expect(m.save.mock.calls[0][1].months).toHaveLength(5)
    expect(m.save.mock.calls[0][1].months.map((month: PayrollOpeningMonth) => month.month))
      .toEqual([3, 4, 5, 6, 7])
  })

  it('refuses to save an empty month as zero unless it is explicitly confirmed', async () => {
    const wrapper = mount(PayrollOpeningBalancesPanel, {
      props: {
        personId: 8,
        startPeriod: '2026-04',
        canWrite: true,
        includePriorMonths: true,
        firstIncludedMonth: 1,
      },
    })
    await flushPromises()

    await wrapper.get('[data-test="opening-1-advance_base_minor_units"]').setValue('40000')
    await wrapper.get('[data-test="opening-3-advance_base_minor_units"]').setValue('40000')
    await wrapper.get('[data-test="openings-save"]').trigger('click')
    await flushPromises()

    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="openings-error"]').text())
      .toBe('payroll.people.openings.explicit.empty_month')
    // Vyplněný měsíc potvrzení nepotřebuje, a proto ho ani nenabízí.
    expect(wrapper.get('[data-test="opening-1-confirmed-zero"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="opening-2-confirmed-zero"]').setValue(true)
    await wrapper.get('[data-test="openings-save"]').trigger('click')
    await flushPromises()

    const months = m.save.mock.calls[0][1].months as PayrollOpeningMonth[]
    expect(months.map(month => month.confirmed_zero ?? false)).toEqual([false, true, false])
    expect(months[0].advance_base_minor_units).toBe(4_000_000)
  })

  it('saves an explicit zero state without fictitious completed months for a new hire', async () => {
    const wrapper = mount(PayrollOpeningBalancesPanel, {
      props: {
        personId: 9,
        startPeriod: '2026-08',
        canWrite: true,
        includePriorMonths: false,
        firstIncludedMonth: null,
      },
    })
    await flushPromises()

    await wrapper.get('[data-test="openings-save"]').trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledWith(9, {
      year: 2026,
      source_reference: '',
      months: [],
    })
  })

  it('reports incomplete or malformed opening ids as not saved', async () => {
    m.load.mockResolvedValue({ locked: false, months: [], source_reference: '' })
    const wrapper = mount(PayrollOpeningBalancesPanel, {
      props: {
        personId: 10,
        startPeriod: '2026-08',
        canWrite: true,
        includePriorMonths: false,
        firstIncludedMonth: null,
      },
    })
    await flushPromises()

    expect(wrapper.emitted('loaded')).toEqual([[false]])
  })

  it('reports the opening as saved only when all three accumulator ids exist', async () => {
    m.load.mockResolvedValue({
      locked: false,
      months: [],
      openings: { social_insurance: 11, health_insurance: 12, income_tax: 13 },
      source_reference: '',
    })
    const wrapper = mount(PayrollOpeningBalancesPanel, {
      props: {
        personId: 11,
        startPeriod: '2026-08',
        canWrite: true,
        includePriorMonths: false,
        firstIncludedMonth: null,
      },
    })
    await flushPromises()

    expect(wrapper.emitted('loaded')).toEqual([[true]])
  })
})
