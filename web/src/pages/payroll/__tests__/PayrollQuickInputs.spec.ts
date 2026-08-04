import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type {
  PayrollQuickInputRef,
  PayrollQuickInputRow,
} from '@/api/payroll'

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

function inputRef(
  status: PayrollQuickInputRef['status'],
  overrides: Partial<PayrollQuickInputRef> = {},
): PayrollQuickInputRef {
  return {
    id: 101,
    amount_minor: 4_200_000,
    quantity_milliunits: null,
    source_kind: 'manual',
    status,
    row_version: 3,
    source_snapshot: null,
    ...overrides,
  }
}

function fixture(overrides: Partial<PayrollQuickInputRow> = {}): PayrollQuickInputRow {
  return {
    employee_id: 8,
    employment_id: 12,
    employment_row_version: 7,
    full_name: 'Syntetická osoba',
    birth_number_masked: '******/**42',
    employment_code: 'SYN-HPP',
    relation_type: 'employment',
    effective_status: 'active',
    suspended_in_month: false,
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
    overtime_hours_relation_supported: true,
    overtime_managed_elsewhere: false,
    overtime_conflict: false,
    bonus_amount_minor: 50_000,
    bonus_managed_elsewhere: false,
    bonus_conflict: false,
    other_amount_minor: 0,
    non_monetary_amount_minor: 0,
    excluded_from_gross_amount_minor: 0,
    gross_preview_minor: 4_275_000,
    inputs: { base: null, overtime: null, bonus: null },
    blockers: [],
    ...overrides,
  }
}

