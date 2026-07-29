import { api } from './client'

/**
 * Featura H (REAL_data_followup_UX.md) — jednotná READ-ONLY fronta „čeká na ruční
 * zaúčtování" napříč bankou, přijatými/vydanými fakturami a vyžádanými doklady.
 * Nic zde neúčtuje — jen agreguje existující stavy s důvodem a odkazem na řešení.
 * Čekající návrhy kontace sem nepatří (schvalují se v Automatu) — viz ManualPostingQueueService.
 */
export type ManualQueueItemType = 'bank_no_suggestion' | 'purchase_invoice' | 'sales_invoice' | 'document_request'

export interface ManualQueueLink {
  route: string
  params?: Record<string, string | number>
  query?: Record<string, string | number>
}

export interface ManualQueueRefs {
  suggestion_id: number | null
  bank_transaction_id: number | null
  statement_id: number | null
  purchase_invoice_id: number | null
  invoice_id: number | null
  document_request_id: number | null
}

export interface ManualQueueItem {
  id: string
  type: ManualQueueItemType
  date: string
  amount: number | null
  currency: string | null
  counterparty: string | null
  description: string | null
  reason: string
  /** Dynamický text (jen document_request — vlastní popis požadavku); jinak null, řeš přes i18n dle `reason`. */
  reason_detail: string | null
  suggested_action: string
  link: ManualQueueLink
  deadline?: string | null
  refs: ManualQueueRefs
}

export interface ManualQueueResult {
  items: ManualQueueItem[]
  total: number
  page: number
  per_page: number
  counts: { by_type: Record<ManualQueueItemType, number>; by_reason: Record<string, number> }
}

export interface ManualQueueFilters {
  type?: ManualQueueItemType
  reason?: string
  page?: number
  per_page?: number
}

export const manualPostingQueueApi = {
  list: (filters: ManualQueueFilters = {}) =>
    api.get<ManualQueueResult>('/accounting/manual-posting-queue', { params: filters }).then(r => r.data),
}
