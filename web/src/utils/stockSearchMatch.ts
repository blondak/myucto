import type { StockSearchMatch } from '@/api/stock'

/** Popisek shody hledání: „Sériové číslo“, „Šarže“ nebo název parametru (např. VIN). */
export function stockSearchMatchLabel(match: StockSearchMatch, t: (key: string) => string): string {
  if (match.kind === 'attribute') return match.attribute || t('stock.search_match.attribute')
  return t(`stock.search_match.${match.kind}`)
}
