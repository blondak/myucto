/** Zámek dokladu — počítá VÝHRADNĚ backend. FE nikdy neodvozuje ze status/booked_at. */
export type LockReason = 'posted' | 'booked' | 'period_closed' | 'period_closing'

export interface DocumentLock {
  is_locked: boolean
  reasons: LockReason[]
  booked_at: string | null
  journal_entry_id: number | null
  period_status: string | null
}
