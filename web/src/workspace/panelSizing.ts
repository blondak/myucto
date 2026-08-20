export function resizePaneFractions(
  source: number[],
  index: number,
  deltaPixels: number,
  paneWidth: number,
  minimumPixels = 288,
): number[] {
  if (paneWidth <= 0 || !source[index] || !source[index + 1]) return source
  const pairTotal = source[index] + source[index + 1]
  const minimum = Math.min(minimumPixels / paneWidth, pairTotal / 2)
  const left = Math.max(minimum, Math.min(pairTotal - minimum, source[index] + deltaPixels / paneWidth))
  const next = [...source]
  next[index] = left
  next[index + 1] = pairTotal - left
  return next
}
