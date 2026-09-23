/** Částka zůstává v úložišti kladná; červené storno snižuje původní stranu. */
export function journalAmount(line: { amount: number | string | null; is_red_storno?: boolean | number }): number {
  return Number(line.amount || 0) * (line.is_red_storno ? -1 : 1)
}

export function journalForeignAmount(line: { amount_foreign: number | null; is_red_storno?: boolean | number }): number | null {
  return line.amount_foreign == null ? null : journalAmount({ amount: line.amount_foreign, is_red_storno: line.is_red_storno })
}
