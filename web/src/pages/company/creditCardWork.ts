import type { RouteLocationRaw } from 'vue-router'
import {
  CREDIT_CARD_TODO_STATES,
  type CreditCardOpening,
  type CreditCardTodoRow,
  type CreditCardTodoState,
  type CreditCardTransactionState,
} from '@/api/creditCards'

/**
 * Pohyby kreditní karty se zpracovávají ve výpisu (/bank/{id}) - tam jsou všechny akce
 * bankovního pohybu. Odkaz s `tx` výpis otevře rovnou na pohybu (zvýrazní ho a posune do
 * zorného pole, viz StatementDetail `highlightLinkedTx`).
 */
export function statementLink(statementId: number, txId?: number | null): RouteLocationRaw {
  return txId
    ? { name: 'bank-detail', params: { id: statementId }, query: { tx: String(txId) } }
    : { name: 'bank-detail', params: { id: statementId } }
}

export const STATE_CLASS: Record<CreditCardTransactionState, string> = {
  unposted: 'bg-warning-50 text-warning-700 ring-warning-600/20',
  suggested: 'bg-primary-50 text-primary-700 ring-primary-600/20',
  clearing_open: 'bg-warning-50 text-warning-700 ring-warning-600/20',
  settled: 'bg-success-50 text-success-700 ring-success-600/20',
  posted: 'bg-success-50 text-success-700 ring-success-600/20',
  ignored: 'bg-neutral-100 text-neutral-600 ring-neutral-500/20',
}

export function isTodo(state: CreditCardTransactionState): state is CreditCardTodoState {
  return (CREDIT_CARD_TODO_STATES as readonly string[]).includes(state)
}

/** Nenulové řádky souhrnu „co zbývá dořešit" v pevném pořadí. */
export function todoRows(todo: Record<CreditCardTodoState, CreditCardTodoRow> | null | undefined): Array<CreditCardTodoRow & { state: CreditCardTodoState }> {
  if (!todo) return []
  return CREDIT_CARD_TODO_STATES
    .map(state => ({ state, ...todo[state] }))
    .filter(r => r.count > 0)
}

/**
 * Zápis počátečního dluhu, jak ho uloží server: dluh (záporný zůstatek výpisu) = D 231.x
 * a MD protiúčet, přeplatek obráceně.
 */
export function openingLines(opening: CreditCardOpening, contraCode: string): Array<{ account_code: string; side: 'debit' | 'credit'; amount: number }> {
  if (!opening.needed || !opening.account_code) return []
  const amount = Math.round(Math.abs(opening.amount) * 100) / 100
  const debt = opening.amount < 0
  return [
    { account_code: debt ? contraCode : opening.account_code, side: 'debit', amount },
    { account_code: debt ? opening.account_code : contraCode, side: 'credit', amount },
  ]
}
