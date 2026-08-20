import { payrollApi } from '@/api/payroll'

/**
 * Výchozí zdravotní pojišťovna zaměstnavatele pro předvyplnění formulářů.
 *
 * Načte se NEJVÝŠ JEDNOU za běh aplikace, ne při každém otevření karty osoby —
 * karet je při tisícovce zaměstnanců tisíc a nastavení zaměstnavatele je jedno.
 * Stejný vzor jako `useCountries`.
 *
 * Selhání se schválně polyká: nastavení zaměstnavatele je za právem
 * `payroll.settings`, které mzdová účetní s právem jen na kartu osoby mít
 * nemusí. Chybějící předvyplnění je nepříjemnost, zablokovaná karta chyba —
 * proto se polyká i SYNCHRONNÍ pád, ne jen odmítnutý příslib.
 */
let cached: string | null | undefined
let pending: Promise<string | null> | null = null

export function loadDefaultHealthInsurerCode(): Promise<string | null> {
  if (cached !== undefined) return Promise.resolve(cached)
  if (pending === null) {
    try {
      pending = payrollApi.employerSettings()
        .then((settings) => {
          cached = settings.default_health_insurer_code
          return cached
        })
        .catch(() => {
          cached = null
          return null
        })
        .finally(() => {
          pending = null
        })
    } catch {
      cached = null
      return Promise.resolve(cached)
    }
  }

  return pending
}

/** Jen pro testy — vyprázdní paměť mezi případy. */
export function resetDefaultHealthInsurerCode(): void {
  cached = undefined
  pending = null
}
