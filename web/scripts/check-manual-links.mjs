// Brána proti odkazu na neexistující kapitolu manuálu.
//
// Kapitoly se přečíslovávají nástrojem `tools/renumberManual.php`. Ten přepíše
// křížové odkazy v `manual/*.md`, v INDEX.md a v obou README — o odkazech ve
// frontendu ale neví. Kontextová nápověda (mapa `MANUAL_CHAPTERS` v AppLayout.vue)
// a přímé odkazy `/manual?ch=…` se tím tiše rozejdou: uživateli se otevře
// rozcestník místo kapitoly, nic se nezaloguje, build projde.
//
// Stalo se to dvakrát (naposledy při vyčlenění kapitoly o mzdách), proto tenhle
// guard. Hledá staticky dva tvary a ověřuje, že soubor kapitoly existuje:
//   - `'NN_Nazev'` / `'NNa_Nazev'` v .ts a .vue (mapa kontextové nápovědy)
//   - `?ch=NN_Nazev` v odkazech
//
// Spouští se z `npm run build`; samostatně `npm run check:manual`.

import { readdirSync, readFileSync } from 'node:fs'
import { join, dirname, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const webRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const repoRoot = join(webRoot, '..')
const manualDir = join(repoRoot, 'manual')
const srcDir = join(webRoot, 'src')

// Řetězce, které vypadají jako název kapitoly, ale nejsou jí — hodnoty číselníků
// apod. Whitelist je záměrně na přesnou hodnotu, ne na soubor: jinak by v tom
// souboru propadl i skutečně rozbitý odkaz.
const NOT_A_CHAPTER = new Set(['9_ending'])

const chapters = new Set(
  readdirSync(manualDir)
    .filter(f => /^\d+[a-z]?_.+\.md$/.test(f))
    .map(f => f.replace(/\.md$/, '')),
)

if (chapters.size === 0) {
  console.error('check-manual-links: v manual/ nejsou žádné kapitoly — sken je rozbitý, ne kód.')
  process.exit(1)
}

function walk(dir) {
  const out = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === '__tests__') continue
      out.push(...walk(full))
    } else if (/\.(ts|vue)$/.test(entry.name)) {
      out.push(full)
    }
  }
  return out
}

const REFERENCE = /(?:'|")(\d+[a-z]?_[A-Za-z0-9_]+)(?:'|")|ch=(\d+[a-z]?_[A-Za-z0-9_]+)/g
const broken = []
let seen = 0

for (const file of walk(srcDir)) {
  const src = readFileSync(file, 'utf8')
  const lines = src.split(/\r?\n/)
  lines.forEach((line, i) => {
    for (const m of line.matchAll(REFERENCE)) {
      const ref = m[1] ?? m[2]
      if (NOT_A_CHAPTER.has(ref)) continue
      seen++
      if (!chapters.has(ref)) {
        broken.push({ file: relative(repoRoot, file), line: i + 1, ref })
      }
    }
  })
}

if (seen === 0) {
  console.error('check-manual-links: nenašel jsem ani jeden odkaz na kapitolu — sken je rozbitý, ne kód.')
  process.exit(1)
}

if (broken.length > 0) {
  console.error(`check-manual-links: ${broken.length} odkazů míří na neexistující kapitolu manuálu.\n`)
  for (const b of broken) {
    const stem = b.ref.replace(/^\d+[a-z]?_/, '')
    const suggestion = [...chapters].find(c => c.replace(/^\d+[a-z]?_/, '') === stem)
    console.error(`  ${b.file}:${b.line}  ${b.ref}${suggestion ? `  →  ${suggestion}` : '  (kapitola se stejným názvem neexistuje)'}`)
  }
  console.error('\nKapitoly se přečíslovávají přes tools/renumberManual.php — ten frontend nepřepisuje, oprav ho ručně.')
  process.exit(1)
}

console.log(`check-manual-links: OK — ${seen} odkazů, všechny na existující kapitolu.`)
