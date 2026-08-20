/**
 * Statická analýza: které jmenné prostory překladů potřebuje která stránka.
 *
 * Why: cs.json má 490 kB a načítá se celý při startu, i když stránka sáhne na
 * zlomek klíčů. Rozdělit ho jde jen tehdy, když se dá spolehlivě zjistit, co
 * která routa potřebuje — ručně udržovaný seznam by se rozešel s kódem během
 * týdne. Tenhle modul projde skutečný importní graf komponenty a posbírá
 * literály `t('ns.…')`, takže mapa vzniká z kódu, ne z dobré vůle.
 *
 * Sdílí ho generátor mapy i test, který hlídá, že mapa nezastarala.
 */
import { readFileSync, statSync } from 'node:fs'
import { dirname, resolve, join } from 'node:path'
import { fileURLToPath } from 'node:url'

export const SRC = fileURLToPath(new URL('../src/', import.meta.url))

const EXTENSIONS = ['', '.ts', '.vue', '.js', '/index.ts', '/index.vue', '/index.js']

/** `@/foo` i relativní `./foo` na skutečný soubor v src/. */
function resolveImport(spec, fromFile) {
  let base
  if (spec.startsWith('@/')) base = join(SRC, spec.slice(2))
  else if (spec.startsWith('.')) base = resolve(dirname(fromFile), spec)
  else return null // node_modules — překlady tam nejsou

  for (const ext of EXTENSIONS) {
    const candidate = base + ext
    if (candidate.endsWith('.json')) continue
    // `existsSync` sám nestačí: `@/pages/invoices` je adresář a čtení by spadlo
    // na EISDIR. Zajímají nás jen soubory.
    try {
      if (statSync(candidate).isFile()) return candidate
    } catch { /* neexistuje — zkoušíme další příponu */ }
  }
  return null
}

