import { computed, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TablePrefs } from '@/api/preferences'
import { contrastingText, normalizeCellColor } from '@/utils/tableColors'

const store = vi.hoisted(() => ({ pages: {} as Record<string, TablePrefs> }))
const pages = ref<Record<string, TablePrefs>>({})
vi.mock('@/composables/useUserPrefs', () => ({
  getPagePrefs: (key: string) => computed(() => pages.value[key] ?? {}),
  patchPagePrefs: (key: string, patch: Partial<TablePrefs>) => {
    pages.value[key] = { ...pages.value[key], ...patch }
    store.pages[key] = pages.value[key]
  },
}))
import { useTableColors } from '@/composables/useTableColors'

describe('table colors', () => {
  beforeEach(() => { pages.value = {}; store.pages = {} })

  it('uses saved preferences, keeps pages separate and resets only colors', () => {
    pages.value.invoices = { density: 'compact', hidden: ['due'], column_colors: { amount: '#123456' } }
    const issued = useTableColors('invoices')
    const received = useTableColors('purchase_invoices')
    expect(issued.cellStyle('amount')).toEqual({ '--cell-bg': '#123456', '--cell-fg': '#ffffff' })
    expect(received.cellStyle('amount')).toBeUndefined()
    issued.setColor('due', '#AABBCC')
    expect(store.pages.invoices.column_colors).toEqual({ amount: '#123456', due: '#aabbcc' })
    issued.setColor('amount', null)
    expect(issued.color('amount')).toBeUndefined()
    expect(issued.hasColors.value).toBe(true)
    issued.reset()
    expect(store.pages.invoices).toEqual({ density: 'compact', hidden: ['due'], column_colors: null })
    expect(issued.hasColors.value).toBe(false)
  })

  it('ignores malformed saved or selected colors', () => {
    pages.value.journal = { column_colors: { date: 'url(https://invalid.test)', amount: '#fff' } }
    const colors = useTableColors('journal')
    expect(colors.cellStyle('date')).toBeUndefined()
    expect(colors.cellStyle('amount')).toBeUndefined()
    expect(colors.hasColors.value).toBe(false)
    colors.setColor('date', 'red')
    expect(store.pages.journal).toBeUndefined()
    expect(normalizeCellColor(null)).toBeUndefined()
    expect(normalizeCellColor('#123456;')).toBeUndefined()
  })

  it('provides at least 4.5:1 contrast across RGB values in either theme', () => {
    for (let r = 0; r < 256; r += 17) {
      for (let g = 0; g < 256; g += 17) {
        for (let b = 0; b < 256; b += 17) {
          const color = '#' + [r, g, b].map(c => c.toString(16).padStart(2, '0')).join('')
          const channels = [r, g, b].map(c => c / 255).map(c => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
          const luminance = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722
          const foreground = contrastingText(color)
          const ratio = foreground === '#000000' ? (luminance + 0.05) / 0.05 : 1.05 / (luminance + 0.05)
          expect(ratio, color).toBeGreaterThanOrEqual(4.5)
        }
      }
    }
  })
})
