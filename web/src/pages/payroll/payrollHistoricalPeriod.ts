/**
 * Období, které vedl předchozí program.
 *
 * Převod mezd naimportuje docházku i mzdové vstupy i za měsíce PŘED prvním
 * mzdovým obdobím firmy (`payroll_module_state.start_period`), tedy za období,
 * které MyÚčto vůbec nepočítá - mzdový běh za ně nejde ani založit. Takové
 * záznamy se OZNAČÍ, nikdy neschovají: jsou podkladem pro srovnávací sestavu
 * a pro počáteční stavy kumulací, jen nejsou rozdělaná práce.
 *
 * Pravidlo drží server (`PayrollHistoricalPeriodService`) a posílá ho v každé
 * odpovědi jako `historical`. Tenhle soubor je pro výpisy, které dostanou jen
 * hranici a měsíce si značí samy - typicky historie docházky, kde jedna
 * odpověď nese víc měsíců naráz.
 */

/**
 * Předchází měsíc prvnímu mzdovému období firmy?
 *
 * Porovnání je ostré: `startPeriod` je PRVNÍ počítaný měsíc, ne poslední
 * historický. Bez nastaveného začátku není podle čeho historii poznat, takže
 * se neoznačuje nic.
 */
export function isHistoricalPayrollPeriod(
  period: string | null | undefined,
  startPeriod: string | null | undefined,
): boolean {
  if (!period || !startPeriod) return false

  return period.slice(0, 7) < startPeriod.slice(0, 7)
}
