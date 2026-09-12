import { describe, expect, it } from 'vitest'
import { isCoveredByParent, toggleTurnoverRow } from '../netTurnoverRows'

describe('netTurnoverRows', () => {
  it('podřádek zvoleného rodiče je v něm zahrnutý', () => {
    expect(isCoveredByParent('IV.2.', ['IV.'])).toBe(true)
    expect(isCoveredByParent('IV.', ['IV.'])).toBe(false)
    expect(isCoveredByParent('IV.2.', ['III.'])).toBe(false)
  })

  it('římské číslice se nepletou (VI. není rodič VII., II. není rodič III.)', () => {
    expect(isCoveredByParent('VII.', ['VI.'])).toBe(false)
    expect(isCoveredByParent('VI.1.', ['V.'])).toBe(false)
    expect(isCoveredByParent('III.', ['II.'])).toBe(false)
  })

  it('zaškrtnutí rodiče odebere jeho podřádky, odškrtnutí jen řádek sám', () => {
    expect(toggleTurnoverRow(['IV.1.', 'IV.2.', 'VI.'], 'IV.', true)).toEqual(['VI.', 'IV.'])
    expect(toggleTurnoverRow(['IV.', 'VI.'], 'IV.', false)).toEqual(['VI.'])
    expect(toggleTurnoverRow(['III.1.'], 'III.1.', true)).toEqual(['III.1.'])
  })
})
