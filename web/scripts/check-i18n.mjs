// Brána proti chybějícím překladům: vue-i18n na neznámý klíč nespadne, jen vypíše
// samotný klíč ("accounting.manual.debit" místo "MD"), takže se chybějící překlad
// pozná až v prohlížeči. Tenhle skript to posune do buildu.
//
// Volání překladače, která se berou v úvahu: t(...), $t(...), tc(...), tm(...), te(...)
// (přes libovolný přístup — t(), $t(), i18n.global.t(), useI18n().t(), obj.t() — hlídá
// se jen že jméno funkce NENÍ součástí delšího identifikátoru, takže "format(" nebo
// "payment(" nespustí falešný poplach) a <i18n-t keypath="…"> v šablonách.
// d(...)/n(...) (datum/číslo) se ignorují, to nejsou translation klíče.
//
// Pro každé volání se rozhoduje, čím je 1. argument:
//   1) STATIC   — řetězcový literál (uvozovky, nebo backtick BEZ ${}) → klíč musí
//                 existovat v cs.json (jinak je to sekce A — reálná chyba).
//   2) PREFIX   — dynamický klíč se statickým prefixem (`t('a.b_' + x)` nebo
//                 `t(\`a.b.${x}\`)`) → nelze ověřit přesný klíč, jen že aspoň jeden
//                 klíč pod daným prefixem existuje (sekce C, nikdy neshazuje build —
//                 přesně takhle vzniklo accounting.assets.accMethod.*, které statická
//                 kontrola nevidí, ale kontrolovat aspoň namespace pomáhá).
//   3) OPAQUE   — proměnná / výraz bez rozeznatelného statického základu (`t(key)`,
//                 `t(getKey())`, …) → nelze ověřit vůbec nic, jen se vypíše pro info.
//
// Komentáře (// … , /* … */, <!-- … -->) se před hledáním vymaskují (nahrazené
// mezerami se zachovaným počtem řádků), takže zakomentovaný kód nikdy nenahlásí
// nic — ani jako chybějící, ani jako použitý klíč.
//
// Výstup je rozdělený do tří sekcí:
//   (A) chybí v cs.json      — jediná věc, která shazuje exit code (reálná chyba)
//   (B) parita cs.json/en.json (celý slovník, nejen použité klíče) — varování
//   (C) dynamické klíče (prefix i opaque) — informativní, nikdy neshazuje exit code
//
// Spouští se z `npm run build`; samostatně `npm run check:i18n`.

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, dirname, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const webRoot = join(dirname(fileURLToPath(import.meta.url)), '..')
const srcDir = join(webRoot, 'src')
const locales = ['cs', 'en']

const flatten = (node, prefix = '', out = new Set()) => {
  for (const [key, value] of Object.entries(node)) {
    const path = prefix + key
    if (value && typeof value === 'object' && !Array.isArray(value)) flatten(value, path + '.', out)
    else out.add(path)
  }
  return out
}

const messages = Object.fromEntries(
  locales.map((l) => [l, flatten(JSON.parse(readFileSync(join(srcDir, 'i18n', `${l}.json`), 'utf8')))]),
)

const walk = (dir, files = []) => {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) walk(path, files)
    else if (/\.(vue|ts|js)$/.test(path)) files.push(path)
  }
  return files
}

// Vymaskuje // …, /* … */ a <!-- … --> komentáře (nahradí mezerami, řádky zachová),
// ale nechá beze změny obsah řetězců (', ", `) — včetně ${…} uvnitř template literálů,
// kde se rekurzivně řeší vnořené komentáře/řetězce (např. t(`a.${cond ? 'x' : 'y'}`)).
function stripComments(text) {
  const out = []
  const n = text.length
  let i = 0

  const blank = (from, to) => {
    for (let j = from; j < to; j++) out.push(text[j] === '\n' ? '\n' : ' ')
  }
  const copyString = (quote) => {
    out.push(text[i]); i++
    while (i < n && text[i] !== quote) {
      if (text[i] === '\\') { out.push(text[i], text[i + 1] ?? ''); i += 2; continue }
      out.push(text[i]); i++
    }
    if (i < n) { out.push(text[i]); i++ }
  }
  // Zpracuje kód (mimo řetězce) do doby, než dojde na `stopAt` znak v hloubce 0 (pro
  // ${…} je to '}'), nebo než dojde text — používá se jak na top-level, tak uvnitř
  // interpolace v template literálu.
  const consumeCode = (stopAt) => {
    let depth = 0
    while (i < n) {
      const c = text[i]
      if (stopAt && c === stopAt && depth === 0) return
      if (c === '{') { depth++; out.push(c); i++; continue }
      if (c === '}') { if (depth === 0 && stopAt) return; depth--; out.push(c); i++; continue }
      if (c === '/' && text[i + 1] === '/') { const j = text.indexOf('\n', i); const stop = j < 0 ? n : j; blank(i, stop); i = stop; continue }
      if (c === '/' && text[i + 1] === '*') { const j = text.indexOf('*/', i + 2); const stop = j < 0 ? n : j + 2; blank(i, stop); i = stop; continue }
      if (c === '<' && text[i + 1] === '!' && text[i + 2] === '-' && text[i + 3] === '-') {
        const j = text.indexOf('-->', i + 4); const stop = j < 0 ? n : j + 3; blank(i, stop); i = stop; continue
      }
      if (c === '"' || c === "'") { copyString(c); continue }
      if (c === '`') { copyTemplate(); continue }
      out.push(c); i++
    }
  }
  function copyTemplate() {
    out.push(text[i]); i++ // opening `
    while (i < n) {
      if (text[i] === '\\') { out.push(text[i], text[i + 1] ?? ''); i += 2; continue }
      if (text[i] === '`') { out.push(text[i]); i++; return }
      if (text[i] === '$' && text[i + 1] === '{') {
        out.push('$', '{'); i += 2
        consumeCode('}')
        if (i < n && text[i] === '}') { out.push('}'); i++ }
        continue
      }
      out.push(text[i]); i++
    }
  }

  consumeCode(null)
  return out.join('')
}

