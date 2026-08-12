import { describe, it, expect } from 'vitest'
import { accountPickerOptions } from '@/utils/chartAccountOptions'
import type { ChartAccount } from '@/api/accounting'

function account(id: number, code: string, parentId: number | null, isActive = true): ChartAccount {
  return {
    id,
    supplier_id: 1,
    account_code: code,
    name: 'Účet ' + code,
    account_type: 'asset',
    normal_side: 'debit',
    is_synthetic: parentId === null,
    parent_id: parentId,
    is_active: isActive,
    created_at: '2026-01-01 00:00:00',
  }
}

const codes = (list: ChartAccount[]): string[] => list.map(a => a.account_code)

describe('accountPickerOptions', () => {
  it('nabídne analytiku PŘED její syntetikou', () => {
    // Regrese: firma převedená na analytiky účtuje na 311.100, ne na 311.
    const out = accountPickerOptions([
      account(73, '311', null),
      account(3138629, '311.100', 73),
      account(74, '311D', 73),
    ])
    expect(codes(out)).toEqual(['311.100', '311D', '311'])
  })

  it('syntetiku bez analytik nechá na místě (firma bez analytického členění)', () => {
    const out = accountPickerOptions([
      account(2, '321', null),
      account(1, '311', null),
      account(3, '518', null),
    ])
    expect(codes(out)).toEqual(['311', '321', '518'])
  })

  it('drží pořadí rodin podle kódu syntetiky', () => {
    const out = accountPickerOptions([
      account(63, '221', null),
      account(200762, '221.100', 63),
      account(73, '311', null),
      account(3138629, '311.100', 73),
      account(100, '518', null),
    ])
    expect(codes(out)).toEqual(['221.100', '221', '311.100', '311', '518'])
  })

  it('vyhodí neaktivní účty', () => {
    const out = accountPickerOptions([
      account(73, '311', null),
      account(1, '311.100', 73),
      account(2, '311.900', 73, false),
    ])
    expect(codes(out)).toEqual(['311.100', '311'])
  })

  it('respektuje filtr a nestáhne s odfiltrovanou syntetikou i její analytiky', () => {
    const out = accountPickerOptions(
      [
        account(63, '221', null),
        account(200762, '221.100', 63),
        account(3138611, '221.400', 63),
        account(73, '311', null),
      ],
      a => a.account_code.startsWith('221'),
    )
    expect(codes(out)).toEqual(['221.100', '221.400', '221'])
  })

  it('umí i analytiku analytiky', () => {
    const out = accountPickerOptions([
      account(1, '343', null),
      account(2, '343.100', 1),
      account(3, '343.100.1', 2),
    ])
    expect(codes(out)).toEqual(['343.100.1', '343.100', '343'])
  })
})
