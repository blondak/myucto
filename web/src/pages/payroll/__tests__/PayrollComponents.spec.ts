import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  components: vi.fn(),
  recurringComponents: vi.fn(),
  inputs: vi.fn(),
  people: vi.fn(),
  person: vi.fn(),
  previewInputImport: vi.fn(),
  applyInputImport: vi.fn(),
  canWrite: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  slugify: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    components: m.components,
    recurringComponents: m.recurringComponents,
    inputs: m.inputs,
    people: m.people,
    person: m.person,
    previewInputImport: m.previewInputImport,
    applyInputImport: m.applyInputImport,
    createComponent: vi.fn(),
    updateComponent: vi.fn(),
    createRecurringComponent: vi.fn(),
    updateRecurringComponent: vi.fn(),
    materializeRecurringComponents: vi.fn(),
    previewInput: vi.fn(),
    createInput: vi.fn(),
    updateInput: vi.fn(),
    approveInput: vi.fn(),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('@/api/slug', () => ({
  slugify: m.slugify,
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: ref('cs-CZ'),
  }),
}))

import PayrollComponents from '@/pages/payroll/PayrollComponents.vue'

describe('PayrollComponents', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.components.mockResolvedValue([{
      id: 5,
      supplier_id: 1,
      code: 'SYN_BONUS',
      name: 'Syntetická odměna',
      component_kind: 'bonus',
      value_kind: 'monetary',
      frequency_kind: 'one_off',
      tax_treatment: 'included',
      social_participation_treatment: 'included',
      social_treatment: 'included',
      health_participation_treatment: 'included',
      health_treatment: 'included',
      average_earning_treatment: 'included',
      enforcement_treatment: 'included',
      jmhz_treatment: 'included',
      statistics_treatment: 'included',
      accounting_debit_code: null,
      accounting_credit_code: null,
      annual_limit_minor: null,
      valid_from: '2026-01-01',
      valid_to: null,
      is_active: true,
      row_version: 1,
      created_at: '2026-01-01 00:00:00',
      updated_at: '2026-01-01 00:00:00',
    }])
    m.recurringComponents.mockResolvedValue([])
    m.inputs.mockResolvedValue([{
      id: 9,
      supplier_id: 1,
      employee_id: 8,
      employee_name: 'Syntetická osoba',
      employment_id: 12,
      employment_code: 'SYN-HPP',
      relation_type: 'employment',
      component_id: 5,
      component_code: 'SYN_BONUS',
      component_name: 'Syntetická odměna',
      component_kind: 'bonus',
      value_kind: 'monetary',
      period_start: '2026-06-01',
      source_period_start: null,
      amount_minor: 25000,
      quantity_milliunits: null,
      source_kind: 'manual',
      external_id: 'synthetic-1',
      import_id: null,
      status: 'draft',
      component_snapshot_json: null,
      row_version: 1,
      created_by: 1,
      approved_by: null,
      approved_at: null,
      created_at: '2026-06-01 00:00:00',
      updated_at: '2026-06-01 00:00:00',
    }])
    m.people.mockResolvedValue([{ id: 8, full_name: 'Syntetická osoba' }])
    m.person.mockResolvedValue({
      id: 8,
      full_name: 'Syntetická osoba',
      employments: [{
        id: 12,
        code: 'SYN-HPP',
        relation_type: 'employment',
        status: 'active',
      }],
    })
    m.previewInputImport.mockResolvedValue({
      format: 'csv',
      source_name: 'synthetic.csv',
      period: '2026-06',
      content_hash: 'synthetic-hash',
      row_count: 1,
      accepted_count: 1,
      rejected_count: 0,
      duplicate_count: 0,
      rows: [],
      errors: [],
      duplicates: [],
    })
    m.applyInputImport.mockResolvedValue({
      id: 20,
      status: 'accepted',
      accepted_count: 1,
      rejected_count: 0,
      duplicate_count: 0,
      replayed: false,
      rows: [],
    })
    m.slugify.mockResolvedValue('ceska-odmena')
  })

  it('renders matching desktop tables and mobile cards from one API contract', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()

    expect(wrapper.get('[data-layout="desktop"]').text()).toContain('Syntetická osoba')
    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('Syntetická osoba')
    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('SYN_BONUS')
    wrapper.unmount()
  })

  it('keeps apply disabled until the exact file has a successful dry-run', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    const importTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.import')
    await importTab!.trigger('click')

    const fileInput = wrapper.get('[data-testid="payroll-import-file"]')
    const file = new File([
      'employment_id;employment_code;component_code;amount_minor;external_id\n'
      + '12;SYN-HPP;SYN_BONUS;25000;synthetic-1',
    ], 'synthetic.csv', { type: 'text/csv' })
    Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
    await fileInput.trigger('change')
    await vi.waitFor(() => {
      const previewButton = wrapper.findAll('button')
        .find(button => button.text() === 'payroll.components.import.preview')
      expect(previewButton!.attributes('disabled')).toBeUndefined()
    })

    const apply = wrapper.get('[data-testid="payroll-import-apply"]')
    expect(apply.attributes('disabled')).toBeDefined()
    await apply.trigger('click')
    expect(m.applyInputImport).not.toHaveBeenCalled()

    const preview = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.import.preview')
    await preview!.trigger('click')
    await flushPromises()
    expect(m.previewInputImport).toHaveBeenCalledTimes(1)
    const enabledApply = wrapper.get('[data-testid="payroll-import-apply"]')
    expect(enabledApply.attributes('disabled')).toBeUndefined()

    await enabledApply.trigger('click')
    await flushPromises()
    expect(m.applyInputImport).toHaveBeenCalledTimes(1)
    expect(m.applyInputImport.mock.calls[0][0]).toMatchObject({
      format: 'csv',
      source_name: 'synthetic.csv',
    })
    expect(m.applyInputImport.mock.calls[0][0].content_base64).not.toBe('')
    wrapper.unmount()
  })

  it('does not expose import controls without payroll input write permission', async () => {
    m.canWrite.mockImplementation((permission: string) => permission !== 'payroll.inputs.write')
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    const importTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.import')
    await importTab!.trigger('click')

    expect(wrapper.find('[data-testid="payroll-import-file"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="payroll-import-apply"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('accepts a supported file dropped into the import zone', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    const importTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.import')
    await importTab!.trigger('click')

    const file = new File([
      'employment_id;component_code;amount_minor\n12;SYN_BONUS;25000',
    ], 'synthetic.xlsx', {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    })
    await wrapper.get('[data-testid="payroll-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [file] },
    })

    await vi.waitFor(() => {
      expect(wrapper.get('[data-testid="payroll-import-selected"]').attributes('title')).toBe('synthetic.xlsx')
      const previewButton = wrapper.findAll('button')
        .find(button => button.text() === 'payroll.components.import.preview')
      expect(previewButton!.attributes('disabled')).toBeUndefined()
    })

    const unsupported = new File(['unsupported'], 'synthetic.txt', { type: 'text/plain' })
    await wrapper.get('[data-testid="payroll-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [unsupported] },
    })
    expect(wrapper.find('[data-testid="payroll-import-selected"]').exists()).toBe(false)
    const previewButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.import.preview')
    expect(previewButton!.attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('creates the code from the name until the user edits it manually', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    const catalogTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.catalog')
    await catalogTab!.trigger('click')
    const addButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.catalog.add')
    await addButton!.trigger('click')

    vi.useFakeTimers()
    await wrapper.get('[data-testid="payroll-component-name"]').setValue('Česká odměna')
    await vi.advanceTimersByTimeAsync(301)
    await flushPromises()

    expect(m.slugify).toHaveBeenCalledWith('Česká odměna')
    const codeInput = wrapper.get('[data-testid="payroll-component-code"]')
    expect((codeInput.element as HTMLInputElement).value).toBe('CESKA-ODMENA')

    await codeInput.setValue('VLASTNI_KOD')
    await wrapper.get('[data-testid="payroll-component-name"]').setValue('Jiný název')
    await vi.advanceTimersByTimeAsync(301)
    await flushPromises()

    expect((codeInput.element as HTMLInputElement).value).toBe('VLASTNI_KOD')
    vi.useRealTimers()
    wrapper.unmount()
  })
})
