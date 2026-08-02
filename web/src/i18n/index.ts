import { createI18n } from 'vue-i18n'
import namespaceMap from './namespaces.generated.json'

type Locale = 'cs' | 'en'

/**
 * Překlady se nenačítají celé. cs.json má 488 kB (167 kB gzip) a medián stránky
 * z toho potřebuje asi desetinu — celý balík zdržoval první vykreslení při každé
 * studené návštěvě.
 *
 * Dělení jede podle `namespaces.generated.json`, který se počítá z reálného
 * importního grafu rout (`npm run gen:i18n`). Jádro = rámec aplikace (menu,
 * patička, hlášky) plus prostory, které chce většina rout; zbytek dotáhne router
 * ještě předtím, než se stránka vykreslí (viz `beforeEach` v router/index.ts).
 *
 * Samotné kousky vyrábí až build (plugins/i18n-split.mjs) — zdrojem pravdy
 * zůstává jeden JSON na jazyk, jinak by se rozešel s `check:i18n` i s překladateli.
 */

const CORE_CHUNKS = import.meta.glob('./chunks/*/__core.json')
const NAMESPACE_CHUNKS = import.meta.glob('./chunks/*/*.json')

const initialLocale: Locale = (localStorage.getItem('locale') as Locale) || 'cs'

export const i18n = createI18n({
  legacy: false,
  locale: initialLocale,
  fallbackLocale: 'cs',
  messages: {},
  /**
   * Záchranná brzda. Mapa prostorů vzniká statickou analýzou, a ta se dá obejít
   * — klíč složený za běhu z dat serveru v ní není vidět. Než ukázat uživateli
   * syrový klíč, dotáhneme radši jednorázově zbytek překladů; vue-i18n je
   * reaktivní, takže se text doplní sám. Cena je jedno zbytečné stažení
   * v případě, kdy analýza selhala — proti rozbitému UI dobrý obchod.
   */
  missing(_locale, key) {
    if (!key.includes('.')) return
    void ensureAllNamespaces()
  },
})

/** locale → prostory, které už jsou v paměti (jádro i dotažené). */
const loaded = new Map<Locale, Set<string>>()

function loadedFor(locale: Locale): Set<string> {
  let set = loaded.get(locale)
  if (!set) { set = new Set(); loaded.set(locale, set) }
  return set
}

/**
 * Slučovat, ne přepisovat — `setLocaleMessage` by dotažením jednoho prostoru
 * zahodil všechny předchozí.
 */
function merge(locale: Locale, messages: Record<string, unknown>): void {
  const current = i18n.global.getLocaleMessage(locale) as Record<string, unknown>
  i18n.global.setLocaleMessage(locale, { ...current, ...messages } as never)
}

/** Rozběhnuté načítání — dvě routy chtějící týž prostor sdílí jeden request. */
const inFlight = new Map<string, Promise<void>>()

function loadChunk(locale: Locale, namespace: string): Promise<void> {
  const key = `${locale}/${namespace}`
  const running = inFlight.get(key)
  if (running) return running

  const importer = NAMESPACE_CHUNKS[`./chunks/${locale}/${namespace}.json`]
  if (!importer) {
    // Prostor je v jádru, nebo v daném jazyce chybí — obojí je v pořádku;
    // chybějící překlad dořeší fallback na češtinu.
    loadedFor(locale).add(namespace)
    return Promise.resolve()
  }

  const promise = importer()
    .then((mod) => {
      merge(locale, (mod as { default: Record<string, unknown> }).default)
      loadedFor(locale).add(namespace)
    })
    .finally(() => inFlight.delete(key))

  inFlight.set(key, promise)
  return promise
}

const coreLoaded = new Set<Locale>()

async function loadCore(locale: Locale): Promise<void> {
  if (coreLoaded.has(locale)) return
  const importer = CORE_CHUNKS[`./chunks/${locale}/__core.json`]
  if (!importer) return
  const mod = await importer() as { default: Record<string, unknown> }
  merge(locale, mod.default)
  for (const ns of namespaceMap.core) loadedFor(locale).add(ns)
  coreLoaded.add(locale)
}

/** Dotáhne uvedené prostory pro aktuální jazyk. Už načtené přeskočí. */
export async function ensureNamespaces(namespaces: readonly string[]): Promise<void> {
  const locale = i18n.global.locale.value as Locale
  const have = loadedFor(locale)
  const missing = namespaces.filter(ns => !have.has(ns))
  if (missing.length === 0) return
  // Paralelně: pár kousků po jednotkách kB stihne HTTP/2 v jednom kole.
  await Promise.all(missing.map(ns => loadChunk(locale, ns)))
}

/** Prostory navíc, které potřebuje daná routa (nad rámec jádra). */
export function namespacesForRoute(routeName: string | null | undefined): readonly string[] {
  if (!routeName) return []
  return (namespaceMap.routes as Record<string, string[]>)[routeName] ?? []
}

/**
 * Načte VŠECHNY prostory. Volá se ze záchranné brzdy `missing` výše; opakované
 * volání je levné, protože už načtené prostory se přeskočí.
 */
let allPending: Promise<void> | null = null
export function ensureAllNamespaces(): Promise<void> {
  if (!allPending) {
    allPending = ensureNamespaces(namespaceMap.lazy).finally(() => { allPending = null })
  }
  return allPending
}

/**
 * Přepnutí jazyka: dotáhne jádro nového jazyka a k tomu prostory, které už
 * uživatel měl načtené v tom starém — jinak by po přepnutí zůstala část
 * stránky v syrových klíčích.
 */
export async function ensureLocaleLoaded(locale: Locale): Promise<void> {
  const previous = [...loadedFor(i18n.global.locale.value as Locale)]
  await loadCore(locale)
  const have = loadedFor(locale)
  const missing = previous.filter(ns => !have.has(ns))
  if (missing.length > 0) {
    await Promise.all(missing.map(ns => loadChunk(locale, ns)))
  }
}

export async function ensureInitialLocaleReady(): Promise<void> {
  await loadCore(initialLocale)
  // Čeština je fallback pro chybějící anglické klíče — bez jejího jádra by
  // anglická relace ukazovala syrové klíče všude, kde překlad chybí.
  if (initialLocale !== 'cs') await loadCore('cs')
}
