import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import test from 'node:test'
import { buildMap } from '../scripts/gen-i18n-map.mjs'
import { SRC, knownNamespaces } from '../scripts/i18n-usage.mjs'

/**
 * `namespaces.generated.json` říká runtime, které překlady dotáhnout před
 * vykreslením routy. Když se rozejde s kódem, uživatel uvidí syrové klíče —
 * a to je tichá regrese, kterou typová kontrola ani testy komponent nechytí.
 *
 * Po přidání klíčů z nového jmenného prostoru spusť `npm run gen:i18n`.
 */

const MAP_PATH = join(SRC, 'i18n/namespaces.generated.json')

test('mapa jmenných prostorů odpovídá kódu', () => {
  const stored = JSON.parse(readFileSync(MAP_PATH, 'utf8'))
  const fresh = buildMap()

  assert.deepEqual(
    stored,
    fresh,
    'src/i18n/namespaces.generated.json je zastaralý — spusť `npm run gen:i18n` a změnu commitni',
  )
})

test('jádro a doplňky pokrývají všechny prostory z cs.json', () => {
  const map = JSON.parse(readFileSync(MAP_PATH, 'utf8'))
  const known = new Set(Object.keys(knownNamespaces()))
  const covered = new Set([...map.core, ...map.lazy])

  const missing = [...known].filter(ns => !covered.has(ns))
  assert.deepEqual(missing, [], 'prostor by se nikdy nenačetl')

  const extra = [...covered].filter(ns => !known.has(ns))
  assert.deepEqual(extra, [], 'mapa odkazuje na prostor, který v cs.json není')
})

test('routa nikdy nežádá prostor, který je už v jádru', () => {
  const map = JSON.parse(readFileSync(MAP_PATH, 'utf8'))
  const core = new Set(map.core)
  const offenders = Object.entries(map.routes)
    .filter(([, list]) => list.some(ns => core.has(ns)))
    .map(([name]) => name)

  assert.deepEqual(offenders, [], 'zbytečný request — prostor je součástí jádra')
})
