import { describe, expect, it } from 'vitest'
import { healthPaymentStatus } from '../healthPaymentStatus'

function reconciliation(overrides: Record<string, unknown> = {}) {
  return {
    liability_ids: [1],
    expected_minor: 302_400,
    liability_minor: 302_400,
    liability_difference_minor: 0,
    bank_settled_minor: 0,
    outgoing_remaining_minor: 302_400,
    incoming_remaining_minor: 0,
    bank_remaining_minor: 302_400,
    state: 'open',
    closing_blocked: true,
    blockers: ['bank_unsettled'],
    due_on: '2026-10-20',
    ...overrides,
  } as Parameters<typeof healthPaymentStatus>[0]
}

describe('healthPaymentStatus', () => {
  it('nezaplacené pojistné před splatností jen čeká na úhradu, nic nesouhlasí', () => {
    expect(healthPaymentStatus(reconciliation(), '2026-10-01')).toEqual({
      kind: 'awaiting',
      dueOn: '2026-10-20',
      amountMinor: 302_400,
    })
  })

  it('po splatnosti varuje', () => {
    expect(healthPaymentStatus(reconciliation(), '2026-10-21')?.kind).toBe('overdue')
  })

  it('částečná úhrada nesouhlasí a ukáže doloženo z očekávaného', () => {
    expect(healthPaymentStatus(reconciliation({
      state: 'partially_settled',
      bank_settled_minor: 100_000,
    }), '2026-10-01')).toEqual({
      kind: 'mismatch',
      settledMinor: 100_000,
      expectedMinor: 302_400,
      liabilityDiffers: false,
    })
  })

  it('jiná částka závazku blokuje uzávěrku', () => {
    expect(healthPaymentStatus(reconciliation({
      state: 'mismatch',
      blockers: ['liability_difference', 'bank_unsettled'],
    }), '2026-10-01')).toMatchObject({ kind: 'mismatch', liabilityDiffers: true })
  })

  it('uhrazené', () => {
    expect(healthPaymentStatus(reconciliation({ state: 'settled', due_on: null }), '2026-10-01'))
      .toEqual({ kind: 'settled' })
  })

  it('bez údajů neukáže nic — ani „blokováno"', () => {
    expect(healthPaymentStatus(null, '2026-10-01')).toBeNull()
    expect(healthPaymentStatus(undefined, '2026-10-01')).toBeNull()
    expect(healthPaymentStatus(reconciliation({ state: 'missing', liability_ids: [] }), '2026-10-01')).toBeNull()
    expect(healthPaymentStatus(reconciliation({ due_on: null }), '2026-10-01')).toBeNull()
  })
})
