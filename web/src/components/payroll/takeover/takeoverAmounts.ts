/**
 * Převod částek a dob ručního zadání převzatých mezd mezi tím, co píše
 * účetní (koruny s desetinnou čárkou, dny a hodiny desetinně), a tím, co
 * ukládá server (haléře, setiny dne, minuty).
 *
 * Přes řetězce, ne přes násobení floatem: 0,07 × 60 dá v plovoucí řádové čárce
 * 4,199999… a zaokrouhlení by ztratilo minutu.
 */

/** Celé kladné číslo se dvěma desetinnými místy → celé setiny; `null` = neplatné. */
function hundredths(value: string): number | null {
  const normalized = value.replace(/[\s  ]/g, '').replace(',', '.')
  if (normalized === '') return 0
  const match = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec(normalized)
  if (match === null) return null
  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
}

/** „12 345,50" → 1 234 550 haléřů; prázdné = 0; neplatné = `null`. */
export function crownsToMinor(value: string): number | null {
  return hundredths(value)
}

/** Haléře → text pro pole; nula jako prázdné pole, ať je vidět, co je vyplněné. */
export function minorToCrowns(minor: number): string {
  if (minor === 0) return ''
  const sign = minor < 0 ? '-' : ''
  const absolute = Math.abs(minor)
  const whole = Math.floor(absolute / 100)
  const fraction = absolute % 100
  return fraction === 0 ? `${sign}${whole}` : `${sign}${whole},${String(fraction).padStart(2, '0')}`
}

/** „21,5" dne → 2150 setin. */
export function daysToHundredths(value: string): number | null {
  return hundredths(value)
}

export function hundredthsToDays(value: number): string {
  return minorToCrowns(value)
}

/** „168,25" hodin → 10 095 minut. */
export function hoursToMinutes(value: string): number | null {
  const parsed = hundredths(value)
  if (parsed === null) return null
  return Math.floor((parsed * 60) / 100)
}

export function minutesToHours(minutes: number): string {
  if (minutes === 0) return ''
  return minorToCrowns(Math.round((minutes * 100) / 60))
}

/** Celý počet dnů 0–31; prázdné = 0. */
export function wholeDays(value: string): number | null {
  const normalized = value.trim()
  if (normalized === '') return 0
  if (!/^\d{1,2}$/.test(normalized)) return null
  const days = Number(normalized)
  return days <= 31 ? days : null
}
