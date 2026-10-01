import type { PayrollHealthPaymentOverview } from '@/api/payroll'

type Reconciliation = PayrollHealthPaymentOverview['payment_reconciliation'] | null | undefined

/**
 * Stav úhrady pojistného na kartě pojišťovny.
 *
 * Dřív karta znala jen „uzávěrka blokována" a „uhrazeno" — a nezaplacené
 * pojistné před splatností tak svítilo červeně jako chyba, ačkoliv se jen
 * čeká na platbu. Pět stavů:
 *   - `awaiting`  nezaplaceno, splatnost ještě neuplynula (neutrální),
 *   - `overdue`   nezaplaceno po splatnosti (varování),
 *   - `mismatch`  zaplaceno jen zčásti nebo závazek nesouhlasí s přehledem,
 *   - `settled`   uhrazeno,
 *   - `null`      údaje chybí (závazek ještě nevznikl) — nic se neukazuje.
 */
export type HealthPaymentStatus =
  | { kind: 'awaiting'; dueOn: string; amountMinor: number }
  | { kind: 'overdue'; dueOn: string; amountMinor: number }
  | { kind: 'mismatch'; settledMinor: number; expectedMinor: number; liabilityDiffers: boolean }
  | { kind: 'settled' }
  | null

export function healthPaymentStatus(reconciliation: Reconciliation, today: string): HealthPaymentStatus {
  if (!reconciliation) return null
  switch (reconciliation.state) {
    case 'settled':
      return { kind: 'settled' }
    case 'mismatch':
    case 'partially_settled':
      return {
        kind: 'mismatch',
        settledMinor: reconciliation.bank_settled_minor,
        expectedMinor: reconciliation.expected_minor,
        liabilityDiffers: reconciliation.blockers.includes('liability_difference'),
      }
    case 'open': {
      const dueOn = reconciliation.due_on ?? null
      if (dueOn === null) return null
      const amountMinor = reconciliation.bank_remaining_minor
      return dueOn < today
        ? { kind: 'overdue', dueOn, amountMinor }
        : { kind: 'awaiting', dueOn, amountMinor }
    }
    default:
      return null
  }
}

/** Dnešní den `RRRR-MM-DD` v místním čase (splatnost je kalendářní den). */
export function localToday(date = new Date()): string {
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}
