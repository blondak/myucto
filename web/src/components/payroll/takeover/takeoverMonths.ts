/**
 * Převzatá část roku přechodu — formátování měsíců pro obrazovky, které
 * ukazují, u koho převzaté úhrny chybí nebo si odporují.
 */
export type {
  TakeoverCheck,
  TakeoverEstimatedStart,
  TakeoverGap,
  TakeoverLayerDifference,
  TakeoverLayerMetric,
  TakeoverLayerOneSided,
} from '@/api/payrollTakeover'

/** „1–3, 5" z [1, 2, 3, 5]. */
export function monthRanges(months: number[]): string {
  const sorted = [...new Set(months)].sort((left, right) => left - right)
  const ranges: string[] = []
  let start: number | null = null
  let previous: number | null = null
  for (const month of sorted) {
    if (previous !== null && month === previous + 1) {
      previous = month
      continue
    }
    if (start !== null && previous !== null) {
      ranges.push(start === previous ? String(start) : `${start}–${previous}`)
    }
    start = month
    previous = month
  }
  if (start !== null && previous !== null) {
    ranges.push(start === previous ? String(start) : `${start}–${previous}`)
  }
  return ranges.join(', ')
}

/** „2026-01" → čísla měsíců. */
export function periodMonths(periods: string[]): number[] {
  return periods.map(period => Number(period.slice(5, 7))).filter(month => month >= 1 && month <= 12)
}
