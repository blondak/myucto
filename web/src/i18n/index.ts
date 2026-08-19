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

/**
 * Čeština má čtyři tvary: 0 / 1 / 2–4 / 5 a víc. Překlady je tak i píšou
 * („žádný záznam | 1 záznam | {count} záznamy | {count} záznamů"), jenže
 * vestavěné pravidlo vue-i18n vybírá `min(count, 2)` — čtvrtý tvar se tedy
 * nikdy nedostal ke slovu a od pěti výš aplikace psala „5 záznamy" místo
 * „5 záznamů". Ořez na počet dostupných tvarů drží i kratší zprávy: kde jsou
 * jen tři, dostane pětka poslední z nich.
 */
function czechPluralIndex(choice: number, choicesLength: number): number {
  const count = Math.abs(Math.trunc(choice))
  const index = count === 0 ? 0 : count === 1 ? 1 : count < 5 ? 2 : 3

  return Math.min(index, Math.max(choicesLength - 1, 0))
}

export const i18n = createI18n({
  legacy: false,
  locale: initialLocale,
  fallbackLocale: 'cs',
  messages: {},
  pluralRules: { cs: czechPluralIndex },
  /**
   * Záchranná brzda. Mapa prostorů vzniká statickou analýzou, a ta se dá obejít
   * — klíč složený za běhu z dat serveru v ní není vidět. Než ukázat uživateli
   * syrový klíč, dotáhneme radši jednorázově zbytek překladů; vue-i18n je
   * reaktivní, takže se text doplní sám. Cena je jedno zbytečné stažení
   * v případě, kdy analýza selhala — proti rozbitému UI dobrý obchod.
   */
  missing(_locale, key) {
    if (!key.includes('.')) return
    // Bez `catch` by selhané dotažení skončilo jako unhandled rejection uvnitř
    // renderu — hlučné a k ničemu, chybějící klíč stejně spadne na fallback.
    ensureAllNamespaces().catch(() => {})
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
 * Načte VŠECHNY prostory pro aktuální jazyk.
 *
 * Volá se ze záchranné brzdy `missing` výše, tedy potenciálně při KAŽDÉM
 * vykreslení klíče, který chybí i po dotažení všeho (skutečná díra v překladu).
 * Proto se za daný jazyk provede jen jednou — jinak by každý takový řádek
 * v seznamu vyrobil promise navíc.
 */
const allLoaded = new Map<Locale, Promise<void>>()
export function ensureAllNamespaces(): Promise<void> {
  const locale = i18n.global.locale.value as Locale
  let pending = allLoaded.get(locale)
  if (!pending) {
    // Selhání se nesmí zapamatovat jako „hotovo" — po výpadku sítě musí jít
    // zkusit znovu.
    pending = ensureNamespaces(namespaceMap.lazy).catch((e) => {
      allLoaded.delete(locale)
      throw e
    })
    allLoaded.set(locale, pending)
  }
  return pending
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
