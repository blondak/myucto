import { api } from './client'

// ── Retenční lhůty účetních a daňových záznamů (§ 31/§ 32 ZoÚ, § 35a ZDPH) ──
// Čistě informativní přehled + zadržení skartace (legal hold). Nic nemaže.

export interface RetentionScheduleRow {
  category: string
  label: string
  years: number
  retain_until: string
  expired: boolean
}

export interface RetentionPeriod {
  year: number
  period_end: string
  retain_until: string
  expired: boolean
  on_hold: boolean
  schedule: RetentionScheduleRow[]
}

export type RetentionHoldReason = 'tax_audit' | 'appeal' | 'litigation' | 'other'

export interface RetentionHold {
  id: number
  period_year: number | null
  reason: RetentionHoldReason
  description: string
  placed_on: string
  released_on: string | null
}

export const retentionApi = {
  overview: () =>
    api.get<{ periods: RetentionPeriod[] }>('/accounting/retention').then(r => r.data.periods),

  holds: (includeReleased = false) =>
    api.get<{ holds: RetentionHold[] }>('/accounting/retention/holds', {
      params: includeReleased ? { include_released: '1' } : {},
    }).then(r => r.data.holds),

  placeHold: (payload: {
    reason: RetentionHoldReason
    description: string
    period_year?: number | null
    placed_on?: string
  }) => api.post<{ id: number }>('/accounting/retention/holds', payload).then(r => r.data),

  releaseHold: (id: number) =>
    api.delete<{ ok: boolean }>(`/accounting/retention/holds/${id}`).then(r => r.data),
}
