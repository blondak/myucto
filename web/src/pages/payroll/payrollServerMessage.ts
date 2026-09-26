import { useI18n } from 'vue-i18n'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'

/**
 * Věty ze serveru v jazyce uživatele.
 *
 * Mzdová podání (NEMPRI, ZP, registrace, JMHZ) vrací důvody česky a spolu
 * s nimi strojový kód. V češtině je věta serveru nejpřesnější (jmenuje
 * dotčené a krok); v jiném jazyce by ale účetní dostala českou větu, proto se
 * tam sáhne po překladu kódu z `payroll.server_codes` nebo
 * `payroll.jmhz_gate.codes`. Kód bez překladu nechá větu serveru, lepší
 * česká věta než žádná.
 */
export function payrollCodeKey(te: (key: string) => boolean, code: string | null | undefined): string | null {
  if (!code) return null
  for (const key of [`payroll.server_codes.${code}`, `payroll.jmhz_gate.codes.${code}`]) {
    if (te(key)) return key
  }
  return null
}

export function usePayrollServerMessage() {
  const i18n = useI18n()
  const t = i18n.t as (key: string, parameters?: Record<string, unknown>) => string
  const te = typeof i18n.te === 'function' ? (key: string) => i18n.te(key) : () => false
  const currentLocale = () => {
    const value = (i18n.locale as { value?: string } | undefined)?.value
    return typeof value === 'string' ? value : 'cs'
  }

  /**
   * Chyba odpovědi API: český text serveru, v jiném jazyce překlad kódu.
   * Podpis stejný jako `apiErrorMessage`, `fallback` je už přeložená věta.
   */
  function errorMessage(error: unknown, fallback: string): string {
    const key = currentLocale() !== 'cs' ? payrollCodeKey(te, apiErrorCode(error)) : null
    return key !== null ? t(key) : apiErrorMessage(error, fallback)
  }

  /** Důvod, který server posílá v datech i s kódem (`reason_code` + `reason`). */
  function reasonText(code: string | null | undefined, reason: string | null | undefined): string {
    const key = currentLocale() !== 'cs' || !reason ? payrollCodeKey(te, code) : null
    if (key !== null) return t(key)
    return reason ?? ''
  }

  return { errorMessage, reasonText }
}
