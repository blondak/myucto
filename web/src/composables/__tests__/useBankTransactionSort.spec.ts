import { describe, expect, it } from 'vitest'
import { useBankTransactionSort } from '@/composables/useBankTransactionSort'

describe('useBankTransactionSort', () => {
  it('klik na hlavičku cykluje vzestupně → sestupně → výchozí pořadí', () => {
    const s = useBankTransactionSort(['posted_at', 'amount'])
    expect(s.params.value).toEqual({})

    s.toggle('amount')
    expect(s.params.value).toEqual({ sort: 'amount', direction: 'asc' })
    s.toggle('amount')
    expect(s.params.value).toEqual({ sort: 'amount', direction: 'desc' })
    s.toggle('amount')
    expect(s.params.value).toEqual({})
  })

  it('jiný sloupec začíná vzestupně', () => {
    const s = useBankTransactionSort(['posted_at', 'amount'])
    s.toggle('amount')
    s.toggle('amount')
    s.toggle('posted_at')
    expect(s.sort.value).toEqual({ key: 'posted_at', dir: 'asc' })
  })

  it('sloupec mimo seznam stránky ignoruje (neposílá ho serveru)', () => {
    const s = useBankTransactionSort(['posted_at'])
    s.toggle('account')
    expect(s.params.value).toEqual({})
  })

  it('výběr na mobilu nastaví sloupec i směr, prázdná hodnota vrátí výchozí', () => {
    const s = useBankTransactionSort(['posted_at', 'counterparty'])
    s.selectValue.value = 'counterparty:desc'
    expect(s.params.value).toEqual({ sort: 'counterparty', direction: 'desc' })
    expect(s.selectValue.value).toBe('counterparty:desc')

    s.selectValue.value = 'amount:asc'
    expect(s.params.value).toEqual({})

    s.selectValue.value = 'counterparty:asc'
    s.selectValue.value = ''
    expect(s.sort.value).toBeNull()
  })
})
