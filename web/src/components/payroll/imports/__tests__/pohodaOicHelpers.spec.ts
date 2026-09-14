import { describe, expect, it } from 'vitest'
import type { PohodaOicRow, PohodaOicRowStatus } from '@/api/payrollImports'
import {
  oicApplyBlock,
  oicResultClass,
  oicStatusClass,
  pruneOicSelection,
  selectableOicKeys,
  visibleOicRows,
} from '../pohodaOicHelpers'

function row(key: string, status: PohodaOicRowStatus): PohodaOicRow {
  return {
    key,
    file: 'personalistika.xlsx',
    sheet: 'Personalistika',
    row: 6,
    name: 'Testovací Jana',
    personal_number: 'Z0001',
    birth_number_masked: '••••••••05',
    oic_masked: '••••••1234',
    status,
    message: '',
    selectable: status === 'ready',
    employee_id: status === 'ready' ? 1 : null,
    employee_name: null,
    employment_id: status === 'ready' ? 2 : null,
    employment_code: null,
    valid_from: status === 'ready' ? '2020-01-01' : null,
  }
}

describe('pohodaOicHelpers', () => {
  const rows = [row('a', 'ready'), row('b', 'conflict'), row('c', 'no_oic'), row('d', 'ready')]

  it('hides rows without OIČ and offers only ready rows', () => {
    expect(visibleOicRows(rows).map(item => item.key)).toEqual(['a', 'b', 'd'])
    expect(selectableOicKeys(rows)).toEqual(['a', 'd'])
  })

  it('drops selection that is no longer ready after a new preview', () => {
    expect(pruneOicSelection(['a', 'b', 'x', 'd'], [row('a', 'already_stored'), row('d', 'ready')])).toEqual(['d'])
  })

  it('explains why apply is disabled in the order the user fixes it', () => {
    expect(oicApplyBlock({ hasPreview: false, selectedCount: 1, confirmed: true })).toBe('no_preview')
    expect(oicApplyBlock({ hasPreview: true, selectedCount: 0, confirmed: true })).toBe('no_selection')
    expect(oicApplyBlock({ hasPreview: true, selectedCount: 2, confirmed: false })).toBe('no_confirmation')
    expect(oicApplyBlock({ hasPreview: true, selectedCount: 2, confirmed: true })).toBeNull()
  })

  it('marks blockers red and never shows a conflict as success', () => {
    expect(oicStatusClass('ready')).toContain('success')
    expect(oicStatusClass('conflict')).toContain('warning')
    expect(oicStatusClass('oic_owned_by_other')).toContain('danger')
    expect(oicStatusClass('invalid_oic')).toContain('danger')
    expect(oicResultClass('failed')).toContain('danger')
  })
})
