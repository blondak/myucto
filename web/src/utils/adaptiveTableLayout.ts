export function adaptiveTableWidths(widths: number[], available: number, weights: number[] = []): number[] {
  if (widths.length === 0 || available <= 0) return []
  const natural = widths.map(width => Math.min(width, available))
  const rows = Math.max(1, Math.ceil(natural.reduce((sum, width) => sum + width, 0) / available))
  const result: number[] = []
  let remaining = natural.length
  for (let row = 0; row < rows; row++) {
    const count = Math.ceil(remaining / (rows - row))
    const offset = result.length
    const rowWeights = Array.from({ length: count }, (_, index) => Math.max(1, weights[offset + index] ?? 1))
    const totalWeight = rowWeights.reduce((sum, weight) => sum + weight, 0)
    for (const weight of rowWeights) result.push(Math.max(0, (available - count * 0.1) * weight / totalWeight))
    remaining -= count
  }
  return result
}
