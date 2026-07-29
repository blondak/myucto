import type { MatchSuggestionCandidate } from '@/api/bank'

/** Signály/flagy „match v2" zobrazované v UI — zbytek (interní) se skrývá. */
const MATCH_SIGNAL_KEYS = new Set([
  'vs_exact', 'amount_remaining', 'subset_sum', 'invoice_no_in_message',
  'known_account', 'vs_typo', 'name_fuzzy', 'due_proximity',
])
const MATCH_FLAG_KEYS = new Set([
  'overpayment', 'fee_gap', 'vs_typo', 'currency_mismatch', 'proforma',
])

export function candidateSignals(candidate: MatchSuggestionCandidate): string[] {
  return Object.keys(candidate.signals).filter(signal => MATCH_SIGNAL_KEYS.has(signal))
}

export function candidateFlags(candidate: MatchSuggestionCandidate): string[] {
  return candidate.flags.filter(flag => MATCH_FLAG_KEYS.has(flag))
}
