import type { EpoAttempt } from '@/api/epoSubmissions'

type AttemptState = Pick<EpoAttempt, 'channel' | 'epo_environment' | 'status'>

export function hasUnresolvedProductionDirectAttempt(attempts: AttemptState[]): boolean {
  return attempts.some(attempt =>
    attempt.channel === 'epo_direct'
    && attempt.epo_environment === 'production'
    && ['submitting', 'processing', 'uncertain', 'confirmed'].includes(attempt.status),
  )
}
