import { afterEach, describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import { formatCompactNumber, formatNumber, formatPercent } from '@/composables/useFormat'

afterEach(() => {
  i18n.global.locale.value = 'cs'
})

describe('locale-aware number formatting', () => {
  it('uses Czech decimal commas for decimal numbers and percentages', () => {
    i18n.global.locale.value = 'cs'

    expect(formatNumber(16.4, { maximumFractionDigits: 1 })).toBe('16,4')
    expect(formatPercent(42.5, 1)).toBe('42,5 %')
  })

  it('uses locale-aware compact chart labels', () => {
    i18n.global.locale.value = 'cs'
    expect(formatCompactNumber(8_000_000)).toMatch(/^8,0\s*mil\.$/)

    i18n.global.locale.value = 'en'
    expect(formatCompactNumber(8_000_000)).toBe('8.0M')
  })
})
