import { describe, expect, it } from 'vitest'
import { stockSearchMatchLabel } from '../stockSearchMatch'

const t = (key: string) => `t:${key}`

describe('stockSearchMatchLabel', () => {
  it('pojmenuje sériové číslo a šarži překladem', () => {
    expect(stockSearchMatchLabel({ kind: 'serial', value: 'X1', attribute: null }, t)).toBe('t:stock.search_match.serial')
    expect(stockSearchMatchLabel({ kind: 'lot', value: 'L1', attribute: null }, t)).toBe('t:stock.search_match.lot')
  })

  it('u parametru použije jeho název, bez názvu obecný popisek', () => {
    expect(stockSearchMatchLabel({ kind: 'attribute', value: 'ABC', attribute: 'VIN' }, t)).toBe('VIN')
    expect(stockSearchMatchLabel({ kind: 'attribute', value: 'ABC', attribute: null }, t)).toBe('t:stock.search_match.attribute')
  })
})
