import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const auth = await readFile(new URL('stores/auth.ts', root), 'utf8')
const layout = await readFile(new URL('components/layout/AppLayout.vue', root), 'utf8')
const router = await readFile(new URL('router/index.ts', root), 'utf8')

test('expired licenses expose a dedicated commercial feature capability', () => {
  assert.match(auth, /const hasCommercialFeatures = computed\(\(\) => license\.value\?\.commercial_features !== false\)/)
  assert.match(auth, /hasCommercialFeatures,/)
})

test('commercial navigation sections and VAT corrections are hidden', () => {
  assert.match(layout, /if \(auth\.hasCommercialFeatures && isDoubleEntry\)/)
  assert.match(layout, /auth\.hasCommercialFeatures[\s\S]*\/reports\/s74b/)
  assert.match(layout, /auth\.hasCommercialFeatures[\s\S]*\/reports\/vat-corrections/)
  assert.match(layout, /auth\.hasCommercialFeatures && supplierStore\.currentSupplier\?\.stock_enabled/)
})

test('direct navigation to commercial pages is rejected', () => {
  assert.match(router, /commercialOnly\?: boolean/)
  assert.match(router, /commercialOnly && !auth\.hasCommercialFeatures/)
  assert.match(router, /'reports-s74b'/)
  assert.match(router, /'reports-vat-corrections'/)
  assert.match(router, /'tools'/)
})
