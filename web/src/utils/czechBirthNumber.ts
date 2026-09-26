/**
 * Datum narození a pohlaví z českého rodného čísla.
 *
 * Zrcadlo serverového `CzechBirthNumber::birthDate()` / `::sex()`: server si
 * obojí při založení odvodí sám, formulář ale pole „Datum narození" nechával
 * prázdné, takže účetní ho psala podruhé (nebo si myslela, že chybí).
 *
 * Pravidla:
 * - devítimístné RČ (do roku 1953) nese vždy rok 19xx;
 * - desetimístné: rok < 54 → 20xx, jinak 19xx;
 * - měsíc +50 = žena, +20 (muž) a +70 (žena) jsou rozšířené řady od 2004,
 *   když v jednom dni došla pořadová čísla.
 *
 * Neplatné nebo neúplné číslo vrací `null` — formulář pak nic nepředvyplní
 * a o platnosti rozhodne server při uložení.
 */
export interface CzechBirthNumberFacts {
  birthDate: string
  sex: 'male' | 'female'
}

export function czechBirthNumberFacts(value: string): CzechBirthNumberFacts | null {
  const digits = value.replace(/\D/g, '')
  if (digits.length !== 9 && digits.length !== 10) return null

  const yearPart = Number(digits.slice(0, 2))
  let month = Number(digits.slice(2, 4))
  const day = Number(digits.slice(4, 6))

  let sex: 'male' | 'female' = 'male'
  if (month > 70) {
    month -= 70
    sex = 'female'
  } else if (month > 50) {
    month -= 50
    sex = 'female'
  } else if (month > 20) {
    month -= 20
  }

  const year = digits.length === 9
    ? 1900 + yearPart
    : (yearPart < 54 ? 2000 : 1900) + yearPart

  if (month < 1 || month > 12 || day < 1) return null
  const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate()
  if (day > daysInMonth) return null

  const birthDate = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`

  return { birthDate, sex }
}
