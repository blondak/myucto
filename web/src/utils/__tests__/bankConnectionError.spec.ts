import { describe, expect, it } from 'vitest'
import { bankConnectionErrorMessage, bankReconciliationCandidates, bankSyncFallback } from '../bankConnectionError'

describe('bank connection diagnostics', () => {
  it.each([
    ['account_inactive', 'account_inactive'],
    ['csob_soap_fault', 'csob_rejected'],
    ['remote_unavailable', 'transport'],
    ['remote_http_error', 'bank_http'],
    ['invalid_response', 'bank_response'],
    ['certificate_invalid', 'certificate'],
    ['statement_reconciliation_required', 'reconciliation'],
  ])('translates %s without exposing the server response', (code, key) => {
    const error = { response: { data: { error: { code, message: 'synthetic-private-response' } } } }
    expect(bankConnectionErrorMessage(error, value => value, 'fallback')).toBe(`bank_connection.error_${key}`)
  })

  it('uses KB+ specific texts instead of the Fio token hint', () => {
    const error = (code: string) => ({ response: { data: { error: { code } } } })
    expect(bankConnectionErrorMessage(error('invalid_token'), value => value, 'fallback', 'kb_plus')).toBe('bank_connection.error_token_kb_plus')
    expect(bankConnectionErrorMessage(error('history_gap'), value => value, 'fallback', 'kb_plus')).toBe('bank_connection.error_history_kb_plus')
    expect(bankConnectionErrorMessage(error('invalid_token'), value => value, 'fallback', 'fio')).toBe('bank_connection.error_token')
    expect(bankConnectionErrorMessage(error('remote_unavailable'), value => value, 'fallback', 'kb_plus')).toBe('bank_connection.error_transport')
  })

  it('explains the KB codes seen on the connection in production', () => {
    const error = (code: string) => ({ response: { status: 409, data: { error: { code, message: 'synthetic' } } } })
    expect(bankConnectionErrorMessage(error('invalid_token'), value => value, 'fallback', 'kb_plus')).toBe('bank_connection.error_token_kb_plus')
    expect(bankConnectionErrorMessage(error('bank_rate_limited'), value => value, 'fallback', 'kb_plus')).toBe('bank_connection.error_cooldown')
    expect(bankConnectionErrorMessage(error('kb_plus_statement_pending'), value => value, 'fallback', 'kb_plus')).toBe('bank_connection.error_kb_plus_statement_pending')
    expect(bankConnectionErrorMessage(error('bank_sync_failed'), value => value, 'fallback', 'kb_plus')).toBe('bank_connection.error_server')
  })

  it('never shows the raw axios text when the server returned no structured error', () => {
    const iisTimeout = { message: 'Request failed with status code 500', response: { status: 500, data: '<html>IIS 500</html>' } }
    const noResponse = { message: 'timeout of 180000ms exceeded', code: 'ECONNABORTED' }
    const t = (value: string) => value

    expect(bankConnectionErrorMessage(iisTimeout, t, 'fallback', 'kb_plus')).toBe('fallback')
    expect(bankConnectionErrorMessage(noResponse, t, 'fallback')).toBe('fallback')
    expect(bankSyncFallback(iisTimeout, t, 'kb_plus')).toBe('bank_connection.error_server_kb_plus')
    expect(bankSyncFallback(iisTimeout, t, 'fio')).toBe('bank_connection.error_server')
    expect(bankSyncFallback(noResponse, t, 'kb_plus')).toBe('bank_connection.error_network')
  })

  it('keeps the server message of a structured error without a known code', () => {
    const error = { response: { status: 422, data: { error: { code: 'validation_failed', message: 'Neplatná potvrzení shod výpisu.' } } } }
    expect(bankConnectionErrorMessage(error, value => value, 'fallback')).toBe('Neplatná potvrzení shod výpisu.')
  })

  it('accepts only complete reconciliation candidates from the structured error', () => {
    const valid = {
      confirmation_key: 'a'.repeat(64), posted_at: '2026-01-12', amount: '1250.00', currency: 'CZK',
      existing_transaction_id: 901, existing_statement_id: 801,
      description: 'Syntetická platba', existing_description: 'Dřívější syntetická platba',
      counterparty_account: 'synthetic-account-a', existing_counterparty_account: 'synthetic-account-b',
      variable_symbol: '1001', existing_variable_symbol: '1002',
    }
    const error = { response: { data: { error: {
      code: 'statement_reconciliation_required',
      reconciliation_candidates: [valid, { confirmation_key: 'incomplete' }, { ...valid, confirmation_key: 'invalid' }],
    } } } }

    expect(bankReconciliationCandidates(error)).toEqual([valid])
  })
})
