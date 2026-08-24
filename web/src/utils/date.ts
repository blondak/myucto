/**
 * Kalendářní datum v LOKÁLNÍ zóně uživatele.
 *
 * Proč to existuje: `toISOString()` renderuje v UTC. V Praze (UTC+1/+2) je
 * lokální půlnoc v UTC ještě předchozí den, takže cokoli zadaného mezi 00:00
 * a 02:00 dostalo datum o den dřív. U účetních dokladů to není kosmetika —
 * DUZP, datum vystavení a datum úhrady rozhodují o zdaňovacím období, takže
 * doklad spadl do jiného přiznání k DPH.
 *
 * Datum se proto skládá z lokálních složek a `Date` se použije jen jako
 * kalendář, ne jako okamžik.
 */
export function localIsoDate(date: Date = new Date()): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/**
 * `YYYY-MM-DD` + N dnů, čistě kalendářně.
 *
 * Nepočítá se přes `toISOString()`: přechod na letní/zimní čas uvnitř intervalu
 * posune výsledek o hodinu a datum pak přeteče přes půlnoc na sousední den.
 */
export function addDaysIso(date: string, days: number): string {
  const [year, month, day] = date.split('-').map(Number)
  return localIsoDate(new Date(year, month - 1, day + days))
}

/**
 * `YYYY-MM-DD` + N měsíců se zachováním dne; když cílový měsíc takový den nemá
 * (31. 1. + 1 měsíc → „31. 2."), vrátí jeho poslední den (28./29. 2.).
 */
export function addMonthsIso(date: string, months: number): string {
  const [year, month, day] = date.split('-').map(Number)
  const targetMonth = month - 1 + months
  const lastDay = new Date(year, targetMonth + 1, 0).getDate()
  return localIsoDate(new Date(year, targetMonth, Math.min(day, lastDay)))
}
