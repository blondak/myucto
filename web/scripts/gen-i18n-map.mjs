/**
 * Vygeneruje `src/i18n/namespaces.generated.json` — které jmenné prostory
 * překladů potřebuje která routa.
 *
 * Why: cs.json má 488 kB a načítá se celý při startu, i když medián routy sáhne
 * na 46 kB. Ručně udržovaný seznam by se rozešel s kódem během týdne, proto se
 * mapa počítá z reálného importního grafu (viz i18n-usage.mjs). Test
 * `tests/i18n-map.test.mjs` hlídá, že vygenerovaný soubor odpovídá kódu.
 *
 * Spouštět přes `npm run gen:i18n` po přidání klíčů do nového prostoru.
 */
import { writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { pathToFileURL } from 'node:url'
import { analyze, SRC, knownNamespaces, routeEntries } from './i18n-usage.mjs'

/**
 * Vstupní body aplikačního rámce — načtou se vždy, bez ohledu na routu.
 * AppLayout drží menu, přepínač firmy, patičku i paletu příkazů; App.vue
 * toasty a zámek relace.
 */
const SHELL_ENTRIES = [
  'main.ts',
  'App.vue',
  'components/layout/AppLayout.vue',
  'router/index.ts',
]

/**
 * Prostor, který chce většina rout, nemá smysl posílat zvlášť — režie requestu
 * by převážila. Práh je vědomě nízký (40 %): u prostorů kolem něj jde o jednotky
 * kilobajtů, takže případné zbytečné načtení nic nestojí.
 */
const CORE_ROUTE_SHARE = 0.4

export function buildMap() {
  const messages = knownNamespaces()
  const known = new Set(Object.keys(messages))
  const cache = new Map()


  // Rámec se prochází BEZ dynamických hran — router dynamicky importuje každou
  // stránku a jádro by jinak spolklo celý cs.json.
  const core = new Set()
  const shellCache = new Map()
  for (const entry of SHELL_ENTRIES) {
    for (const ns of analyze(join(SRC, entry), messages, shellCache, false).namespaces) core.add(ns)
  }

  const routes = routeEntries()
  const perRoute = new Map()
  const useCount = new Map()
  for (const r of routes) {
    const { namespaces } = analyze(r.file, messages, cache)
    perRoute.set(r.name, namespaces)
    for (const ns of namespaces) useCount.set(ns, (useCount.get(ns) ?? 0) + 1)
  }

  for (const [ns, count] of useCount) {
    if (count / routes.length >= CORE_ROUTE_SHARE) core.add(ns)
  }

  // Prostor, který nechce ani rámec, ani žádná routa, do jádra nepatří — ale ani
  // ho nesmíme ztratit: sáhne na něj typicky komponenta načtená až za běhu.
  // Zbytek se dobírá až na vyžádání, viz `ensureNamespaces` v i18n/index.ts.
  const lazy = [...known].filter(ns => !core.has(ns)).sort()

  const routeMap = {}
  for (const [name, namespaces] of perRoute) {
    const needed = [...namespaces].filter(ns => !core.has(ns)).sort()
    if (needed.length > 0) routeMap[name] = needed
  }

  return {
    core: [...core].sort(),
    lazy,
    routes: routeMap,
  }
}

const OUT = join(SRC, 'i18n/namespaces.generated.json')

// `file://` + cesta nestačí: na Windows vzniká `file:///C:/…` se třemi lomítky
// a porovnání by tiše selhalo (skript by se choval jako pouhá knihovna).
if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  const map = buildMap()
  writeFileSync(OUT, JSON.stringify(map, null, 2) + '\n')

  const messages = knownNamespaces()
  const kb = ns => Buffer.byteLength(JSON.stringify(messages[ns])) / 1024
  const total = Object.keys(messages).reduce((s, ns) => s + kb(ns), 0)
  const coreKb = map.core.reduce((s, ns) => s + kb(ns), 0)
  const routeSizes = Object.values(map.routes).map(list => list.reduce((s, ns) => s + kb(ns), 0)).sort((a, b) => a - b)
  const median = routeSizes[Math.floor(routeSizes.length / 2)] ?? 0

  console.log(`${relative(process.cwd(), OUT)}`)
  console.log(`  jádro ${map.core.length} prostorů / ${Math.round(coreKb)} kB (z ${Math.round(total)} kB)`)
  console.log(`  na vyžádání ${map.lazy.length} prostorů, medián routy +${Math.round(median)} kB`)
  console.log(`  rout s doplňkem: ${Object.keys(map.routes).length}`)
}
