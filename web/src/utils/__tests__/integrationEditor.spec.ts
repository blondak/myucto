import { describe, expect, it } from 'vitest'
import type { ConnectorDefinition } from '@/api/eshopIntegrations'
import {
  mappingRowsFrom, mappingsFromRows, ownershipFromRows, ownershipRowsFrom, parseJsonObject,
  validateMappingsObject, validateOwnershipObject,
} from '../integrationEditor'

const definition: ConnectorDefinition = {
  key: 'custom.webhook', available: true, i18n: 'custom_webhook', name: 'Vlastní', capabilities: [], notes: [], free_fields: true,
  credentials: [],
  mappings: [
    { type: 'warehouses', source: 'warehouses', label: 'Sklady' },
    { type: 'currencies', source: 'currencies', label: 'Měny' },
  ],
  fields: [
    { key: 'product.name', area: 'product', default_owner: 'local', label: 'Název' },
    { key: 'order.status', area: 'order', default_owner: 'remote', label: 'Stav' },
  ],
}
const lookups = {
  warehouses: [{ value: 'HLAVNI', label: 'HLAVNI · Hlavní', active: true }, { value: '0', label: '0 · Nula', active: true }],
  currencies: [{ value: 'CZK', label: 'CZK', active: true }],
}

describe('integrationEditor', () => {
  it('round-trips mappings between the stored object and table rows', () => {
    const stored = { warehouses: { HLAVNI: 'main-store', 0: 'store-zero' }, currencies: { CZK: 'CZK' }, legacy: { x: 'y' } }
    const rows = mappingRowsFrom(stored, ['warehouses', 'currencies', 'languages'])
    expect(rows.warehouses).toEqual([{ local: '0', remote: 'store-zero' }, { local: 'HLAVNI', remote: 'main-store' }])
    expect(rows.languages).toEqual([])
    expect(mappingsFromRows(rows)).toEqual({
      value: { warehouses: { 0: 'store-zero', HLAVNI: 'main-store' }, currencies: { CZK: 'CZK' } },
      issues: [],
    })
    expect(mappingRowsFrom([], ['warehouses'])).toEqual({ warehouses: [] })
  })

  it('reports incomplete and duplicate rows but ignores empty ones', () => {
    const result = mappingsFromRows({
      warehouses: [
        { local: '', remote: '' },
        { local: '', remote: 'orphan' },
        { local: 'HLAVNI', remote: ' ' },
        { local: '0', remote: 'a' },
        { local: '0', remote: 'b' },
      ],
    })
    expect(result.issues).toEqual([
      { code: 'local_missing', type: 'warehouses', row: 2 },
      { code: 'remote_missing', type: 'warehouses', row: 3, value: 'HLAVNI' },
      { code: 'duplicate', type: 'warehouses', value: '0' },
    ])
    expect(result.value).toEqual({ warehouses: { 0: 'a' } })
  })

  it('validates hand-written JSON against the connector definition and lookups', () => {
    expect(validateMappingsObject({ warehouses: { HLAVNI: 'x' } }, definition, lookups)).toEqual([])
    expect(validateMappingsObject({ payment_methods: {}, warehouses: { CIZI: 'x', HLAVNI: '' }, currencies: [] }, definition, lookups)).toEqual([
      { code: 'unknown_type', type: 'payment_methods' },
      { code: 'unknown_value', type: 'warehouses', value: 'CIZI' },
      { code: 'remote_missing', type: 'warehouses', value: 'HLAVNI' },
      { code: 'not_object', type: 'currencies' },
    ])
  })

  it('fills ownership defaults, keeps custom fields and validates owners', () => {
    const rows = ownershipRowsFrom(definition, { 'order.status': 'manual', 'custom.points': 'local' })
    expect(rows.map(row => [row.key, row.owner, row.known])).toEqual([
      ['product.name', 'local', true],
      ['order.status', 'manual', true],
      ['custom.points', 'local', false],
    ])
    expect(ownershipFromRows(rows)).toEqual({ 'product.name': 'local', 'order.status': 'manual', 'custom.points': 'local' })
    expect(validateOwnershipObject({ 'product.name': 'eshop', 'Bad Key': 'local', 'custom.ok': 'remote' }, definition)).toEqual([
      { code: 'bad_owner', key: 'product.name' },
      { code: 'unknown_field', key: 'Bad Key' },
    ])
    expect(validateOwnershipObject({ 'custom.ok': 'remote' }, { ...definition, free_fields: false }))
      .toEqual([{ code: 'unknown_field', key: 'custom.ok' }])
  })

  it('parses only JSON objects', () => {
    expect(parseJsonObject('')).toEqual({})
    expect(parseJsonObject('{"a":1}')).toEqual({ a: 1 })
    expect(parseJsonObject('[]')).toBeNull()
    expect(parseJsonObject('{')).toBeNull()
  })
})
