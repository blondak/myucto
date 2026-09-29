import { describe, expect, it } from 'vitest'
import { detectAccrualPeriod } from '../accrualPeriod'
// Sdílená sada s backendovým dvojčetem: api/tests/Unit/Service/Accounting/AccrualPeriodDetectorTest.php
// (AccrualPeriodDetector.php). Nový případ přidávej sem do JSON, projde oběma stranami.
import cases from './accrualPeriod.cases.json'

interface Case {
  text: string
  expected: { from: string; to: string } | null
}

describe('detectAccrualPeriod', () => {
  it('sdílená sada není prázdná', () => {
    expect((cases as Case[]).length).toBeGreaterThan(30)
  })

  it.each((cases as Case[]).map((c) => [c.text, c.expected] as const))('%s', (text, expected) => {
    expect(detectAccrualPeriod(text)).toEqual(expected)
  })
})
