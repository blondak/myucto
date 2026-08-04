import { describe, expect, it } from 'vitest'
import {
  formatPayrollMinutes,
  payrollWallTimeToIso,
} from '@/pages/payroll/payrollTime'

describe('payrollTime', () => {
  it('formátuje fond, rozdíl i záporné minuty bez ztráty znaménka', () => {
    expect(formatPayrollMinutes(0)).toBe('0:00')
    expect(formatPayrollMinutes(485)).toBe('8:05')
    expect(formatPayrollMinutes(-75)).toBe('−1:15')
  })

  it('odvodí offset ze zvoleného IANA pásma, ne z pásma prohlížeče', () => {
    expect(payrollWallTimeToIso('2026-06-01T08:00', 'Europe/Prague'))
      .toBe('2026-06-01T08:00:00+02:00')
    expect(payrollWallTimeToIso('2026-06-01T08:00', 'America/New_York'))
      .toBe('2026-06-01T08:00:00-04:00')
  })

  it('neodešle neplatný ani neexistující lokální čas při přechodu DST', () => {
    expect(payrollWallTimeToIso('není-datum', 'Europe/Prague')).toBe('')
    expect(payrollWallTimeToIso('2026-03-29T02:30', 'Europe/Prague')).toBe('')
  })
})
