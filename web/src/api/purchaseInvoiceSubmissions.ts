import { api } from './client'

export type PurchaseInvoiceSubmissionStatus =
  | 'submitted'
  | 'processing'
  | 'needs_information'
  | 'processed'
  | 'rejected'

export type PurchaseInvoiceSubmissionKindHint =
  | 'invoice'
  | 'receipt'
  | 'credit_note'
  | 'advance'
  | 'tax_document'
  | 'other'

export interface PurchaseInvoiceSubmission {
  id: number
  supplier_id: number
  document_id: number
  bank_transaction_id: number | null
  supersedes_submission_id: number | null
  replacement_submission_id: number | null
  submitted_by: number | null
  submitted_via: 'portal' | 'document_request' | 'staff'
  note: string | null
  document_kind_hint: PurchaseInvoiceSubmissionKindHint | null
  document_sha256: string
  status: PurchaseInvoiceSubmissionStatus
  status_reason: string | null
  extraction_status: 'not_started' | 'running' | 'succeeded' | 'failed'
  extraction_source: string | null
  extraction_error: string | null
  purchase_invoice_id: number | null
  processed_by: number | null
  processing_started_at: string | null
  processed_at: string | null
  created_at: string
  updated_at: string
  original_name: string
  mime_type: string
  doc_type: 'pdf' | 'image' | 'xml' | 'other'
  size_bytes: number
  thumb_status: string
  submitted_by_name: string | null
  processed_by_name: string | null
  vendor_invoice_number: string | null
  purchase_invoice_varsymbol: string | null
  vendor_name: string | null
  request_count: number
  duplicate?: boolean
}

export interface PurchaseInvoiceSubmissionPage {
  items: PurchaseInvoiceSubmission[]
  total: number
}

export interface SubmissionUploadResult {
  items: PurchaseInvoiceSubmission[]
  created: number
  duplicates: number
  errors: Array<{ filename: string; code: string; message: string }>
}

function uploadForm(
  files: File[],
  note: string,
  documentKindHint: PurchaseInvoiceSubmissionKindHint | null,
): FormData {
  const fd = new FormData()
  for (const file of files) fd.append('file[]', file, file.name)
  if (note.trim()) fd.append('note', note.trim())
  if (documentKindHint) fd.append('document_kind_hint', documentKindHint)
  return fd
}

export const portalPurchaseInvoiceSubmissionsApi = {
  list: (status?: PurchaseInvoiceSubmissionStatus) =>
    api.get<PurchaseInvoiceSubmissionPage>('/portal/purchase-invoice-submissions', {
      params: status ? { status } : undefined,
    }).then(r => r.data),
  upload: (files: File[], note: string, documentKindHint: PurchaseInvoiceSubmissionKindHint | null) =>
    api.post<SubmissionUploadResult>(
      '/portal/purchase-invoice-submissions',
      uploadForm(files, note, documentKindHint),
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data),
  resubmit: (id: number, file: File, note: string) =>
    api.post<SubmissionUploadResult>(
      `/portal/purchase-invoice-submissions/${id}/resubmit`,
      uploadForm([file], note, null),
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data),
  previewUrl: (id: number) => `/api/portal/purchase-invoice-submissions/${id}/preview`,
  downloadUrl: (id: number) => `/api/portal/purchase-invoice-submissions/${id}/download`,
}

export const purchaseInvoiceSubmissionsApi = {
  list: (status?: PurchaseInvoiceSubmissionStatus) =>
    api.get<PurchaseInvoiceSubmissionPage>('/purchase-invoice-submissions', {
      params: status ? { status } : undefined,
    }).then(r => r.data),
  get: (id: number) =>
    api.get<PurchaseInvoiceSubmission>(`/purchase-invoice-submissions/${id}`).then(r => r.data),
  extract: (id: number) =>
    api.post<PurchaseInvoiceSubmission>(`/purchase-invoice-submissions/${id}/extract`, {}).then(r => r.data),
  needsInformation: (id: number, reason: string) =>
    api.post<PurchaseInvoiceSubmission>(`/purchase-invoice-submissions/${id}/needs-information`, { reason }).then(r => r.data),
  reject: (id: number, reason: string) =>
    api.post<PurchaseInvoiceSubmission>(`/purchase-invoice-submissions/${id}/reject`, { reason }).then(r => r.data),
  previewUrl: (id: number) => `/api/purchase-invoice-submissions/${id}/preview`,
  downloadUrl: (id: number) => `/api/purchase-invoice-submissions/${id}/download`,
}
