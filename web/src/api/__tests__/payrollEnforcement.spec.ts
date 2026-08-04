import { describe, expect, it } from 'vitest'
import { pensionEvidenceValues } from '@/api/payrollEnforcement'

describe('payroll enforcement API contract', () => {
  it('uses the backend pension evidence enum', () => {
    expect(pensionEvidenceValues).toEqual(['unknown', 'none', 'verified'])
    expect(pensionEvidenceValues).not.toContain('receives_pension')
  })
})
