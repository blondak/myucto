import { describe, expect, it } from 'vitest'
import type { BankStatement } from '@/api/bank'
import { statementClosingBalance, statementGpcTitle, statementGpcUrl } from '@/utils/bankStatement'

describe('intraday advice balance', () => {
  const advice = {
    id: 123, source: 'bank_api', has_file: false, curr_balance: null,
    balance_calculation: { status: 'calculated', opening: null, closing: 125, bank_statement_id: null },
  } as BankStatement

  it('shows the known balance without offering a fabricated GPC opening balance', () => {
    expect(statementClosingBalance(advice)).toBe(125)
    expect(statementGpcUrl(advice)).toBeUndefined()
    expect(statementGpcTitle(advice)).toBe('bank.balance_missing_anchor')
  })

  it('offers the original bank document after finalization', () => {
    const finalized = { ...advice, balance_calculation: { ...advice.balance_calculation, bank_statement_id: 456 } } as BankStatement
    expect(statementGpcUrl(finalized)).toContain('456')
    expect(statementGpcTitle(finalized)).toBe('bank.download_gpc')
  })
})
