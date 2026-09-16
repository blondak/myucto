import { describe, expect, it } from 'vitest'
import type { AttendanceSourceChecks } from '@/api/payrollImports'
import { mismatchedPeriods, sourceConfirmationMissing } from '../importHelpers'

function checks(overrides: Partial<AttendanceSourceChecks> = {}): AttendanceSourceChecks {
  return {
    period: { selected: '2025-12', detected: [], mismatch: false },
    other_sources: [],
    requires_confirmation: false,
    ...overrides,
  }
}

describe('kontroly zdroje docházky', () => {
  it('bez nálezu ani u staršího serveru nic nepotvrzuje', () => {
    expect(sourceConfirmationMissing(checks(), false)).toBe(false)
    expect(sourceConfirmationMissing(undefined, false)).toBe(false)
    expect(sourceConfirmationMissing(null, false)).toBe(false)
  })

  it('nález blokuje použití, dokud ho účetní nepotvrdí', () => {
    const found = checks({
      requires_confirmation: true,
      other_sources: [{ attendance_import_id: 1, input_import_id: 2, files: ['a.xlsx'], active_inputs: 3, created_at: '2025-12-01 10:00:00' }],
    })
    expect(sourceConfirmationMissing(found, false)).toBe(true)
    expect(sourceConfirmationMissing(found, true)).toBe(false)
  })

  it('vrátí jen období, která se liší od vybraného', () => {
    const period = {
      selected: '2025-12',
      detected: [
        { period: '2025-11', sources: ['podklady 11-2025.xlsx'] },
        { period: '2025-12', sources: ['1225.xlsx'] },
      ],
      mismatch: true,
    }
    expect(mismatchedPeriods(checks({ period, requires_confirmation: true }))).toEqual(['2025-11'])
    expect(mismatchedPeriods(checks())).toEqual([])
  })
})
