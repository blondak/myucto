import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  preview: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  approve: vi.fn(),
  materialize: vi.fn(),
  context: vi.fn(),
  canWrite: vi.fn(),
}))

vi.mock('@/api/payrollTravel', () => ({
  payrollTravelApi: {
    list: m.list,
    preview: m.preview,
    create: m.create,
    update: m.update,
    calculation: vi.fn(),
    approve: m.approve,
    materialize: m.materialize,
  },
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: { context: m.context },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

import PayrollTravel from '@/pages/payroll/PayrollTravel.vue'

function trip(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    employee_id: 3,
    employment_id: 5,
    employee_name: 'Syntetická cestující',
    employment_code: 'SYN-TRV-1',
    relation_type: 'employment',
    country_code: 'CZ',
    departure_at: '2026-06-10 08:00:00',
    arrival_at: '2026-06-10 16:00:00',
    origin_place: 'Praha',
    destination_place: 'Brno',
    purpose: 'Jednání',
    transport_mode: 'public_transport',
    meal_rate_band_1_minor: 20000,
    meal_rate_band_2_minor: null,
    meal_rate_band_3_minor: null,
    advance_minor: 0,
    settlement_period_start: '2026-06-01',
    status: 'draft',
    entitlement_total_minor: 20000,
    exempt_total_minor: 18500,
    taxable_total_minor: 1500,
    ruleset_id: 'cz-payroll-2026.travel-allowances.v2',
    calculation: null,
    row_version: 1,
    items: [],
    free_meals: {},
    ...overrides,
  }
}

describe('PayrollTravel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.context.mockResolvedValue([
      { id: 5, employee_id: 3, code: 'SYN-TRV-1', relation_type: 'employment', status: 'active', full_name: 'Syntetická cestující' },
    ])
    m.list.mockResolvedValue([trip()])
  })

  it('renders both the desktop table and the mobile cards', async () => {
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    expect(wrapper.findAll('[data-test="travel-row"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test="travel-card"]')).toHaveLength(1)
  })

  it('hides write and approve actions without the matching permission', async () => {
    m.canWrite.mockImplementation(() => false)
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    expect(wrapper.find('[data-test="travel-new"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('payroll_travel.actions.approve')
  })

  it('offers posting to payroll only for an approved settlement', async () => {
    m.list.mockResolvedValue([trip({ status: 'approved' })])
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    expect(wrapper.find('[data-test="travel-materialize"]').exists()).toBe(true)
  })

  it('previews the split before saving and keeps one shared save button', async () => {
    m.preview.mockResolvedValue({
      status: 'supported',
      blockers: [],
      ruleset_ids: ['cz-payroll-2026.travel-allowances.v2'],
      meal_days: [{
        kind: 'meal_allowance',
        date: '2026-06-10',
        minutes: 480,
        band: 1,
        free_meals: 0,
        base_rate_minor: 20000,
        statutory_minimum_minor: 15500,
        tax_exempt_maximum_minor: 18500,
        entitlement_minor: 20000,
        exempt_minor: 18500,
        taxable_minor: 1500,
        ruleset_id: 'cz-payroll-2026.travel-allowances.v2',
      }],
      items: [],
      entitlement_total_minor: 20000,
      exempt_total_minor: 18500,
      taxable_total_minor: 1500,
      advance_minor: 0,
      settlement_difference_minor: 20000,
      steps: [],
    })
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    await wrapper.find('[data-test="travel-new"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="travel-preview-button"]').trigger('click')
    await flushPromises()

    expect(m.preview).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="travel-preview"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="travel-save"]')).toHaveLength(1)
  })

  it('shows the exact server message inline when saving fails', async () => {
    m.create.mockRejectedValue({
      response: { data: { error: { message: 'Sazba stravného pásma 1 je nižší než zákonné minimum.' } } },
    })
    const wrapper = mount(PayrollTravel)
    await flushPromises()

    await wrapper.find('[data-test="travel-new"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="travel-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="travel-error"]').text())
      .toContain('Sazba stravného pásma 1 je nižší než zákonné minimum.')
  })
})
