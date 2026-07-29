// Fail-closed normalizace uživatelsky zadaných webových adres (SEC-10).
//
// Hodnota se renderuje do `href`, takže projde jen absolutní http(s) URL.
// Všechno ostatní (javascript:, data:, vbscript:, file:, relativní i
// protokolově-relativní odkazy) vrací null — volající pak vykreslí jen TEXT,
// nikdy odkaz.
//
// PHP zrcadlo je `api/src/Support/SafeUrl.php` — obě verze musí zůstat 1:1
// (stejný přístup jako u varsymbol.ts ↔ VarsymbolGenerator). Autoritou je
// backend, tohle je druhá obranná linie pro už uložená legacy data.

/** Délka sloupce, do kterého se URL ukládá (např. eshop_manufacturers.website). */
export const SAFE_URL_MAX_LENGTH = 255

/** Řídicí znaky + mezera; prohlížeč je z href stripuje, my kvůli tomu odmítáme celý vstup. */
const CONTROL_OR_SPACE = /[\u0000-\u0020\u007F]/

/**
 * Vrátí normalizovanou absolutní http(s) URL, nebo null když je vstup nepoužitelný.
 */
export function safeExternalUrl(raw: string | null | undefined): string | null {
  if (typeof raw !== 'string') return null
  let url = raw.trim()
  if (url === '') return null

  // Řídicí znaky a mezery kdekoliv uvnitř. Prohlížeče \t\n\r před navigací
  // zahazují, takže "java\tscript:alert(1)" by se v href stalo funkčním
  // "javascript:alert(1)". Odmítáme celý řetězec, nesanitizujeme ho.
  if (CONTROL_OR_SPACE.test(url)) return null

  // Zpětné lomítko prohlížeče normalizují na "/", čímž se dá obejít kontrola
  // autority ("https://evil.com\@duvery-hodna.cz").
  if (url.includes('\\')) return null

  // Relativní ("/foo") ani protokolově-relativní ("//evil.com") odkazy nechceme.
  if (url.startsWith('/')) return null

  // Když uživatel schéma nenapsal, doplníme https://. Když napsal jakékoliv jiné,
  // odmítáme — porovnání je case-insensitive, takže "JaVaScRiPt:" neprojde.
  if (/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(url)) {
    if (!/^https?:\/\//i.test(url)) return null
  } else {
    url = `https://${url}`
  }

  let parsed: URL
  try {
    parsed = new URL(url)
  } catch {
    return null
  }

  const scheme = parsed.protocol.toLowerCase()
  if (scheme !== 'http:' && scheme !== 'https:') return null

  // userinfo ("https://podvod.cz:x@evil.com") mate uživatele o cílové doméně.
  // Kontrolujeme přes URL i přímo v autoritě — ať nespoléháme na jedinou
  // implementaci parsování.
  if (parsed.username !== '' || parsed.password !== '') return null
  const authority = url.slice(scheme.length + 2).split('/', 1)[0]
  if (authority.includes('@')) return null

  const host = parsed.hostname
  if (host === '') return null
  // Doména musí mít tečku nebo jít o IPv6/localhost — chrání před tím, aby se
  // z překlepu bez schématu ("javascript") stalo "https://javascript".
  if (!host.includes('.') && !host.startsWith('[') && host.toLowerCase() !== 'localhost') return null

  // Schéma normalizujeme na malá písmena, zbytek URL necháváme beze změny
  // (cesta a query mohou být case-sensitive).
  const normalized = scheme + url.slice(scheme.length)
  if (normalized.length > SAFE_URL_MAX_LENGTH) return null

  return normalized
}
