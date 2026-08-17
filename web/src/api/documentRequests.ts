import { api } from './client'

export type DocumentRequestStatus = 'requested' | 'uploaded' | 'resolved'

export interface DocumentRequest {
  id: number
  supplier_id: number
  description: string
  amount: number | null
  context_date: string | null
  status: DocumentRequestStatus
  deadline: string | null
  bank_transaction_id: number | null
  purchase_invoice_id: number | null
  submission_id: number | null
  created_by: number | null
  resolved_by: number | null
  resolved_at: string | null
  last_reminder_at: string | null
  reminder_count: number
  created_at: string
  updated_at: string
  // Doplněná pole z JOIN (jen v listForSupplier/find, ne po create/upload response těla)
  pi_vendor_invoice_number?: string | null
  pi_status?: string | null
  pi_vendor_name?: string | null
  bank_tx_amount?: number | null
  bank_tx_posted_at?: string | null
  created_by_name?: string | null
  resolved_by_name?: string | null
  submission_status?: 'submitted' | 'processing' | 'needs_information' | 'processed' | 'rejected' | null
  submission_status_reason?: string | null
  submission_original_name?: string | null
}

export interface DocumentRequestCreatePayload {
  description: string
  amount?: number | null
  context_date?: string | null
  deadline?: string | null
}

/** Účetní pohled — CRUD nad document_requests (RBAC: accountant|admin, readonly GET). */
export const documentRequestsApi = {
  list: (status?: DocumentRequestStatus | DocumentRequestStatus[]) => {
    const q = status ? (Array.isArray(status) ? status.join(',') : status) : undefined
    return api.get<{ items: DocumentRequest[] }>('/document-requests', { params: q ? { status: q } : undefined })
      .then(r => r.data.items)
  },
  get: (id: number) => api.get<DocumentRequest>(`/document-requests/${id}`).then(r => r.data),
  create: (payload: DocumentRequestCreatePayload) =>
    api.post<DocumentRequest>('/document-requests', payload).then(r => r.data),
  resolve: (id: number) => api.post<DocumentRequest>(`/document-requests/${id}/resolve`).then(r => r.data),
  reopen: (id: number) => api.post<DocumentRequest>(`/document-requests/${id}/reopen`).then(r => r.data),
  delete: (id: number) => api.delete<{ deleted: true }>(`/document-requests/${id}`).then(r => r.data),
  /** Jedním klikem z nespárované bankovní transakce (StatementDetail.vue). */
  createFromBankTransaction: (txId: number, payload?: { description?: string; deadline?: string }) =>
    api.post<DocumentRequest>(`/bank-transactions/${txId}/document-request`, payload ?? {}).then(r => r.data),
}

/** Klientský portál — vlastní požadavky + předání originálu do účetní fronty. */
export const portalDocumentRequestsApi = {
  list: () => api.get<{ items: DocumentRequest[] }>('/portal/document-requests').then(r => r.data.items),
  upload: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post<DocumentRequest>(`/portal/document-requests/${id}/upload`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
}
