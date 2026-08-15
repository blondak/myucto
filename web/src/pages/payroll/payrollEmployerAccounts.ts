import type {
  PayrollAccountOption,
  PayrollEmployerAccounts,
} from '@/api/payroll'

export type PayrollAccountKey = keyof PayrollEmployerAccounts
export type PayrollAccountError = 'invalid_format' | 'not_found' | 'inactive' | 'wrong_type'

export const PAYROLL_ACCOUNT_TYPES: Record<PayrollAccountKey, PayrollAccountOption['account_type']> = {
  employment_gross_debit: 'expense',
  employment_gross_credit: 'liability',
  partner_gross_debit: 'expense',
  partner_gross_credit: 'liability',
  statutory_gross_debit: 'expense',
  statutory_gross_credit: 'liability',
  employer_insurance_debit: 'expense',
  social_insurance_credit: 'liability',
  health_insurance_credit: 'liability',
  income_tax_credit: 'liability',
  other_deductions_credit: 'liability',
  partner_settlement_credit: 'liability',
}

export function normalizedPayrollAccountCode(value: string): string {
  return value.trim().toUpperCase()
}

export function payrollAccount(
  accounts: PayrollAccountOption[],
  code: string,
): PayrollAccountOption | undefined {
  const normalized = normalizedPayrollAccountCode(code)
  return accounts.find(account => normalizedPayrollAccountCode(account.account_code) === normalized)
}

export function payrollAccountError(
  accounts: PayrollAccountOption[],
  key: PayrollAccountKey,
  code: string,
): PayrollAccountError | null {
  const normalized = normalizedPayrollAccountCode(code)
  if (!/^[0-9]{3}[.A-Z0-9]{0,7}$/.test(normalized)) return 'invalid_format'

  const account = payrollAccount(accounts, normalized)
  if (!account) return 'not_found'
  if (!account.is_active) return 'inactive'
  if (account.account_type !== PAYROLL_ACCOUNT_TYPES[key]) return 'wrong_type'
  return null
}

export function payrollAccountOptions(accounts: PayrollAccountOption[], key: PayrollAccountKey) {
  return accounts
    .filter(account => account.is_active && account.account_type === PAYROLL_ACCOUNT_TYPES[key])
    .sort((a, b) => a.account_code.localeCompare(b.account_code))
    .map(account => ({
      value: normalizedPayrollAccountCode(account.account_code),
      label: normalizedPayrollAccountCode(account.account_code),
      secondary: account.name,
    }))
}
