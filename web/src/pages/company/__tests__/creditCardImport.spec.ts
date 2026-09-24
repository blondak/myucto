import { beforeEach, describe, expect, it, vi } from 'vitest'
import { importCreditCardStatement } from '../creditCardImport'

const m = vi.hoisted(() => ({
  importStatement: vi.fn(),
  convert: vi.fn(),
}))
vi.mock('@/api/creditCards', () => ({
  creditCardsApi: { importStatement: m.importStatement, convert: m.convert },
}))

/** Chyba importu ve tvaru, jaký vrací API (Json::error + context). */
function apiError(code: string, extra: Record<string, unknown> = {}) {
  return { response: { data: { error: { code, message: `Chyba ${code}`, ...extra } } } }
}

const file = new File(['%PDF-1.4 synthetic'], 'vypis.pdf', { type: 'application/pdf' })
const ok = { statement_id: 5, transactions: 3, matched: 0, duplicate: false, credit_card_account_id: 9 }

describe('importCreditCardStatement', () => {
  beforeEach(() => {
    m.importStatement.mockReset()
    m.convert.mockReset()
  })

  it('imports the statement directly when the account is a credit card', async () => {
    m.importStatement.mockResolvedValueOnce(ok)

    const r = await importCreditCardStatement(file, null, () => true)

    expect(r).toEqual({ result: ok, reposted: null })
    expect(m.convert).not.toHaveBeenCalled()
  })

  it('offers conversion of a current account with history and imports again after it', async () => {
    m.importStatement
      .mockRejectedValueOnce(apiError('account_is_bank_account', { bank_account_id: 42 }))
      .mockResolvedValueOnce(ok)
    m.convert.mockResolvedValueOnce({ credit_card_account_id: 9, reposted: 4 })
    const confirm = vi.fn(() => true)

    const r = await importCreditCardStatement(file, 9, confirm)

    expect(confirm).toHaveBeenCalledWith('Chyba account_is_bank_account')
    expect(m.convert).toHaveBeenCalledWith(42)
    expect(m.importStatement).toHaveBeenLastCalledWith(file, 9)
    expect(r.reposted).toBe(4)
  })

  it('does not convert when the user declines', async () => {
    m.importStatement.mockRejectedValueOnce(apiError('account_is_bank_account', { bank_account_id: 42 }))

    await expect(importCreditCardStatement(file, null, () => false)).rejects.toBeTruthy()
    expect(m.convert).not.toHaveBeenCalled()
  })

  it('passes other errors through without asking', async () => {
    m.importStatement.mockRejectedValueOnce(apiError('account_foreign'))
    const confirm = vi.fn(() => true)

    await expect(importCreditCardStatement(file, null, confirm)).rejects.toBeTruthy()
    expect(confirm).not.toHaveBeenCalled()
    expect(m.convert).not.toHaveBeenCalled()
  })
})
