import { describe, expect, it } from 'vitest'
import { isCoveredByParent, isTurnoverRowVisible, toggleTurnoverRow } from '../netTurnoverRows'

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

describe('viditelnost řádku podle obratu', () => {
  const row = (over: Partial<Parameters<typeof isTurnoverRowVisible>[0]> = {}) => ({
    checked: false, amount: 0, amountsAvailable: true, showAll: false, ...over,
  })

  it('schová jen řádek, o kterém s jistotou víme, že na něm nic není', () => {
    expect(isTurnoverRowVisible(row({ amount: 0 }))).toBe(false)
    expect(isTurnoverRowVisible(row({ amount: 12_000 }))).toBe(true)
    expect(isTurnoverRowVisible(row({ amount: -4_500 }))).toBe(true)
  })

  it('neznámý obrat není nula — bez čísel se neschová nic', () => {
    expect(isTurnoverRowVisible(row({ amount: 0, amountsAvailable: false }))).toBe(true)
    expect(isTurnoverRowVisible(row({ amount: null }))).toBe(true)
  })

  it('zvolený řádek zůstává vidět, i když na něm obrat není', () => {
    // Schovaný zaškrtnutý řádek by nešlo odškrtnout a dál by mlčky zvyšoval obrat.
    expect(isTurnoverRowVisible(row({ amount: 0, checked: true }))).toBe(true)
  })

  it('přepínač „zobrazit i řádky bez obratu" ukáže všechno', () => {
    expect(isTurnoverRowVisible(row({ amount: 0, showAll: true }))).toBe(true)
  })
})
