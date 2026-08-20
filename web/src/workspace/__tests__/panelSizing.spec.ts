import { describe, expect, it } from 'vitest'
import { resizePaneFractions } from '@/workspace/panelSizing'

describe('panel sizing', () => {
  it('posune jen sousední panely a zachová jejich součet', () => {
    const resized = resizePaneFractions([1 / 3, 1 / 3, 1 / 3], 0, 160, 1580)

    expect(resized[0]).toBeGreaterThan(1 / 3)
    expect(resized[1]).toBeLessThan(1 / 3)
    expect(resized[0] + resized[1]).toBeCloseTo(2 / 3)
    expect(resized[2]).toBeCloseTo(1 / 3)
  })

  it('neumožní zmenšit panel pod minimální šířku', () => {
    const resized = resizePaneFractions([0.5, 0.5], 0, -1000, 1000)

    expect(resized[0]).toBeCloseTo(0.288)
    expect(resized[1]).toBeCloseTo(0.712)
  })
})