// (?<![\w$]) — jméno volání není součást delšího identifikátoru (vyloučí "format(",
// "payment(", ale povolí "$t(", "i18n.global.t(", "useI18n().t(", "obj.tm(" — před
// jménem je buď začátek, mezera, tečka, závorka nebo $).
const CALL_START = /(?<![\w$])\$?(?:t|tc|te|tm)\(/g
// `keypath="a.b.c"` je statický atribut; `:keypath="…"` / `v-bind:keypath="…"` je
// bind na výraz (dynamický) — (?<!:) vyloučí z STATIC ten druhý případ.
const I18N_T_STATIC = /<i18n-t\b[^>]*(?<!:)\bkeypath\s*=\s*(["'])([\w.]+)\1/g
const I18N_T_DYNAMIC = /<i18n-t\b[^>]*:keypath\s*=/g

// Klíč/prefix musí vypadat jako "a.b.c" (aspoň jedna tečka) — zamezí to náhodným
// shodám nesouvisejících volání s jednoslovným řetězcem.
const KEY_RE = /^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+$/
const PREFIX_RE = /^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*[._]$/

function parseArg(line, pos) {
  while (pos < line.length && /\s/.test(line[pos])) pos++
  const quote = line[pos]
  if (quote === '"' || quote === "'") {
    let j = pos + 1
    let buf = ''
    while (j < line.length && line[j] !== quote) {
      if (line[j] === '\\') { buf += line[j + 1] ?? ''; j += 2; continue }
      buf += line[j]; j++
    }
    if (j >= line.length) return { kind: 'opaque', snippet: `${quote}${buf}… (nedokončený řetězec na řádku)` }
    j++
    let k = j
    while (k < line.length && /\s/.test(line[k])) k++
    if (line[k] === '+') {
      if (PREFIX_RE.test(buf)) return { kind: 'prefix', prefix: buf }
      return { kind: 'opaque', snippet: `'${buf}' + …` }
    }
    if (KEY_RE.test(buf)) return { kind: 'static', key: buf }
    return { kind: 'opaque', snippet: `'${buf}'` }
  }
  if (quote === '`') {
    let j = pos + 1
    let buf = ''
    let sawInterp = false
    while (j < line.length && line[j] !== '`') {
      if (line[j] === '\\') { buf += line[j] + (line[j + 1] ?? ''); j += 2; continue }
      if (line[j] === '$' && line[j + 1] === '{') { sawInterp = true; break }
      buf += line[j]; j++
    }
    if (sawInterp) {
      if (PREFIX_RE.test(buf)) return { kind: 'prefix', prefix: buf }
      return { kind: 'opaque', snippet: `\`${buf}\${…}\`` }
    }
    if (KEY_RE.test(buf)) return { kind: 'static', key: buf }
    return { kind: 'opaque', snippet: `\`${buf}\`` }
  }
  // Ani uvozovka, ani backtick — proměnná / výraz (t(key), t(getKey()), t(a || b), …).
  const end = line.indexOf(')', pos)
  const raw = (end < 0 ? line.slice(pos, pos + 60) : line.slice(pos, end)).trim()
  return { kind: 'opaque', snippet: raw || '(prázdný / víceřádkový argument)' }
}

const staticKeys = new Map() // key -> string[] (where)
const prefixes = new Map() // prefix -> string[] (where)
const opaque = [] // { where, snippet }
const note = (map, key, where) => (map.get(key) ?? map.set(key, []).get(key)).push(where)

const files = walk(srcDir)
for (const file of files) {
  const where = relative(webRoot, file)
  const masked = stripComments(readFileSync(file, 'utf8'))
  masked.split('\n').forEach((line, i) => {
    const loc = `${where}:${i + 1}`
    for (const m of line.matchAll(CALL_START)) {
      const parsed = parseArg(line, m.index + m[0].length)
      if (parsed.kind === 'static') note(staticKeys, parsed.key, loc)
      else if (parsed.kind === 'prefix') note(prefixes, parsed.prefix, loc)
      else opaque.push({ where: loc, snippet: parsed.snippet })
    }
    for (const m of line.matchAll(I18N_T_STATIC)) note(staticKeys, m[2], loc)
    if (I18N_T_DYNAMIC.test(line)) opaque.push({ where: loc, snippet: '<i18n-t :keypath="…">' })
  })
}

// (A) — staticky použité klíče, které v cs.json nejsou. Jediná sekce co shazuje exit code.
const missingInCs = []
for (const [key, where] of staticKeys) {
  if (!messages.cs.has(key)) missingInCs.push({ key, where })
}

// (B) — parita celého slovníku cs.json <-> en.json (ne jen použité klíče).
const parityIssues = []
for (const [a, b] of [['cs', 'en'], ['en', 'cs']]) {
  for (const key of messages[a]) {
    if (!messages[b].has(key)) parityIssues.push(`${key} — je v ${a}.json, chybí v ${b}.json`)
  }
}

// (C) — dynamické prefixy: varuj jen když pod prefixem NENÍ ani jeden klíč (celý
// namespace pravděpodobně chybí — konkrétní chybějící list uvnitř existujícího
// namespace se tu záměrně nehlásí, aby to nebyl falešný poplach).
const missingNamespaces = []
for (const [prefix, where] of prefixes) {
  const missing = locales.filter((l) => ![...messages[l]].some((k) => k.startsWith(prefix)))
  if (missing.length) missingNamespaces.push({ prefix, where, missing })
}

const fmtLocations = (where, max = 3) => {
  const shown = where.slice(0, max).join(', ')
  return where.length > max ? `${shown}, +${where.length - max} dalších` : shown
}

// V buildu je skript jen brána — tiše projde, hlásí jen sekci A (reálné chyby).
// Detailní rozpis parity (B) a dynamických klíčů (C) je pro ladění: `--verbose`/`-v`
// (nebo I18N_VERBOSE=1), např. `node scripts/check-i18n.mjs --verbose`.
const VERBOSE = process.argv.includes('--verbose') || process.argv.includes('-v') || !!process.env.I18N_VERBOSE

console.log(`i18n check — ${files.length} souborů, ${staticKeys.size} statických klíčů, ${prefixes.size} dynamických prefixů, ${opaque.length} opaque volání\n`)

console.log(`=== (A) Chybí v cs.json — ${missingInCs.length} ===`)
if (missingInCs.length) {
  for (const { key, where } of missingInCs) console.log(`  ${key}\n    ${fmtLocations(where)}`)
} else {
  console.log('  žádné')
}

if (VERBOSE) {
  console.log(`\n=== (B) Parita cs.json <-> en.json — ${parityIssues.length} (varování, neshazuje build) ===`)
  if (parityIssues.length) {
    for (const p of parityIssues) console.log(`  ${p}`)
  } else {
    console.log('  žádné')
  }

  console.log(`\n=== (C) Dynamické klíče — nelze staticky ověřit, jen informativně ===`)
  console.log(`  prefixy: ${prefixes.size} (z toho ${missingNamespaces.length} s pravděpodobně chybějícím celým namespace)`)
  for (const { prefix, where, missing } of missingNamespaces) {
    console.log(`    ${prefix}* — chybí v: ${missing.join(', ')}\n      ${fmtLocations(where)}`)
  }
  console.log(`  opaque (proměnná/výraz, žádný statický základ): ${opaque.length}`)
  const opaqueGroups = new Map()
  for (const { where, snippet } of opaque) note(opaqueGroups, snippet, where)
  for (const [snippet, where] of opaqueGroups) console.log(`    ${snippet} — ${fmtLocations(where, 5)}`)
} else if (parityIssues.length || missingNamespaces.length) {
  console.log(`\n(B parita: ${parityIssues.length} · C namespace k prověření: ${missingNamespaces.length} — detail přes --verbose)`)
}

console.log(`\nSouhrn: A=${missingInCs.length} (reálné chyby) · B=${parityIssues.length} (parita) · C=${prefixes.size + opaque.length} (dynamické, informativní)`)

if (missingInCs.length) {
  console.error(`\ni18n: ${missingInCs.length} staticky použit${missingInCs.length === 1 ? 'ý klíč chybí' : 'ých klíčů chybí'} v cs.json (viz sekce A výše)`)
  process.exit(1)
}
