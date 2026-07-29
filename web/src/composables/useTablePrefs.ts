import { ref, computed, onMounted } from 'vue'
import type { SortPref } from '@/api/preferences'
import { ensurePrefsLoaded, getPagePrefs, patchPagePrefs } from '@/composables/useUserPrefs'

export interface ColumnDef {
  key: string               // stabilní id — jde do user_preferences.payload.hidden
  labelKey: string          // i18n klíč (reuse existujících klíčů hlaviček)
  required?: boolean        // nelze skrýt (číslo dokladu, částka, akce)
  sortable?: boolean        // jen stránky se zapnutým sortem (§5.6)
  defaultHidden?: boolean   // doplňkový sloupec — dokud uživatel nesáhne na výběr, je skrytý
}

export function useTablePrefs(pageKey: string, columns: ColumnDef[]) {
  const prefs = getPagePrefs(pageKey)
  const ready = ref(false)

  onMounted(async () => { await ensurePrefsLoaded(); ready.value = true })

  // Doplňkové sloupce (defaultHidden) jsou skryté, dokud je uživatel EXPLICITNĚ neodkryje —
  // odkrytí se eviduje v prefs.shown. Efektivně skryté = (hidden ?? []) ∪ (defaultHidden − shown).
  // Díky tomu se nové doplňkové sloupce nikdy samy nezobrazí ani uživatelům s dříve uloženým
  // hidden polem (to nové klíče neobsahuje). resetColumns (hidden+shown: null) = default stránky.
  const defaultHiddenKeys = columns.filter(c => c.defaultHidden && !c.required).map(c => c.key)
  const hidden = computed<string[]>(() => {
    const shown = prefs.value.shown ?? []
    const set = new Set(prefs.value.hidden ?? [])
    for (const k of defaultHiddenKeys) {
      if (!shown.includes(k)) set.add(k)
    }
    return [...set]
  })

  // R9: isVisible(key) = required || !hidden.includes(key). Neznámý (nový) sloupec = viditelný,
  // pokud není defaultHidden.
  function isVisible(key: string): boolean {
    const col = columns.find(c => c.key === key)
    if (col?.required) return true
    return !hidden.value.includes(key)
  }

  function visibleNonRequiredCount(): number {
    return columns.filter(c => !c.required && isVisible(c.key)).length
  }

  function toggleColumn(key: string): void {
    const col = columns.find(c => c.key === key)
    if (!col || col.required) return              // required = no-op
    const hiddenSet = new Set(hidden.value)
    const shownSet = new Set(prefs.value.shown ?? [])
    if (hiddenSet.has(key)) {
      hiddenSet.delete(key)
      if (defaultHiddenKeys.includes(key)) shownSet.add(key)
    } else {
      if (visibleNonRequiredCount() <= 1) return  // nedovol skrýt poslední viditelný ne-required
      hiddenSet.add(key)
      shownSet.delete(key)
    }
    patchPagePrefs(pageKey, { hidden: [...hiddenSet], shown: [...shownSet] })
  }

  function resetColumns(): void {
    patchPagePrefs(pageKey, { hidden: null, shown: null })
  }

  const density = computed<'comfortable' | 'compact'>(() => prefs.value.density ?? 'comfortable')
  function setDensity(d: 'comfortable' | 'compact'): void {
    patchPagePrefs(pageKey, { density: d })
  }
  const densityClass = computed(() => (density.value === 'compact' ? 'tbl-compact' : ''))

  const sort = computed<SortPref | null>(() => prefs.value.sort ?? null)
  // Cyklus asc → desc → výchozí; persistuje do prefs.
  function toggleSort(key: string): void {
    const cur = sort.value
    let next: SortPref | null
    if (!cur || cur.key !== key) next = { key, dir: 'asc' }
    else if (cur.dir === 'asc') next = { key, dir: 'desc' }
    else next = null
    patchPagePrefs(pageKey, { sort: next })
  }

  return {
    columns,
    isVisible, toggleColumn, resetColumns,
    density, setDensity, densityClass,
    sort, toggleSort,
    ready,
  }
}

export type TablePrefsCtrl = ReturnType<typeof useTablePrefs>