const STATIC_IMPORT_RE = /(?:import|export)\s[^'"]*?from\s*['"]([^'"]+)['"]|import\s*['"]([^'"]+)['"]/g
const DYNAMIC_IMPORT_RE = /import\s*\(\s*['"]([^'"]+)['"]\s*\)/g

/**
 * Statické a dynamické importy odděleně. Rozdíl je zásadní u `router/index.ts`:
 * ten dynamicky importuje KAŽDOU stránku, takže procházení dynamických hran od
 * rámce aplikace by prošlo celou aplikaci a jádro by spolklo celý cs.json.
 * Uvnitř jedné stránky naopak dynamický import (líně načtený modál) k té routě
 * pořád patří a jeho prostory se započítat musí.
 */
function collectImports(code, file) {
  const pick = (re, group) => {
    const out = []
    for (const m of code.matchAll(re)) {
      const target = m[group] && resolveImport(m[group], file)
      if (target) out.push(target)
    }
    return out
  }
  return {
    static: [...pick(STATIC_IMPORT_RE, 1), ...pick(STATIC_IMPORT_RE, 2)],
    dynamic: pick(DYNAMIC_IMPORT_RE, 1),
  }
}

/*
 * Klíč se nehledá jen uvnitř `t(…)`. Spousta míst si ho skládá bokem —
 * `const confirmKey = 'accounting.journal.delete_confirm'`, mapy
 * `ERROR_KEYS[code]`, `col.labelKey` z konfigurace sloupců — a analýza vázaná
 * na volání `t()` by je celé přehlédla. Proto se bere KAŽDÝ řetězcový literál,
 * který se v cs.json dá dohledat.
 *
 * Ověřit celou cestu, ne jen první segment, je nutné: aplikace je plná
 * identifikátorů OPRÁVNĚNÍ ve stejném tvaru (`accounting.journal.write`,
 * `settings.company`, `documents.requests`) a ty žijí v `permissions.ts`
 * a `AppLayout.vue`, které táhne skoro celá aplikace. Podle prvního segmentu
 * by tak jádro spolklo přes polovinu cs.json a dělení by nedávalo nic.
 *
 * Nadhodnocení zůstává bezpečné (načte se prostor navíc), podhodnocení ne —
 * chybějící překlad znamená syrový klíč v UI.
 */
const LITERAL_RE = /['"`]([A-Za-z_][\w]*(?:\.[\w]+)+)['"`]/g
// Interpolovaný konec klíče: `accounting.status.${x}` → ověřuje se prefix.
const TEMPLATE_PREFIX_RE = /`([A-Za-z_][\w]*(?:\.[\w]+)*)\.\$\{/g

/**
 * Klíče oprávnění mají stejný tvar jako překladové a některé se dokonce trefí
 * do existující cesty — `'accounting.templates'` je oprávnění, ale v cs.json je
 * pod ním i skutečná větev překladů. Protože ho `AppLayout` uvádí u položky
 * menu, přitáhl by celý 89 kB velký prostor `accounting` do jádra. Katalog
 * oprávnění je jediné místo pravdy, tak se z něj bere seznam k vyloučení.
 */
let permissionKeys = null
function knownPermissionKeys() {
  if (permissionKeys) return permissionKeys
  permissionKeys = new Set()
  try {
    const src = readFileSync(join(SRC, 'security/permissions.ts'), 'utf8')
    const block = src.match(/PERMISSION_KEYS\s*=\s*\[([\s\S]*?)\]/)
    if (block) for (const m of block[1].matchAll(/'([^']+)'/g)) permissionKeys.add(m[1])
  } catch { /* katalog chybí — nevadí, jen se nic nevyloučí */ }
  return permissionKeys
}

/** Existuje `a.b.c` v messages? Vrátí jmenný prostor, nebo null. */
function namespaceOf(path, messages) {
  if (knownPermissionKeys().has(path)) return null
  const parts = path.split('.')
  let node = messages
  for (const p of parts) {
    if (node === null || typeof node !== 'object' || !(p in node)) return null
    node = node[p]
  }
  return parts[0]
}

/**
 * Projde importní graf od `entry` a vrátí { namespaces, files }.
 * `messages` = obsah cs.json; proti němu se ověřuje, že nalezený literál je
 * skutečně existující překladový klíč (viz LITERAL_RE).
 *
 * `followDynamic: false` zastaví procházení na `import()` — použij pro rámec
 * aplikace, jinak se přes router dostaneš do všech stránek.
 */
export function analyze(entry, messages, cache = new Map(), followDynamic = true) {
  const namespaces = new Set()
  const files = new Set()
  const queue = [entry]

  while (queue.length) {
    const file = queue.pop()
    if (files.has(file)) continue
    files.add(file)

    let info = cache.get(file)
    if (!info) {
      const code = readFileSync(file, 'utf8')
      const ns = new Set()
      for (const m of code.matchAll(LITERAL_RE)) {
        const found = namespaceOf(m[1], messages)
        if (found) ns.add(found)
      }
      for (const m of code.matchAll(TEMPLATE_PREFIX_RE)) {
        const found = namespaceOf(m[1], messages)
        if (found) ns.add(found)
      }
      info = { namespaces: ns, imports: collectImports(code, file) }
      cache.set(file, info)
    }

    for (const ns of info.namespaces) namespaces.add(ns)
    queue.push(...info.imports.static)
    if (followDynamic) queue.push(...info.imports.dynamic)
  }

  return { namespaces, files }
}

/** Jmenné prostory = top-level klíče cs.json (referenční jazyk). */
export function knownNamespaces() {
  return JSON.parse(readFileSync(join(SRC, 'i18n/cs.json'), 'utf8'))
}

const ROUTE_RE = /name:\s*'([^']+)',\s*(?:\r?\n\s*)?component:\s*\(\)\s*=>\s*import\('@\/([^']+)'\)/g

/** [{ name, file }] pro každou routu, která má vlastní komponentu. */
export function routeEntries() {
  const sources = [
    readFileSync(join(SRC, 'router/index.ts'), 'utf8'),
    readFileSync(join(SRC, 'router/workspaceRoutes.ts'), 'utf8'),
  ]
  return sources.flatMap(source => [...source.matchAll(ROUTE_RE)]
    .map(m => ({ name: m[1], file: join(SRC, m[2]) })))
}
