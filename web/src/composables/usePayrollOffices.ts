import { payrollApi, type PayrollOffice } from '@/api/payroll'

/**
 * Mzdové účtárny zaměstnavatele pro výběr na kartě pracovního vztahu.
 *
 * Načte se NEJVÝŠ JEDNOU za běh aplikace, ne při každém otevření karty —
 * účtáren je hrstka a nastavení zaměstnavatele je jedno. Stejný vzor jako
 * `usePayrollDefaultInsurer`.
 *
 * Selhání se schválně polyká: nastavení zaměstnavatele je za právem
 * `payroll.settings`, které mzdová účetní s právem jen na kartu osoby mít
 * nemusí. Prázdná nabídka je nepříjemnost, zablokovaná karta chyba — proto
 * i synchronní pád (např. chybějící metoda v testovací atrapě) končí prázdným
 * seznamem, ne výjimkou.
 */
let cached: PayrollOffice[] | undefined
let pending: Promise<PayrollOffice[]> | null = null

export function loadPayrollOffices(): Promise<PayrollOffice[]> {
  if (cached !== undefined) return Promise.resolve(cached)
  if (pending === null) {
    try {
      pending = payrollApi.employerSettings()
        .then((settings) => {
          cached = (settings.offices ?? []).filter(office => office.is_active)
          return cached
        })
        .catch(() => {
          cached = []
          return cached
        })
        .finally(() => {
          pending = null
        })
    } catch {
      cached = []
      return Promise.resolve(cached)
    }
  }

  return pending
}

/** Jen pro testy — vyprázdní paměť mezi případy. */
export function resetPayrollOffices(): void {
  cached = undefined
  pending = null
}
