import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  load: vi.fn(),
  save: vi.fn(),
  canWrite: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    quickInputs: m.load,
    saveQuickInputs: m.save,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs-CZ') }),
}))

import PayrollQuickInputs from '@/pages/payroll/PayrollQuickInputs.vue'

describe('PayrollQuickInputs', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.load.mockImplementation(async period => ({
      period,
      items: [{
        employee_id: 8,
        employment_id: 12,
        full_name: 'Syntetická osoba',
        birth_number_masked: '******/**42',
        employment_code: 'SYN-HPP',
        relation_type: 'employment',
        base_amount_minor: 4_200_000,
        base_managed_elsewhere: false,
        base_conflict: false,
        partial_month: false,
        base_requires_entry: false,
        overtime_mode: 'amount',
        overtime_hours_milli: null,
        overtime_amount_minor: 25_000,
        overtime_hourly_rate_minor: null,
        overtime_average_snapshot_id: null,
        overtime_average_snapshot_version: null,
        overtime_hours_available: false,
        overtime_managed_elsewhere: false,
        bonus_amount_minor: 50_000,
        bonus_managed_elsewhere: false,
        other_amount_minor: 0,
        gross_preview_minor: 4_275_000,
        inputs: { base: null, overtime: null, bonus: null },
        blockers: [],
      }],
    }))
    m.save.mockImplementation(async payload => ({
      period: payload.period,
      items: [],
    }))
  })

  it('renders the same employee in desktop row and mobile card with a masked identifier', async () => {
    const wrapper = mount(PayrollQuickInputs)
    await flushPromises()
    expect(wrapper.get('[data-layout="desktop"]').text()).toContain('Syntetická osoba')
    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('Syntetická osoba')
    expect(wrapper.text()).toContain('******/**42')
    expect(wrapper.text()).not.toContain('123456/7842')
    const saveBar = wrapper.get('[data-testid="quick-payroll-save"]').element.parentElement
    expect(saveBar?.classList.contains('sticky')).toBe(false)
    expect(saveBar?.classList.contains('md:sticky')).toBe(true)
    expect(wrapper.get('[data-testid="quick-payroll-save"]').classes()).toContain('w-full')
  })

  it('keeps hour mode unavailable without an approved average and saves one bulk payload', async () => {
    const wrapper = mount(PayrollQuickInputs)
    await flushPromises()
    const hours = wrapper.get('[data-testid="overtime-mode-hours-12"]')
    expect(hours.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save).toHaveBeenCalledTimes(1)
    expect(m.save.mock.calls[0][0].rows[0]).toMatchObject({
      employment_id: 12,
      base_amount_minor: 4_200_000,
      overtime_mode: 'amount',
      overtime_amount_minor: 25_000,
      bonus_amount_minor: 50_000,
    })
  })

  it('blocks saving when an editable amount is empty or invalid', async () => {
    const wrapper = mount(PayrollQuickInputs)
    await flushPromises()
    await wrapper.get('[data-testid="quick-base-12"]').setValue('')
    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('payroll.quick_inputs.invalid_amount')
  })

  it('does not apply a stale response after the payroll period changes', async () => {
    let resolveFirst: ((value: unknown) => void) | undefined
    m.load.mockReset()
    m.load
      .mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
      .mockResolvedValueOnce({ period: '2026-07', items: [] })

    const wrapper = mount(PayrollQuickInputs)
    await flushPromises()
    const periodInput = wrapper.get('[data-testid="quick-payroll-period"]')
    expect(periodInput.attributes('disabled')).toBeDefined()
    const vm = wrapper.vm as unknown as { period: string; load: () => Promise<void> }
    vm.period = '2026-07'
    void vm.load()
    resolveFirst?.({ period: '2026-06', items: [{
      employee_id: 8,
      employment_id: 12,
      full_name: 'Starý měsíc',
      birth_number_masked: null,
      employment_code: 'OLD',
      relation_type: 'employment',
      base_amount_minor: 0,
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
      overtime_hours_available: false,
      overtime_managed_elsewhere: false,
      bonus_amount_minor: 0,
      bonus_managed_elsewhere: false,
      other_amount_minor: 0,
      gross_preview_minor: 0,
      inputs: { base: null, overtime: null, bonus: null },
      blockers: [],
    }] })
    await flushPromises()

    expect(wrapper.text()).not.toContain('Starý měsíc')
    expect(m.load).toHaveBeenLastCalledWith('2026-07')
  })
})
