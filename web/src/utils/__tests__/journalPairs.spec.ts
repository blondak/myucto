import { describe, expect, it } from 'vitest'
import { canPair, pairLines } from '@/utils/journalPairs'
import type { JournalLine } from '@/api/accounting'

let nextId = 1

function line(partial: Partial<JournalLine> & Pick<JournalLine, 'side' | 'amount'>): JournalLine {
  const id = nextId++
  return {
    id,
    entry_id: 1,
    supplier_id: 1,
    account_id: id,
    account_code: `${100 + id}.000`,
    account_name: `Účet ${id}`,
    currency_code: null,
    fx_rate: null,
    amount_foreign: null,
    cost_center: null,
    line_no: id,
    ...partial,
  }
}

describe('journalPairs', () => {
  it('spáruje prostou úhradu na jednu souvztažnost', () => {
    const lines = [
      line({ side: 'debit', amount: 101640, account_code: '221.400' }),
      line({ side: 'credit', amount: 101640, account_code: '311.100' }),
    ]

    expect(canPair(lines)).toBe(true)
    const pairs = pairLines(lines)
    expect(pairs).toHaveLength(1)
    expect(pairs[0].debit?.account_code).toBe('221.400')
    expect(pairs[0].credit?.account_code).toBe('311.100')
    expect(pairs[0].amount).toBe(101640)
  })

  it('rozpustí pohledávku mezi tržbu a DPH tak, jak to dělá předkontace', () => {
    const lines = [
      line({ side: 'debit', amount: 101640, account_code: '311.100' }),
      line({ side: 'credit', amount: 84000, account_code: '602.100' }),
      line({ side: 'credit', amount: 17640, account_code: '343.200' }),
    ]

    expect(canPair(lines)).toBe(true)
    const pairs = pairLines(lines)
    expect(pairs.map(p => [p.debit?.account_code, p.credit?.account_code, p.amount])).toEqual([
      ['311.100', '602.100', 84000],
      ['311.100', '343.200', 17640],
    ])
    // Rozpad nesmí změnit ani korunu.
    expect(pairs.reduce((s, p) => s + p.amount, 0)).toBe(101640)
  })

  it('počítá v haléřích, takže se rozpad nerozjede o zaokrouhlení', () => {
    const lines = [
      line({ side: 'debit', amount: 100.03, account_code: '321.000' }),
      line({ side: 'credit', amount: 33.34, account_code: '221.000' }),
      line({ side: 'credit', amount: 33.34, account_code: '221.100' }),
      line({ side: 'credit', amount: 33.35, account_code: '221.200' }),
    ]

    expect(canPair(lines)).toBe(true)
    const pairs = pairLines(lines)
    expect(pairs).toHaveLength(3)
    expect(pairs.reduce((s, p) => s + p.amount, 0)).toBeCloseTo(100.03, 2)
  })

  it('nepáruje, když má obě strany víc nohou — dvojice by si vymyslela vztahy', () => {
    const lines = [
      line({ side: 'debit', amount: 500, account_code: '501.000' }),
      line({ side: 'debit', amount: 500, account_code: '518.000' }),
      line({ side: 'credit', amount: 400, account_code: '321.000' }),
      line({ side: 'credit', amount: 600, account_code: '325.000' }),
    ]

    expect(canPair(lines)).toBe(false)
  })

  it('nepáruje nevyrovnaný zápis — koncept musí být vidět tak, jak je', () => {
    const lines = [
      line({ side: 'debit', amount: 1000, account_code: '221.000' }),
      line({ side: 'credit', amount: 900, account_code: '311.000' }),
    ]

    expect(canPair(lines)).toBe(false)
  })

  it('nepáruje, když by se dělila noha v cizí měně — devizová částka se nedopočítává', () => {
    const lines = [
      line({ side: 'debit', amount: 24000, account_code: '311.100', currency_code: 'EUR', amount_foreign: 1000 }),
      line({ side: 'credit', amount: 20000, account_code: '602.100' }),
      line({ side: 'credit', amount: 4000, account_code: '343.200' }),
    ]

    expect(canPair(lines)).toBe(false)
  })

  it('devizovou částku bere z nohy, která do dvojice vstoupila celá', () => {
    const lines = [
      line({ side: 'debit', amount: 24000, account_code: '221.300', currency_code: 'EUR', amount_foreign: 1000 }),
      line({ side: 'credit', amount: 24000, account_code: '311.100', currency_code: 'EUR', amount_foreign: 1000 }),
    ]

    const [pair] = pairLines(lines)
    expect(pair.amountForeign).toBe(1000)
    expect(pair.currencyCode).toBe('EUR')
  })

  it('středisko přebírá z té nohy, která ho má vyplněné', () => {
    const lines = [
      line({ side: 'debit', amount: 1000, account_code: '518.000', cost_center: 'PROVOZ' }),
      line({ side: 'credit', amount: 1000, account_code: '321.000' }),
    ]

    expect(pairLines(lines)[0].costCenter).toBe('PROVOZ')
  })
  it('spáruje přenesenou daň — dvě nohy proti dvěma, částky si odpovídají', () => {
    const lines = [
      line({ side: 'debit', amount: 4123.97, account_code: '518.100', line_no: 1 }),
      line({ side: 'debit', amount: 866.03, account_code: '343.100', line_no: 2 }),
      line({ side: 'credit', amount: 866.03, account_code: '343.200', line_no: 3 }),
      line({ side: 'credit', amount: 4123.97, account_code: '321.100', line_no: 4 }),
    ]

    expect(canPair(lines)).toBe(true)
    // Bez přiřazení podle částek by pořadí řádků spojilo 518 s daní a 343 se závazkem.
    expect(pairLines(lines).map(p => [p.debit?.account_code, p.credit?.account_code, p.amount])).toEqual([
      ['518.100', '321.100', 4123.97],
      ['343.100', '343.200', 866.03],
    ])
  })

  it('nepáruje, když se částka na straně opakuje — přiřazení by bylo náhodné', () => {
    const lines = [
      line({ side: 'debit', amount: 500, account_code: '518.001' }),
      line({ side: 'debit', amount: 500, account_code: '518.002' }),
      line({ side: 'credit', amount: 500, account_code: '321.001' }),
      line({ side: 'credit', amount: 500, account_code: '321.002' }),
    ]

    expect(canPair(lines)).toBe(false)
  })
})
