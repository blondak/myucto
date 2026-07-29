import { ref, computed, onMounted } from 'vue'
import type { SavedFilter } from '@/api/preferences'
import {
  ensurePrefsLoaded, filtersFor,
  createSavedFilter, updateSavedFilter, deleteSavedFilter,
} from '@/composables/useUserPrefs'
import { useToast } from '@/composables/useToast'
import { i18n } from '@/i18n'

export interface SavedFiltersOpts {
  getQuery:   () => Record<string, string>          // = buildQuery() stránky (§5.6a)
  applyQuery: (q: Record<string, string>) => void   // = applyQueryToPage(q) stránky (§5.6a)
}

// Mapování BE chybových kódů na hlášky (§6).
const ERROR_KEYS: Record<string, string> = {
  filter_name_exists:   'common.errors.filter_name_exists',
  filter_limit_reached: 'common.errors.filter_limit_reached',
  payload_too_large:    'common.errors.payload_too_large',
}

// Deep-equal přes JSON.stringify se seřazenými klíči.
function stableStringify(q: Record<string, string>): string {
  const sorted: Record<string, string> = {}
  for (const k of Object.keys(q).sort()) sorted[k] = q[k]
  return JSON.stringify(sorted)
}

function toastError(e: unknown): void {
  const code = (e as { response?: { data?: { error?: { code?: string } } } })?.response?.data?.error?.code
  const key = code ? ERROR_KEYS[code] : undefined
  useToast().warning(i18n.global.t(key ?? 'common.save_failed'))
}

export function useSavedFilters(pageKey: string, opts: SavedFiltersOpts) {
  const filters = filtersFor(pageKey)
  const ready = ref(false)

  onMounted(async () => { await ensurePrefsLoaded(); ready.value = true })

  const activeId = computed<number | null>(() => {
    const cur = stableStringify(opts.getQuery())
    const match = filters.value.find(f => stableStringify(f.payload) === cur)
    return match ? match.id : null
  })

  async function saveCurrent(name: string, asDefault = false): Promise<SavedFilter> {
    try {
      return await createSavedFilter({ page_key: pageKey, name, payload: opts.getQuery(), is_default: asDefault })
    } catch (e) { toastError(e); throw e }
  }

  async function overwrite(id: number): Promise<void> {
    try { await updateSavedFilter(id, { payload: opts.getQuery() }) }
    catch (e) { toastError(e); throw e }
  }

  function apply(f: SavedFilter): void {
    opts.applyQuery({ ...f.payload })
  }

  async function rename(id: number, name: string): Promise<void> {
    try { await updateSavedFilter(id, { name }) }
    catch (e) { toastError(e); throw e }
  }

  async function setDefault(id: number, on: boolean): Promise<void> {
    try { await updateSavedFilter(id, { is_default: on }) }
    catch (e) { toastError(e); throw e }
  }

  async function remove(id: number): Promise<void> {
    try { await deleteSavedFilter(id) }
    catch (e) { toastError(e); throw e }
  }

  // ASYNC (R5): počkej na cache, pak aplikuj default (pokud existuje).
  async function applyDefaultIfAny(): Promise<boolean> {
    await ensurePrefsLoaded()
    const def = filters.value.find(f => f.is_default)
    if (!def) return false
    apply(def)
    return true
  }

  return {
    filters, activeId, ready,
    getQuery: opts.getQuery,
    saveCurrent, overwrite, apply, rename, setDefault, remove, applyDefaultIfAny,
  }
}

export type SavedFiltersCtrl = ReturnType<typeof useSavedFilters>
