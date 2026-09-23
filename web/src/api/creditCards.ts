import { api } from './client'

export type CreditCardIssuer = 'kb' | 'rb' | 'csob' | 'erste' | 'other'
export type CreditCardTransactionKind = 'purchase' | 'refund' | 'repayment' | 'interest' | 'fee' | 'cash' | 'reward'

export const CREDIT_CARD_KINDS: CreditCardTransactionKind[] = ['purchase', 'refund', 'repayment', 'interest', 'fee', 'cash', 'reward']

export interface CreditCardStatement {
  id: number
  statement_date: string
  statement_number: string
  prev_balance: number
  curr_balance: number
  credit_total: number
  debit_total: number
  transaction_count: number
  file_name: string
  has_pdf: boolean
}

export interface CreditCardTransaction {
  id: number
  statement_id: number
  posted_at: string
  amount: number
  currency: string
  description: string | null
  counterparty_name: string | null
  counterparty_account: string | null
  card_last4: string | null
  kind: CreditCardTransactionKind
  match_status: string
  /** Živý bankovní zápis pohybu (zaúčtováno), jinak null. */
  entry_id: number | null
  /** Otevřený návrh zaúčtování ve frontě, jinak null. */
  suggestion_id: number | null
}

/** Úvěrový účet kreditní karty (účtuje se na 231.xxx). */
export interface CreditCardAccount {
  id: number
  bank_account_id: number
  issuer: CreditCardIssuer
  label: string
  account_number: string
  bank_code: string | null
  currency: string
  credit_limit: number | null
  analytic_suffix: string | null
  repayment_account: string | null
  repayment_bank_code: string | null
  repayment_vs: string | null
  /** false = účet založil import výpisu; čeká na kontrolu. */
  is_verified: boolean
  archived: boolean
  archived_at: string | null
  note: string | null
  created_at: string
  /** Kód analytiky 231.xxx, null = zatím nepřidělená. */
  account_code: string | null
  /** Zůstatek analytiky v účetnictví (MD − D; dluh je záporný jako na výpisu). */
  ledger_balance: number | null
  last_statement: CreditCardStatement | null
  statement_count: number
  unposted_count: number
  /** Účetnictví k datu posledního výpisu − konečný zůstatek výpisu. */
  balance_difference: number | null
}

export interface CreditCardDetail extends CreditCardAccount {
  statements: CreditCardStatement[]
  transactions: CreditCardTransaction[]
  analytic_options: Array<{ id: number; account_code: string; name: string; credit_card_account_id: number | null }>
}

export interface CreditCardPayload {
  label: string
  credit_limit: number | null
  repayment_account: string | null
  repayment_bank_code: string | null
  repayment_vs: string | null
  note: string | null
}

export type CreditCardSettingsField = 'interest' | 'fee' | 'repayment' | 'cash' | 'reward'
export const CREDIT_CARD_SETTINGS_FIELDS: CreditCardSettingsField[] = ['interest', 'fee', 'repayment', 'cash', 'reward']

export interface CreditCardSettings {
  configured: boolean
  interest_account_id: number | null
  fee_account_id: number | null
  repayment_account_id: number | null
  cash_account_id: number | null
  reward_account_id: number | null
  interest_account_code: string
  fee_account_code: string
  repayment_account_code: string
  cash_account_code: string
  reward_account_code: string
}

export interface CreditCardSettingsResponse {
  double_entry: boolean
  settings: CreditCardSettings
  defaults: Record<CreditCardSettingsField, string>
  allowed_prefixes: Record<CreditCardSettingsField, string[]>
  account_options: Array<{ id: number; account_code: string; name: string; is_synthetic: boolean }>
}

export interface CreditCardImportResult {
  statement_id: number
  transactions: number
  matched: number
  duplicate: boolean
  credit_card_account_id: number | null
  skipped_duplicates?: number
}

export const creditCardsApi = {
  list: (includeArchived = false) =>
    api.get<{ accounts: CreditCardAccount[] }>('/credit-cards', { params: includeArchived ? { include_archived: 1 } : {} })
      .then(r => r.data.accounts),
  get: (id: number) => api.get<CreditCardDetail>(`/credit-cards/${id}`).then(r => r.data),
  update: (id: number, payload: CreditCardPayload) => api.put<CreditCardDetail>(`/credit-cards/${id}`, payload).then(r => r.data),
  setAnalytic: (id: number, accountCode: string) =>
    api.put<CreditCardDetail>(`/credit-cards/${id}/analytic`, { account_code: accountCode }).then(r => r.data),
  archive: (id: number) => api.post<CreditCardDetail>(`/credit-cards/${id}/archive`).then(r => r.data),
  restore: (id: number) => api.post<CreditCardDetail>(`/credit-cards/${id}/restore`).then(r => r.data),
  importStatement: (file: File, creditCardAccountId: number | null = null) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    if (creditCardAccountId !== null) fd.append('credit_card_account_id', String(creditCardAccountId))
    return api.post<CreditCardImportResult>('/credit-cards/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000,
    }).then(r => r.data)
  },
  convert: (bankAccountId: number) =>
    api.post<{ credit_card_account_id: number; reposted: number }>('/credit-cards/convert', { bank_account_id: bankAccountId })
      .then(r => r.data),
  settings: () => api.get<CreditCardSettingsResponse>('/credit-cards/settings').then(r => r.data),
  saveSettings: (payload: Partial<Record<`${CreditCardSettingsField}_account_id`, number | null>>) =>
    api.put<CreditCardSettingsResponse>('/credit-cards/settings', payload).then(r => r.data),
}
