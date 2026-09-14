import { afterEach, describe, expect, it, vi } from 'vitest'
import { rememberTotpEnrollment, takeTotpEnrollment } from '../totpEnrollment'

afterEach(() => {
  rememberTotpEnrollment(null)
  vi.useRealTimers()
})

describe('TOTP enrollment authorization', () => {
  it('can only be taken once by its user', () => {
    rememberTotpEnrollment('grant', 17)
    expect(takeTotpEnrollment(17)?.token).toBe('grant')
    expect(takeTotpEnrollment(17)).toBeNull()
    rememberTotpEnrollment('grant', 17)
    expect(takeTotpEnrollment(18)).toBeNull()
    expect(takeTotpEnrollment(17)).toBeNull()
  })

  it('expires after five minutes', () => {
    vi.useFakeTimers()
    rememberTotpEnrollment('grant', 17)
    vi.advanceTimersByTime(300_000)
    expect(takeTotpEnrollment(17)).toBeNull()
  })

  it('does not provide authorization without a user or after clearing', () => {
    expect(takeTotpEnrollment()).toBeNull()
    rememberTotpEnrollment('grant', 17)
    rememberTotpEnrollment(null)
    expect(takeTotpEnrollment(17)).toBeNull()
  })
})
