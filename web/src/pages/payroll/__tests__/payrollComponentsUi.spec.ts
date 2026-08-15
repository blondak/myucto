import { describe, expect, it } from 'vitest'
import type { PayrollInputImportPreview, PayrollPerson } from '@/api/payroll'
import {
  canApplyPayrollImport,
  localPayrollPeriod,
  monthStart,
  parsePayrollAmountToMinor,
  parsePayrollHoursToMilli,
  payrollEmploymentOptions,
  payrollImportFingerprint,
  payrollImportIssues,
  payrollMinorToInput,
} from '@/pages/payroll/payrollComponentsUi'

function preview(overrides: Partial<PayrollInputImportPreview> = {}): PayrollInputImportPreview {
  return {
    format: 'csv',
    source_name: 'synthetic.csv',
    period: '2026-06',
    content_hash: 'synthetic-hash',
    row_count: 3,
    accepted_count: 1,
    rejected_count: 1,
    duplicate_count: 1,
    rows: [],
    errors: [{
      row_number: 4,
      error_code: 'row_validation_failed',
      field_name: 'amount_minor',
      error_message: 'Synthetic invalid amount.',
    }],
    duplicates: [{
      row_number: 3,
      error_code: 'duplicate_in_file',
      field_name: 'external_id',
      error_message: 'Synthetic duplicate.',
    }],
    ...overrides,
  }
}

describe('payrollComponentsUi', () => {
  it('requires a matching dry-run fingerprint before apply', () => {
    const source = {
      period: '2026-06',
      format: 'csv' as const,
      source_name: 'synthetic.csv',
      content_base64: 'c3ludGhldGlj',
    }
    const fingerprint = payrollImportFingerprint(source)

    expect(canApplyPayrollImport(null, null, fingerprint)).toBe(false)
    expect(canApplyPayrollImport(preview(), fingerprint, fingerprint)).toBe(true)
    expect(canApplyPayrollImport(preview(), fingerprint, payrollImportFingerprint({
      ...source,
      period: '2026-07',
    }))).toBe(false)
    expect(canApplyPayrollImport(preview({ accepted_count: 0 }), fingerprint, fingerprint)).toBe(false)
  })

  it('merges row errors and duplicates in source-row order', () => {
    expect(payrollImportIssues(preview())).toEqual([
      expect.objectContaining({ row_number: 3, kind: 'duplicate', error_code: 'duplicate_in_file' }),
      expect.objectContaining({ row_number: 4, kind: 'error', error_code: 'row_validation_failed' }),
    ])
  })

  it('keeps the employee-employment contract used by mobile cards and forms', () => {
    const people = [{
      id: 8,
      full_name: 'Syntetická osoba',
      employments: [{
        id: 12,
        code: 'SYN-HPP',
        relation_type: 'employment',
        status: 'active',
      }],
    }] as PayrollPerson[]

    expect(payrollEmploymentOptions(people)).toEqual([{
      employee_id: 8,
      employment_id: 12,
      full_name: 'Syntetická osoba',
      code: 'SYN-HPP',
      relation_type: 'employment',
      status: 'active',
    }])
  })

  it('never offers an archived or never-started relation for a payroll input', () => {
    const people = [{
      id: 8,
      full_name: 'Syntetická osoba',
      employments: [
        { id: 12, code: 'SYN-HPP', relation_type: 'employment', status: 'active' },
        { id: 13, code: 'SYN-ARCH', relation_type: 'employment', status: 'archived' },
        { id: 14, code: 'SYN-NOSHOW', relation_type: 'employment', status: 'no_show' },
      ],
    }] as PayrollPerson[]

    expect(payrollEmploymentOptions(people).map(option => option.employment_id))
      .toEqual([12])
  })

  it('converts user amounts without floating-point rounding', () => {
    expect(parsePayrollAmountToMinor('1 234,56')).toBe(123456)
    expect(parsePayrollAmountToMinor('-0,05')).toBe(-5)
    expect(parsePayrollAmountToMinor('12,345')).toBeNull()
    expect(payrollMinorToInput(123456)).toBe('1234,56')
    expect(monthStart('2026-06')).toBe('2026-06-01')
  })

  it('accepts at most thousandths of an overtime hour without rounding user input', () => {
    expect(parsePayrollHoursToMilli('1,25')).toBe(1250)
    expect(parsePayrollHoursToMilli('0.001')).toBe(1)
    expect(parsePayrollHoursToMilli('1.2345')).toBeNull()
    expect(parsePayrollHoursToMilli('-1')).toBeNull()
  })

  it('selects the payroll period from local time around midnight', () => {
    expect(localPayrollPeriod(new Date(2026, 7, 1, 0, 30))).toBe('2026-08')
  })
})
