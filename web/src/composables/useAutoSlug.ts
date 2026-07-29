import { ref } from 'vue'
import { slugify } from '@/api/slug'

/**
 * Auto-předvyplnění pole „Kód" slugem z „Název/Label" (serverový Slugifier).
 * Sdílená linkage logika pro CodeNameFields.vue i ručně stavěné číselníkové
 * formuláře (admin/codebooks). Jakmile uživatel do kódu sáhne, auto-generování
 * se vypne; když kód smaže, zase zapne.
 *
 * @param setCode  callback, který zapíše vygenerovaný kód do modelu
 * @param opts.maxLen  ořez slugu na délku pole (kód bývá kratší než 50)
 */
export function useAutoSlug(setCode: (value: string) => void, opts: { maxLen?: number } = {}) {
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
