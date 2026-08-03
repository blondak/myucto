import { describe, expect, it } from 'vitest'
import type { PayrollAccountOption } from '@/api/payroll'
import {
  payrollAccountError,
  payrollAccountOptions,
} from '@/pages/payroll/payrollEmployerAccounts'

function account(
  account_code: string,
  account_type: PayrollAccountOption['account_type'],
  is_active = true,
): PayrollAccountOption {
  return {
    id: Number(account_code.replace(/\D/g, '')) || 1,
    account_code,
    name: `Účet ${account_code}`,
    account_type,
    is_synthetic: account_code.length === 3,
    parent_id: null,
    is_active,
  }
}

describe('payrollEmployerAccounts', () => {
  const accounts = [
    account('521', 'expense'),
    account('521001', 'expense'),
    account('331', 'liability'),
    account('379', 'liability', false),
  ]

  it('nabízí jen aktivní účty backendového typu daného slotu', () => {
    expect(payrollAccountOptions(accounts, 'employment_gross_debit').map(option => option.value))
      .toEqual(['521', '521001'])
    expect(payrollAccountOptions(accounts, 'employment_gross_credit').map(option => option.value))
      .toEqual(['331'])
  })

  it('odliší neexistující, neaktivní a typově chybný účet', () => {
    expect(payrollAccountError(accounts, 'employment_gross_debit', '999')).toBe('not_found')
    expect(payrollAccountError(accounts, 'other_deductions_credit', '379')).toBe('inactive')
    expect(payrollAccountError(accounts, 'employment_gross_debit', '331')).toBe('wrong_type')
    expect(payrollAccountError(accounts, 'employment_gross_debit', '52-1')).toBe('invalid_format')
    expect(payrollAccountError(accounts, 'employment_gross_debit', ' 521 ')).toBeNull()
  })
})
