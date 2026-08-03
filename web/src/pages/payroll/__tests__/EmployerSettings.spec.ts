import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type {
  PayrollAccountOption,
  PayrollEmployerAccounts,
  PayrollEmployerSettings,
} from '@/api/payroll'

const m = vi.hoisted(() => ({
  employerSettings: vi.fn(),
  saveEmployerSettings: vi.fn(),
  accountOptions: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employerSettings: m.employerSettings,
    saveEmployerSettings: m.saveEmployerSettings,
    accountOptions: m.accountOptions,
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
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import EmployerSettings from '@/pages/payroll/EmployerSettings.vue'

const defaultAccounts: PayrollEmployerAccounts = {
  employment_gross_debit: '521',
  employment_gross_credit: '331',
  partner_gross_debit: '522',
  partner_gross_credit: '366',
  statutory_gross_debit: '523',
  statutory_gross_credit: '366',
  employer_insurance_debit: '524',
  social_insurance_credit: '336',
  health_insurance_credit: '336',
  income_tax_credit: '342',
  other_deductions_credit: '379',
}

function chartAccount(
  account_code: string,
  account_type: PayrollAccountOption['account_type'],
  is_active = true,
  name = `Účet ${account_code}`,
): PayrollAccountOption {
  return {
    id: Number(account_code.replace(/\D/g, '')) || 1,
    account_code,
    name,
    account_type,
    is_synthetic: account_code.length === 3,
    parent_id: null,
    is_active,
  }
}

function chartAccounts(): PayrollAccountOption[] {
  return [
    ...['521', '522', '523', '524'].map(code => chartAccount(code, 'expense')),
    ...['331', '336', '342', '366', '379'].map(code => chartAccount(code, 'liability')),
    chartAccount('521001', 'expense', true, 'Analytická mzda'),
    chartAccount('521999', 'expense', false, 'Neaktivní mzda'),
  ]
}

function settings(accounts: PayrollEmployerAccounts = defaultAccounts): PayrollEmployerSettings {
  return {
    supplier_id: 1,
    row_version: 3,
    employer_registration_number: '12345678',
    social_security_office_code: 'P',
    health_insurance_payer_number: '87654321',
    default_health_insurer_code: '111',
    payroll_contact_name: 'Testovací účetní',
    payroll_contact_email: 'payroll@example.test',
    payroll_contact_phone: '+420 000 000 000',
    default_office_code: 'MAIN',
    accounts,
    offices: [{
      id: 1,
      code: 'MAIN',
      name: 'Hlavní účtárna',
      is_active: true,
      row_version: 1,
    }],
    created_at: '',
    updated_at: '',
  }
}

async function mountPage(value = settings()) {
  m.employerSettings.mockResolvedValue(value)
  m.accountOptions.mockResolvedValue(chartAccounts())
  m.saveEmployerSettings.mockResolvedValue(value)
  const wrapper = mount(EmployerSettings, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

describe('EmployerSettings — účtová osnova', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    document.body.innerHTML = ''
  })

  it('načte i neaktivní účty pro validaci a nabízí jen aktivní účet správného typu', async () => {
    const wrapper = await mountPage()

    expect(m.accountOptions).toHaveBeenCalledOnce()
    const picker = wrapper.findAll('[data-account-key="employment_gross_debit"]')[0]
    const input = picker.find('input[role="combobox"]')
    await input.trigger('focus')

    const optionTexts = Array.from(document.querySelectorAll<HTMLElement>('[role="option"]'))
      .map(option => option.textContent ?? '')
    expect(optionTexts.some(text => text.startsWith('521Účet 521'))).toBe(true)
    expect(optionTexts).toContain('521001Analytická mzda')
    expect(optionTexts).not.toContain('331')
    expect(optionTexts).not.toContain('521999Neaktivní mzda')

    wrapper.unmount()
  })

  it('typově chybný účet označí a neodešle', async () => {
    const invalid = settings({ ...defaultAccounts, employment_gross_debit: '331' })
    const wrapper = await mountPage(invalid)

    const picker = wrapper.findAll('[data-account-key="employment_gross_debit"]')[0]
    expect(picker.find('input').attributes('aria-invalid')).toBe('true')
    expect(picker.text()).toContain('payroll.employer.validation.account_wrong_type')

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    expect(m.saveEmployerSettings).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('payroll.employer.validation.accounts')

    wrapper.unmount()
  })

  it('umožní účet vyhledat klávesnicí a pošle jeho kód', async () => {
    const wrapper = await mountPage()
    const picker = wrapper.findAll('[data-account-key="employment_gross_debit"]')[0]
    const input = picker.find('input[role="combobox"]')

    await input.trigger('focus')
    await input.setValue('Analytická')
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Enter' })
    expect((input.element as HTMLInputElement).value).toBe('521001')

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveEmployerSettings).toHaveBeenCalledTimes(1)
    expect(m.saveEmployerSettings.mock.calls[0][0].accounts.employment_gross_debit).toBe('521001')

    wrapper.unmount()
  })

  it('při více našeptávačích propojí každý combobox s vlastním listboxem', async () => {
    const wrapper = await mountPage()
    const inputs = wrapper.findAll('input[role="combobox"]')

    await inputs[0].trigger('focus')
    await inputs[1].trigger('focus')

    expect(inputs[0].attributes('aria-controls')).toBeTruthy()
    expect(inputs[1].attributes('aria-controls')).toBeTruthy()
    expect(inputs[0].attributes('aria-controls')).not.toBe(inputs[1].attributes('aria-controls'))

    wrapper.unmount()
  })
})
