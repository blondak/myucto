import type { PayrollPaymentBatch, PayrollPaymentLiability } from '@/api/payrollPayments'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'

/**
 * Datum úhrady mzdové dávky.
 *
 * `statutory` = „podle splatnosti": datum se neposílá a server použije svoje
 * výchozí (u odvodů o rezervu na převod dřív než zákonný termín). Dávka je
 * pak bajtově stejná jako před zavedením volby. `today` a `custom` posílají
 * konkrétní datum; pozdější než výchozí projde jen s potvrzením.
 */
export type PaymentDateMode = 'statutory' | 'today' | 'custom'

export interface PaymentDateChoice {
  mode: PaymentDateMode
  customDate: string
}

/** Dnešek v místním čase prohlížeče jako ISO `RRRR-MM-DD`. */
export function todayIso(now: Date = new Date()): string {
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

/** Datum, které se pošle serveru; `null` = podle splatnosti. */
export function requestedPaymentDate(
  choice: PaymentDateChoice,
  today: string,
): string | null {
  if (choice.mode === 'today') return today
  if (choice.mode === 'custom') return choice.customDate || null
  return null
}

/** Datum, se kterým příkaz skutečně odejde (pro zobrazení). */
export function effectivePaymentDate(
  choice: PaymentDateChoice,
  defaultDate: string | null,
  today: string,
): string | null {
  return requestedPaymentDate(choice, today) ?? defaultDate
}

export function isLatePaymentDate(
  date: string | null,
  defaultDate: string | null,
): boolean {
  return date !== null && defaultDate !== null && date > defaultDate
}

/**
 * Výchozí datum výběru závazků. Do jedné dávky patří jen závazky se stejnou
 * splatností; čistě odvodová dávka má datum příkazu o rezervu dřív
 * (`payment_on`). Smíšený výběr zůstává na zákonném datu - stejně jako
 * na serveru (PayrollPaymentDatePolicy).
 */
export function selectionDefaultPaymentDate(
  items: PayrollPaymentLiability[],
): string | null {
  if (items.length === 0) return null
  const dates = new Set(items.map(item => item.payment_on ?? item.due_on))
  return dates.size === 1 ? [...dates][0] : items[0].due_on
}

function monthLabel(period: string): string {
  const [year, month] = period.split('-')
  return `${Number(month)}/${year}`
}

/** Mzdové období dávky, např. „9/2026" nebo „8/2026 – 9/2026". */
export function batchPeriodLabel(batch: PayrollPaymentBatch): string | null {
  if (!batch.period_from || !batch.period_to) return null
  const from = monthLabel(batch.period_from)
  const to = monthLabel(batch.period_to)
  return from === to ? from : `${from} – ${to}`
}

/**
 * Lidský popis dávky pro výběry a potvrzení - místo interní reference
 * `payroll-batch:9f85…`, která účetní nic neřekne.
 */
export function batchDescription(
  batch: PayrollPaymentBatch,
  t: (key: string, params?: Record<string, unknown>) => string,
): string {
  const parts = [
    batchPeriodLabel(batch),
    formatDate(batch.planned_payment_date),
    formatMoneyMinor(batch.declared_total_minor, batch.currency_code),
    t('payroll.payments.batch.item_count', { count: batch.declared_item_count }),
  ]
  return parts.filter((part): part is string => !!part).join(' · ')
}
