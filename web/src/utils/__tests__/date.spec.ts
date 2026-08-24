import { describe, expect, it } from 'vitest'
import { addDaysIso, addMonthsIso, localIsoDate } from '../date'

describe('localIsoDate', () => {
  it('vrací lokální kalendářní datum, ne UTC', () => {
    // Půlnoc + 40 minut místního času. `toISOString()` by v Praze vrátil předchozí den.
    const justAfterMidnight = new Date(2026, 3, 1, 0, 40, 0)
    expect(localIsoDate(justAfterMidnight)).toBe('2026-04-01')
  })

  it('doplňuje nuly', () => {
    expect(localIsoDate(new Date(2026, 0, 5))).toBe('2026-01-05')
  })
})

describe('addMonthsIso', () => {
  it('drží stejný den v měsíci', () => {
    expect(addMonthsIso('2026-03-01', 1)).toBe('2026-04-01')
  })

  it('u kratšího měsíce vrací jeho poslední den', () => {
    expect(addMonthsIso('2026-01-31', 1)).toBe('2026-02-28')
    expect(addMonthsIso('2028-01-31', 1)).toBe('2028-02-29')
  })

  it('přetéká přes konec roku', () => {
    expect(addMonthsIso('2026-11-15', 3)).toBe('2027-02-15')
  })

  /**
   * Regrese platebního kalendáře: splátky se generovaly opakovaným voláním nad
   * vlastním výstupem, takže posun o den se sčítal (1. 3. → 31. 3. → 29. 4. → …).
   * Datum splátky je podle § 31 ZDPH DUZP, takže to přesouvalo splátky do jiného
   * zdaňovacího období.
   */
  it('iterativní volání neposouvá datum', () => {
    let date = '2026-03-01'
    const dates: string[] = []
    for (let i = 0; i < 12; i++) {
      dates.push(date)
      date = addMonthsIso(date, 1)
    }
    expect(dates).toEqual([
      '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01',
      '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01',
      '2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01',
    ])
  })
})

describe('addDaysIso', () => {
  it('počítá kalendářně přes konec měsíce', () => {
    expect(addDaysIso('2026-01-30', 3)).toBe('2026-02-02')
  })

  /** Přechod na letní čas (29. 3. 2026) nesmí datum přetéct na sousední den. */
  it('přežije přechod letního času', () => {
    expect(addDaysIso('2026-03-28', 2)).toBe('2026-03-30')
    expect(addDaysIso('2026-10-24', 2)).toBe('2026-10-26')
  })

  it('umí odečítat', () => {
    expect(addDaysIso('2026-03-01', -1)).toBe('2026-02-28')
  })
})
