import { describe, expect, it } from 'vitest'
import type { PayrollPaymentBatch, PayrollPaymentLiability } from '@/api/payrollPayments'
import {
  batchPeriodLabel,
  effectivePaymentDate,
  isLatePaymentDate,
  requestedPaymentDate,
  selectionDefaultPaymentDate,
  todayIso,
} from '../payrollPaymentDate'

function liability(overrides: Partial<PayrollPaymentLiability>): PayrollPaymentLiability {
  return {
    id: 1,
    liability_kind: 'social_insurance',
    due_on: '2026-10-20',
    payment_on: '2026-10-19',
    ...overrides,
  } as PayrollPaymentLiability
}

describe('payrollPaymentDate', () => {
  it('„podle splatnosti" serveru datum neposílá, dnes a vlastní ano', () => {
    expect(requestedPaymentDate({ mode: 'statutory', customDate: '2026-10-05' }, '2026-10-01')).toBeNull()
    expect(requestedPaymentDate({ mode: 'today', customDate: '' }, '2026-10-01')).toBe('2026-10-01')
    expect(requestedPaymentDate({ mode: 'custom', customDate: '2026-10-05' }, '2026-10-01')).toBe('2026-10-05')
    expect(requestedPaymentDate({ mode: 'custom', customDate: '' }, '2026-10-01')).toBeNull()
  })

  it('výchozí datum výběru bere datum příkazu odvodů, smíšený výběr zákonnou splatnost', () => {
    expect(selectionDefaultPaymentDate([liability({}), liability({ id: 2 })])).toBe('2026-10-19')
    expect(selectionDefaultPaymentDate([
      liability({}),
      liability({ id: 2, liability_kind: 'net_wage', payment_on: '2026-10-20' }),
    ])).toBe('2026-10-20')
    expect(selectionDefaultPaymentDate([])).toBeNull()
  })

  it('pozdní je jen datum PO poslední včasné splatnosti', () => {
    const today = '2026-10-01'
    const defaultDate = '2026-10-19'
    expect(isLatePaymentDate(effectivePaymentDate({ mode: 'today', customDate: '' }, defaultDate, today), defaultDate)).toBe(false)
    expect(isLatePaymentDate('2026-10-19', defaultDate)).toBe(false)
    expect(isLatePaymentDate('2026-10-20', defaultDate)).toBe(true)
  })

  it('popíše období dávky lidsky', () => {
    const batch = { period_from: '2026-08', period_to: '2026-09' } as PayrollPaymentBatch
    expect(batchPeriodLabel(batch)).toBe('8/2026 – 9/2026')
    expect(batchPeriodLabel({ ...batch, period_from: '2026-09' })).toBe('9/2026')
    expect(batchPeriodLabel({ ...batch, period_from: null })).toBeNull()
  })

  it('dnešek skládá z místního času', () => {
    expect(todayIso(new Date(2026, 9, 1, 0, 30))).toBe('2026-10-01')
  })
})
