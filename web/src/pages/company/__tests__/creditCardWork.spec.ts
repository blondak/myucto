import { describe, expect, it } from 'vitest'
import { isTodo, openingLines, statementLink, todoRows } from '../creditCardWork'
import type { CreditCardOpening, CreditCardTodoRow, CreditCardTodoState } from '@/api/creditCards'

function opening(amount: number, over: Partial<CreditCardOpening> = {}): CreditCardOpening {
  return {
    account_code: '231.101',
    statement: { id: 5, statement_date: '2099-06-30', statement_number: '6', prev_balance: amount },
    entry_date: '2099-06-02',
    contra_account_code: '379',
    contra_account_id: null,
    posted_entry_id: null,
    other_balance: 0,
    amount,
    needed: Math.abs(amount) >= 0.005,
    ...over,
  }
}

describe('statementLink', () => {
  it('otevře výpis rovnou na pohybu (zvýraznění přes ?tx)', () => {
    expect(statementLink(12, 345)).toEqual({ name: 'bank-detail', params: { id: 12 }, query: { tx: '345' } })
  })

  it('bez pohybu otevře jen výpis', () => {
    expect(statementLink(12)).toEqual({ name: 'bank-detail', params: { id: 12 } })
  })
})

describe('todoRows', () => {
  const row = (count: number, amount = 0): CreditCardTodoRow => ({ count, amount, first_statement_id: count ? 1 : null, first_tx_id: count ? 2 : null })

  it('vrací jen stavy, kde něco zbývá, v pevném pořadí', () => {
    const todo: Record<CreditCardTodoState, CreditCardTodoRow> = {
      suggested: row(2, 300),
      unposted: row(1, 50),
    }
    expect(todoRows(todo).map(r => r.state)).toEqual(['unposted', 'suggested'])
    expect(todoRows(todo)[1].amount).toBe(300)
    expect(todoRows({ unposted: row(1, 50), suggested: row(0) }).map(r => r.state)).toEqual(['unposted'])
  })

  it('bez dat je prázdné', () => {
    expect(todoRows(undefined)).toEqual([])
  })
})

describe('isTodo', () => {
  it('vyřešené stavy nejsou úkol', () => {
    expect(isTodo('unposted')).toBe(true)
    expect(isTodo('posted')).toBe(false)
    expect(isTodo('ignored')).toBe(false)
  })
})

describe('openingLines', () => {
  it('dluh (záporný zůstatek) je D 231.x a MD protiúčet', () => {
    expect(openingLines(opening(-1000.5), '379')).toEqual([
      { account_code: '379', side: 'debit', amount: 1000.5 },
      { account_code: '231.101', side: 'credit', amount: 1000.5 },
    ])
  })

  it('přeplatek jde obráceně', () => {
    expect(openingLines(opening(250), '379')).toEqual([
      { account_code: '231.101', side: 'debit', amount: 250 },
      { account_code: '379', side: 'credit', amount: 250 },
    ])
  })

  it('nic nenavrhne, když není co účtovat', () => {
    expect(openingLines(opening(0), '379')).toEqual([])
    expect(openingLines(opening(-10, { account_code: null }), '379')).toEqual([])
  })
})
