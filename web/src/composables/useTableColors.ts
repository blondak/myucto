import { computed } from 'vue'
import { getPagePrefs, patchPagePrefs } from '@/composables/useUserPrefs'
import { normalizeCellColor, contrastingText } from '@/utils/tableColors'
import '@/styles/tableColors.css'

export function useTableColors(pageKey: string) {
  const prefs = getPagePrefs(pageKey)
  const colors = computed(() => prefs.value.column_colors ?? {})
  const hasColors = computed(() => Object.values(colors.value).some(color => normalizeCellColor(color)))

  function color(key: string): string | undefined {
    return normalizeCellColor(colors.value[key])
  }
  function setColor(key: string, value: string | null): void {
    const next = { ...colors.value }
    if (value === null) delete next[key]
    else {
      const normalized = normalizeCellColor(value)
      if (!normalized) return
      next[key] = normalized
    }
    patchPagePrefs(pageKey, { column_colors: Object.keys(next).length ? next : null })
  }
  function reset(): void {
    patchPagePrefs(pageKey, { column_colors: null })
  }
  function cellStyle(key: string): Record<string, string> | undefined {
    const background = color(key)
    return background ? { '--cell-bg': background, '--cell-fg': contrastingText(background) } : undefined
  }

  return { color, setColor, reset, cellStyle, hasColors }
}

export type TableColorsCtrl = ReturnType<typeof useTableColors>
