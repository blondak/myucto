import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollDimension, PayrollEmploymentDimension } from '@/api/payroll'

const m = vi.hoisted(() => ({
  employmentDimensions: vi.fn(),
  payrollDimensions: vi.fn(),
  createEmploymentDimension: vi.fn(),
  updateEmploymentDimension: vi.fn(),
  splitEmploymentDimension: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employmentDimensions: m.employmentDimensions,
    payrollDimensions: m.payrollDimensions,
    createEmploymentDimension: m.createEmploymentDimension,
    updateEmploymentDimension: m.updateEmploymentDimension,
    splitEmploymentDimension: m.splitEmploymentDimension,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

import EmploymentDimensionsPanel from '@/pages/payroll/EmploymentDimensionsPanel.vue'

function dimension(overrides: Partial<PayrollDimension> = {}): PayrollDimension {
  return {
    id: 7,
    dimension_type: 'cost_center',
    code: 'S01',
    name: 'Provoz',
    account_code: null,
    valid_from: '2026-01-01',
    valid_to: null,
    is_active: true,
    row_version: 1,
    ...overrides,
  } as PayrollDimension
}

function assignment(
  overrides: Partial<PayrollEmploymentDimension> = {},
): PayrollEmploymentDimension {
  return {
    id: 3,
    employment_id: 12,
    dimension_id: 7,
    dimension_type: 'cost_center',
    dimension_code: 'S01',
    dimension_name: 'Provoz',
    valid_from: '2026-01-01',
    valid_to: null,
    row_version: 1,
    ...overrides,
  } as PayrollEmploymentDimension
}

function mountPanel(selectable = false) {
  return mount(EmploymentDimensionsPanel, {
    props: { employmentId: 12, canWrite: true },
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
        SearchableSelect: selectable
          ? {
              props: ['modelValue'],
              emits: ['update:modelValue'],
              template: '<input @input="$emit(\'update:modelValue\', Number($event.target.value))" />',
            }
          : {
              props: ['modelValue'],
              template: '<span />',
            },
      },
    },
  })
}

describe('EmploymentDimensionsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.employmentDimensions.mockResolvedValue([assignment()])
    m.payrollDimensions.mockResolvedValue([dimension()])
    m.createEmploymentDimension.mockResolvedValue(assignment({ id: 4 }))
  })

  it('řekne, proč Uložit nic neudělalo, když chybí dimenze', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-test="dimensions-add"]').trigger('click')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.createEmploymentDimension).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="dimensions-invalid-reason"]').text())
      .toBe('payroll.people.dimensions.dimension_required')
  })

  it('u obráceného intervalu pojmenuje datum, ne mlčí', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    // Přes „upravit" u existujícího přiřazení — dimenze je tím vybraná
    // a zbývá jediná vada: obrácený interval.
    await wrapper.get('[data-test="dimensions-edit"]').trigger('click')
    const dates = wrapper.findAll('input[type="date"]')
    await dates[0].setValue('2026-05-01')
    await dates[1].setValue('2026-04-01')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.updateEmploymentDimension).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="dimensions-invalid-reason"]').text())
      .toBe('payroll.people.dimensions.valid_to_invalid')
  })

  it('prázdný číselník nabídne cestu ven do nastavení mezd', async () => {
    m.payrollDimensions.mockResolvedValue([])
    const wrapper = mountPanel()
    await flushPromises()

    const link = wrapper.get('[data-test="dimensions-none-available"]').get('a')
    expect(link.attributes('data-to')).toContain('payroll-settings')
    expect(link.attributes('data-to')).toContain('dimensions')
  })

  it('nabídku ven neukazuje, dokud je v číselníku účinná dimenze', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="dimensions-none-available"]').exists()).toBe(false)
  })

  it('rozpad, který nedává 100 %, neodešle', async () => {
    m.payrollDimensions.mockResolvedValue([dimension(), dimension({ id: 8, code: 'S02' })])
    const wrapper = mountPanel(true)
    await flushPromises()

    await wrapper.get('[data-test="dimensions-split-open"]').trigger('click')
    await wrapper.get('[data-test="dimensions-split-dimension-0"]').setValue('7')
    await wrapper.get('[data-test="dimensions-split-dimension-1"]').setValue('8')
    await wrapper.get('[data-test="dimensions-split-share-0"]').setValue('70')
    await wrapper.get('[data-test="dimensions-split-share-1"]').setValue('20')
    await wrapper.get('[data-test="dimensions-split-form"]').trigger('submit')
    await flushPromises()

    expect(m.splitEmploymentDimension).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="dimensions-split-total"]').classes()).toContain('text-warning-700')
  })

  it('rozpad 70 / 30 uloží najednou jedním požadavkem', async () => {
    m.payrollDimensions.mockResolvedValue([dimension(), dimension({ id: 8, code: 'S02' })])
    m.splitEmploymentDimension.mockResolvedValue([])
    const wrapper = mountPanel(true)
    await flushPromises()

    await wrapper.get('[data-test="dimensions-split-open"]').trigger('click')
    await wrapper.get('[data-test="dimensions-split-dimension-0"]').setValue('7')
    await wrapper.get('[data-test="dimensions-split-dimension-1"]').setValue('8')
    await wrapper.get('[data-test="dimensions-split-share-0"]').setValue('70')
    await wrapper.get('[data-test="dimensions-split-share-1"]').setValue('30')
    await wrapper.get('[data-test="dimensions-split-form"]').trigger('submit')
    await flushPromises()

    expect(m.splitEmploymentDimension).toHaveBeenCalledWith(12, expect.objectContaining({
      dimension_type: 'cost_center',
      shares: [
        { dimension_id: 7, share_percent: 70 },
        { dimension_id: 8, share_percent: 30 },
      ],
    }))
  })
})
