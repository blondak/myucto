/**
 * Odvození strojového „Kódu" z lidského „Názvu" — čistě klientsky, synchronně.
 *
 * Doplněk k {@link useAutoSlug}, který sahá na serverový `GET /api/slug`
 * (Slugifier v módu `'-' + lower`, vhodný pro URL slugy e-shopu). Číselníky
 * mezd naopak vyžadují VERZÁLKOVÝ identifikátor s podtržítkem — např. mzdové
 * účtárny mají serverovou validaci `^[A-Z0-9][A-Z0-9_-]{0,31}$`, kterou by
 * lowercase slug neprošel. Zrcadlí tedy PHP `Slugifier::slug($s, '_', 'upper')`.
 *
 * Synchronní záměrně: kód se přegeneruje při každém úhozu bez debounce,
 * bez round-tripu a funguje i offline.
 */

/** Maximální délka kódu mzdové účtárny (`payroll_offices.code` VARCHAR(32)). */
export const OFFICE_CODE_MAX_LENGTH = 32

/**
 * Text → identifikátor `[A-Z0-9_]`: odstraní diakritiku přes NFD normalizaci,
 * nepovolené znaky sloučí do `_`, ořízne podtržítka z krajů, převede na
 * verzálky a zkrátí na `maxLen`.
 *
 * Prázdný nebo čistě neabecední vstup vrací prázdný řetězec — volající se pak
 * rozhodne, zda nechat pole prázdné, nebo doplnit fallback.
 */
export function slugifyCode(input: string, maxLen = OFFICE_CODE_MAX_LENGTH): string {
  const code = input
    .normalize('NFD')
    // U+0300–U+036F = kombinující diakritická znaménka (č → c + háček → c)
    .replace(/[̀-ͯ]/g, '')
    // Písmena bez NFD rozkladu — parita s PHP Slugifier::transliterate.
    // (ß rozvine na SS už samo toUpperCase, mapovat ho netřeba.)
    .replace(/[łŁ]/g, 'L')
    .replace(/[đĐ]/g, 'D')
    .replace(/[øØ]/g, 'O')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
  return maxLen > 0 ? code.slice(0, maxLen) : code
}

/**
 * Zajistí unikátnost kódu proti již obsazeným hodnotám — při kolizi připojí
 * `_2`, `_3`, … Suffix se vejde do `maxLen` na úkor konce základu, aby výsledek
 * nikdy nepřetekl délku sloupce a nespadl až na serverové unikátní validaci.
 *
 * @param taken obsazené kódy (porovnává se case-insensitive, jako v DB)
 */
export function uniqueCode(
  base: string,
  taken: Iterable<string>,
  maxLen = OFFICE_CODE_MAX_LENGTH,
): string {
  if (base === '') return ''
  const used = new Set<string>()
  for (const value of taken) {
    const normalized = value.trim().toUpperCase()
    if (normalized !== '') used.add(normalized)
  }
  if (!used.has(base)) return base
  for (let suffix = 2; ; suffix++) {
    const tail = `_${suffix}`
    // maxLen <= 0 = bez limitu, základ se nezkracuje
    const head = maxLen > 0
      ? base.slice(0, Math.max(1, maxLen - tail.length)).replace(/_+$/, '')
      : base
    const candidate = `${head}${tail}`
    if (!used.has(candidate)) return candidate
  }
}

/**
 * Kód odvozený z názvu rovnou zbavený kolizí — to, co formulář zapisuje do
 * pole „Kód", dokud ho uživatel needitoval ručně.
 */
export function codeFromName(
  name: string,
  taken: Iterable<string> = [],
  maxLen = OFFICE_CODE_MAX_LENGTH,
): string {
  return uniqueCode(slugifyCode(name, maxLen), taken, maxLen)
}
