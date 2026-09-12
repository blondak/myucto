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
