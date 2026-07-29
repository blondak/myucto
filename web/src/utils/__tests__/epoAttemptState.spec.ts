import { describe, expect, it } from 'vitest'
import { hasUnresolvedProductionDirectAttempt } from '../epoAttemptState'

describe('hasUnresolvedProductionDirectAttempt', () => {
  it('does not block assisted handoff for an unresolved sandbox attempt', () => {
    expect(hasUnresolvedProductionDirectAttempt([{
      channel: 'epo_direct',
      epo_environment: 'test',
      status: 'processing',
    }])).toBe(false)
  })

  it.each(['submitting', 'processing', 'uncertain', 'confirmed'] as const)(
    'blocks assisted handoff for a production %s attempt',
    (status) => {
      expect(hasUnresolvedProductionDirectAttempt([{
        channel: 'epo_direct',
        epo_environment: 'production',
        status,
      }])).toBe(true)
    },
  )

  it('ignores finished or assisted attempts', () => {
    expect(hasUnresolvedProductionDirectAttempt([
      {
        channel: 'epo_direct',
        epo_environment: 'production',
        status: 'test_failed',
      },
      {
        channel: 'epo_assisted',
        epo_environment: 'production',
        status: 'awaiting_confirmation',
      },
    ])).toBe(false)
  })
})
