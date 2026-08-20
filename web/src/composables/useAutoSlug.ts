import { ref } from 'vue'
import { slugify } from '@/api/slug'
import { codeFromName } from '@/utils/slugifyCode'

/**
 * Auto-předvyplnění pole „Kód" slugem z „Název/Label".
 * Sdílená linkage logika pro CodeNameFields.vue i ručně stavěné číselníkové
 * formuláře (admin/codebooks, mzdy). Jakmile uživatel do kódu sáhne,
 * auto-generování se vypne; když kód smaže, zase zapne.
 *
 * Dva režimy podle toho, jaký tvar identifikátoru dané pole vyžaduje:
 *  - `'slug'` (výchozí) — serverový Slugifier (`GET /api/slug`), lowercase
 *    s pomlčkou; používají e-shopové číselníky, kde je kód zároveň URL slug.
 *    Debounced, protože jde o síťový round-trip.
 *  - `'code'` — klientský {@link codeFromName}, VERZÁLKY s podtržítkem;
 *    číselníky mezd mají serverové validace typu `^[A-Z0-9][A-Z0-9_-]{0,31}$`,
 *    kterými by lowercase slug neprošel. Synchronní, bez round-tripu.
 *
 * @param setCode  callback, který zapíše vygenerovaný kód do modelu
 * @param opts.maxLen  ořez slugu na délku pole (kód bývá kratší než 50)
 * @param opts.mode    tvar generovaného identifikátoru (viz výše)
 * @param opts.taken   getter obsazených kódů; při kolizi se přidá `_2`, `_3`
 *                     (jen v režimu `'code'`), ať to nespadne až na serverové
 *                     unikátní validaci
 */
export function useAutoSlug(
  setCode: (value: string) => void,
  opts: {
    maxLen?: number
    mode?: 'slug' | 'code'
    taken?: () => Iterable<string>
  } = {},
) {
  const manual = ref(false)
  let timer: ReturnType<typeof setTimeout> | null = null
  let seq = 0

  /** Nastav výchozí stav při otevření formuláře (edit / existující kód = ruční). */
  function init(codeValue: string, editing = false): void {
    manual.value = editing || codeValue.trim() !== ''
  }

  /** Uživatel psal do kódu → přepni na ruční (prázdný kód = zas auto). */
  function markManual(codeValue: string): void {
    manual.value = codeValue.trim() !== ''
  }

  /** Uživatel psal do názvu → debounced slug do kódu (jen když není ruční). */
  function fromName(nameValue: string): void {
    if (manual.value) return
    if (timer) clearTimeout(timer)
    const s = ++seq
    if (nameValue.trim() === '') { setCode(''); return }
    if (opts.mode === 'code') {
      setCode(codeFromName(nameValue, opts.taken?.() ?? [], opts.maxLen ?? 0))
      return
    }
    timer = setTimeout(async () => {
      try {
        let slug = await slugify(nameValue)
        if (opts.maxLen && slug.length > opts.maxLen) slug = slug.slice(0, opts.maxLen)
        // Ignoruj zastaralou odpověď i případ, že uživatel mezitím sáhl do kódu.
        if (s === seq && !manual.value) setCode(slug)
      } catch { /* offline / chyba → uživatel doplní kód ručně */ }
    }, 300)
  }

  return { init, markManual, fromName }
}
