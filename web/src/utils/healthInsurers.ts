/**
 * Číselník českých zdravotních pojišťoven pro frontend.
 *
 * Zrcadlí backendový `MyInvoice\Service\Codebook\HealthInsurers`. Do teď žil
 * zadrátovaný přímo v `EmployerSettings.vue`, takže druhé místo, které kód
 * pojišťovny sbírá (`HealthInsurerAccounts.vue`), o něm nevědělo a nechávalo
 * uživatele psát „111" i „VZP" rukou. Překlep v kódu instituce znamená špatně
 * adresovanou platbu pojistného, proto číselník žije v `utils/` — mimo obě
 * stránky, aby na něm mohly viset obě a nemohly se rozejít.
 *
 * Zdroj pravdy zůstává backend; tenhle soubor je jeho kopie pro UI. Když se
 * seznam pojišťoven změní, mění se obě strany naráz — a že se to stalo, hlídá
 * `api/tests/Architecture/HealthInsurerCodebookContractTest.php`. Porovnává
 * kódy, názvy i pořadí, takže tuhle kopii nejde změnit jednostranně.
 */

export interface HealthInsurer {
  /** Trojmístný kód pojišťovny (VZP = 111). */
  code: string
  name: string
}

export const HEALTH_INSURERS: readonly HealthInsurer[] = [
  { code: '111', name: 'Všeobecná zdravotní pojišťovna ČR (VZP)' },
  { code: '201', name: 'Vojenská zdravotní pojišťovna ČR (VoZP)' },
  { code: '205', name: 'Česká průmyslová zdravotní pojišťovna (ČPZP)' },
  { code: '207', name: 'Oborová zdravotní pojišťovna (OZP)' },
  { code: '209', name: 'Zaměstnanecká pojišťovna Škoda (ZPŠ)' },
  { code: '211', name: 'Zdravotní pojišťovna ministerstva vnitra ČR (ZPMV)' },
  { code: '213', name: 'Revírní bratrská pokladna (RBP)' },
]

export function isHealthInsurerCode(code: string | null | undefined): boolean {
  const normalized = code?.trim() ?? ''
  return normalized !== '' && HEALTH_INSURERS.some(insurer => insurer.code === normalized)
}

export function healthInsurerName(code: string | null | undefined): string | null {
  const normalized = code?.trim() ?? ''
  return HEALTH_INSURERS.find(insurer => insurer.code === normalized)?.name ?? null
}

/** Nabídka pro `SearchableSelect` — hodnotou je kód, štítek nese kód i název. */
export function healthInsurerOptions(): Array<{ value: string; label: string }> {
  return HEALTH_INSURERS.map(insurer => ({
    value: insurer.code,
    label: `${insurer.code} — ${insurer.name}`,
  }))
}
