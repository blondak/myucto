/**
 * Výběr řádků VZZ, které firma počítá do čistého obratu nad výchozí I. + II.
 * (§ 35 vyhl. 500/2002 Sb.). Rodič (IV.) už obsahuje své podřádky (IV.1., IV.2.),
 * zaškrtnutí rodiče proto podřádky z výběru odebere a dokud je rodič vybraný, jsou
 * zahrnuté v něm. Stejné pravidlo drží backend (FinancialStatementService).
 */
export function isCoveredByParent(code: string, selected: readonly string[]): boolean {
  return selected.some(parent => parent !== code && code.startsWith(parent))
}

export function toggleTurnoverRow(selected: readonly string[], code: string, on: boolean): string[] {
  if (!on) {
    return selected.filter(c => c !== code)
  }
  const withoutChildren = selected.filter(c => c === code || !c.startsWith(code))
  return withoutChildren.includes(code) ? withoutChildren : [...withoutChildren, code]
}

/**
 * Má se řádek nabídnout k rozhodnutí? Seznam devatenácti zaškrtávátek je bez
 * vodítka nerozhodnutelný, takže se řádky bez obratu schovávají.
 *
 * Dvě situace, kdy se NESCHOVÁVÁ nikdy:
 *  - **obrat neznáme** (`amountsAvailable === false`, nebo řádek v odpovědi
 *    chybí) — „nevíme" není „nula" a schovat řádek kvůli neznalosti by tiše
 *    ubralo z rozhodnutí, které dělá účetní jednotka;
 *  - **řádek je zvolený** — schovaný zaškrtnutý řádek by nešlo odškrtnout
 *    a mlčky by dál zvyšoval čistý obrat.
 */
export function isTurnoverRowVisible(row: {
  checked: boolean
  amount: number | null
  amountsAvailable: boolean
  showAll: boolean
}): boolean {
  if (row.showAll || !row.amountsAvailable || row.checked) return true
  return row.amount === null || row.amount !== 0
}