function mountPage() {
  return mount(PayrollQuickInputs, {
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

describe('PayrollQuickInputs', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture()],
    }))
    m.save.mockImplementation(async payload => ({
      period: payload.period,
      items: [],
    }))
  })

  it('keeps two employments of one person separate and labels statutory income correctly', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [
        fixture(),
        fixture({
          employment_id: 13,
          employment_row_version: 4,
          employment_code: 'SYN-JED',
          relation_type: 'statutory_body',
          base_amount_minor: 800_000,
          overtime_amount_minor: 0,
          bonus_amount_minor: 0,
          gross_preview_minor: 800_000,
          overtime_hours_relation_supported: false,
        }),
      ],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-layout="desktop"]').findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.get('[data-testid="quick-relation-12"]').text())
      .toBe('payroll.people.relations.employment')
    expect(wrapper.get('[data-testid="quick-relation-13"]').text())
      .toBe('payroll.people.relations.statutory_body')
    expect(wrapper.get('[data-testid="quick-income-label-13"]').text())
      .toBe('payroll.quick_inputs.income_labels.statutory_body')
    expect(wrapper.find('[data-testid="overtime-mode-hours-13"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.quick_inputs.amount_only_relation_hint')
  })

  it('keeps partner dependent income amount-only as well', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        relation_type: 'partner_dependent',
        overtime_hours_relation_supported: false,
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-income-label-12"]').text())
      .toBe('payroll.quick_inputs.income_labels.partner_dependent')
    expect(wrapper.find('[data-testid="overtime-mode-hours-12"]').exists()).toBe(false)
  })

  it('labels a suspension that occurred during an otherwise active month', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        effective_status: 'active',
        suspended_in_month: true,
        base_requires_entry: true,
        base_amount_minor: 0,
        blockers: ['suspended_month_base_required'],
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-status-12"]').text())
      .toBe('payroll.quick_inputs.suspended_in_month')
    expect(wrapper.text()).toContain('payroll.quick_inputs.blockers.suspended_month_base_required')
  })

  it('renders the mobile form, masked identifier and lg sticky action bar', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('Syntetická osoba')
    expect(wrapper.find('[data-testid="quick-base-mobile-12"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quick-relation-mobile-12"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('******/**42')
    expect(wrapper.text()).not.toContain('123456/7842')
    const actionBar = wrapper.get('[data-testid="quick-payroll-save"]').element.parentElement
    expect(actionBar?.classList.contains('lg:sticky')).toBe(true)
    expect(actionBar?.classList.contains('md:sticky')).toBe(false)
    expect(wrapper.get('[data-testid="quick-payroll-save"]').classes()).toContain('w-full')
    expect(wrapper.get('[data-testid="quick-payroll-runs"]').text())
      .toContain('payroll.quick_inputs.continue_to_runs')
  })

  it('keeps hour mode unavailable without an average and sends employment concurrency version', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="overtime-mode-hours-12"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledTimes(1)
    expect(m.save.mock.calls[0][0].rows[0]).toMatchObject({
      employment_id: 12,
      employment_row_version: 7,
      base_amount_minor: 4_200_000,
      overtime_mode: 'amount',
      overtime_amount_minor: 25_000,
      bonus_amount_minor: 50_000,
    })
  })

  it('explains and protects locked, approved, draft and externally managed fields', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [
        fixture({
          inputs: {
            base: inputRef('locked'),
            overtime: inputRef('approved', { id: 102, amount_minor: 25_000 }),
            bonus: inputRef('draft', { id: 103, amount_minor: 50_000 }),
          },
        }),
        fixture({
          employment_id: 13,
          employment_code: 'SYN-DPC',
          relation_type: 'dpc',
          bonus_managed_elsewhere: true,
        }),
      ],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="quick-base-state-12"]').text())
      .toBe('payroll.quick_inputs.field_state.locked')
    expect(wrapper.get('[data-testid="quick-overtime-state-12"]').text())
      .toBe('payroll.quick_inputs.field_state.approved')
    expect(wrapper.get('[data-testid="quick-bonus-state-12"]').text())
      .toBe('payroll.quick_inputs.field_state.draft')
    expect(wrapper.get('[data-testid="quick-bonus-state-13"]').text())
      .toBe('payroll.quick_inputs.field_state.managed')
  })

  it('shows effective suspended status and requires an explicit base', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        effective_status: 'suspended',
        suspended_in_month: true,
        base_requires_entry: true,
        base_amount_minor: 0,
        blockers: ['suspended_month_base_required'],
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-status-12"]').text())
      .toBe('payroll.quick_inputs.suspended_in_month')
    expect(wrapper.text()).toContain('payroll.quick_inputs.blockers.suspended_month_base_required')
    expect(wrapper.get('[data-testid="quick-base-12"]').element).toHaveProperty('value', '')
    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeDefined()
  })

  it('uses read-only mode while keeping the payroll-run navigation available', async () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="quick-payroll-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="quick-payroll-runs"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll.quick_inputs.readonly_hint')
  })

  it('blocks saving when an editable amount is empty or invalid', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-base-12"]').setValue('')

    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('payroll.quick_inputs.validation.amount_required')
    expect(wrapper.find('[data-testid="quick-payroll-validation-summary"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('aria-invalid')).toBe('true')
  })

  it('rejects a negative amount locally and keeps the gross preview fail-safe', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-base-12"]').setValue('-1')

    expect(wrapper.text()).toContain('payroll.quick_inputs.validation.amount_non_negative')
    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeDefined()
    expect(m.save).not.toHaveBeenCalled()
  })

  it('keeps the exact API failure visible and offers reload after employment conflict', async () => {
    m.save.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            code: 'employment_row_version_conflict',
            message: 'Syntetický vztah mezitím změnil jiný uživatel.',
          },
        },
      },
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-payroll-save-error"]').text())
      .toContain('Syntetický vztah mezitím změnil jiný uživatel.')
    expect(wrapper.find('[data-testid="quick-payroll-conflict-refresh"]').exists()).toBe(true)
    expect(m.error).toHaveBeenCalledWith('Syntetický vztah mezitím změnil jiný uživatel.')

    await wrapper.get('[data-testid="quick-payroll-conflict-refresh"]').trigger('click')
    await flushPromises()
    expect(m.load).toHaveBeenCalledTimes(2)
  })

  it('does not apply a stale response after the payroll period changes', async () => {
    let resolveFirst: ((value: unknown) => void) | undefined
    m.load.mockReset()
    m.load
      .mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
      .mockResolvedValueOnce({ period: '2026-07', items: [] })

    const wrapper = mountPage()
    await flushPromises()
    const periodInput = wrapper.get('[data-testid="quick-payroll-period"]')
    expect(periodInput.attributes('disabled')).toBeDefined()
    const vm = wrapper.vm as unknown as { period: string; load: () => Promise<void> }
    vm.period = '2026-07'
    void vm.load()
    resolveFirst?.({ period: '2026-06', items: [fixture({ full_name: 'Starý měsíc' })] })
    await flushPromises()

    expect(wrapper.text()).not.toContain('Starý měsíc')
    expect(m.load).toHaveBeenLastCalledWith('2026-07')
  })

  it('invalidates old rows when loading a new payroll period fails', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('Syntetická osoba')

    m.load.mockRejectedValueOnce(new Error('synthetic load failure'))
    const vm = wrapper.vm as unknown as { period: string; load: () => Promise<void> }
    vm.period = '2026-07'
    await vm.load()
    await flushPromises()

    expect(wrapper.text()).not.toContain('Syntetická osoba')
    expect(wrapper.find('[data-testid="quick-payroll-save"]').exists()).toBe(false)
    expect(m.save).not.toHaveBeenCalled()
  })
})
