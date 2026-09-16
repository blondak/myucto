import { api } from './client'
import { uploadChunked as uploadChunkedTo, type ChunkedUploadProgress } from './chunkedUpload'

export { retryDelay } from './chunkedUpload'

/**
 * Průvodce „Přechod z Money S3" — záloha agendy, náhled, sestavy z Money ke kontrole,
 * zkouška nanečisto a ostrý převod na pozadí. Stav běžícího převodu se čte přes
 * společné `/admin/imports/{id}` (fetchImportJob v api/imports.ts).
 */

export interface MoneyS3YearInfo {
  dir: string
  fiscal_year: number | null
  journal_rows: number
  opening_rows: number
  first_date: string | null
  last_date: string | null
  purchase_invoices: number
  issued_invoices: number
  cash_documents: number
  bank_documents: number
}

export interface MoneyS3Agenda {
  name: string
  ico: string
  dic: string
  street: string
  city: string
  zip: string
  version: string
  version_verified: boolean
  backup_at: string
  years: MoneyS3YearInfo[]
  partners: number
  warnings: { code: string; message: string }[]
}

export interface MoneyS3Message {
  level: 'error' | 'warning' | 'info'
  code: string
  message: string
  context?: Record<string, unknown>
}

export interface MoneyS3Upload {
  token: string
  file_name: string
  sha256: string
  uploaded_at: string
  uploaded_by: number
  agenda: MoneyS3Agenda
  preflight: MoneyS3Message[]
  reports: number[]
}

/** Záloha se ještě nahrává po částech nebo ji server na pozadí rozbaluje a čte. */
export interface MoneyS3UploadPending {
  token: string
  status: 'uploading' | 'processing' | 'failed'
  file_name: string
  size: number
  received: number
  job_id: number | null
  error: string | null
}

export function isUploadReady(upload: MoneyS3Upload | MoneyS3UploadPending): upload is MoneyS3Upload {
  return 'agenda' in upload
}

export type MoneyS3Triple = [number, number, number]

export interface MoneyS3Diff {
  account: string
  myucto: MoneyS3Triple
  money: MoneyS3Triple
}

export interface MoneyS3Step {
  key: string
  status: 'ok' | 'warning' | 'error' | 'running'
  counts: Record<string, number>
  messages: { level: 'error' | 'warning' | 'info'; code: string; text: string; context: Record<string, unknown> }[]
}

export interface MoneyS3ReconciliationYear {
  year: number
  period_id: number
  ok: boolean
  checks: { key: string; ok: boolean }[]
  journal_diffs: MoneyS3Diff[]
  money_report: { accounts: number; skipped_lines: number; diffs: MoneyS3Diff[] } | null
  documents: { key: string; documents: number; journal: number; ok: boolean }[]
}

export interface MoneyS3ClosingYear {
  year: number
  status: string
  accounts?: number
  diffs?: { account_code: string; expected: number; existing: number; difference: number }[]
  error?: string
}

export interface MoneyS3ProtocolData {
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  failure: string | null
  error?: string
  steps: MoneyS3Step[]
  agenda?: MoneyS3Agenda
  reconciliation?: MoneyS3ReconciliationYear[]
  closing?: MoneyS3ClosingYear[]
  orphans?: { type: string; year: number; document_no: string; id: number }[]
  automation?: { during: string; restored: boolean; after: string | null }
}

export type MoneyS3RunStatus = 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'cancelled'

export interface MoneyS3Run {
  id: number
  job_id: number | null
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  agenda_ico: string | null
  agenda_name: string | null
  money_version: string | null
  created_at: string
  finished_at: string | null
  protocol?: MoneyS3ProtocolData | null
}

export interface MoneyS3StartParams {
  mode: 'dry_run' | 'import'
  close_history: boolean
  first_period_start: string | null
  /** Záloha patří firmě, i když to IČO ověřit nejde (chybí v záloze nebo ve firmě). */
  confirm_ico?: boolean
  /** První převáděný účetní rok; starší roky zálohy se vynechají. */
  from_year?: number | null
}

const BASE = '/admin/imports/money-s3'

/**
 * Záloha agendy má stovky megabajtů až gigabajty, posílá se proto po částech
 * (sdílený helper v chunkedUpload.ts). Po poslední části server zálohu zpracuje
 * na pozadí (stav přes `show`).
 */
function uploadChunked(
  file: File,
  onProgress?: ChunkedUploadProgress,
  onStarted?: (token: string) => void,
): Promise<{ token: string; job_id: number | null }> {
  return uploadChunkedTo(BASE, file, onProgress, onStarted)
}

export const moneyS3Api = {
  upload: (file: File): Promise<MoneyS3Upload> => {
    const fd = new FormData()
    fd.append('backup', file, file.name)
    return api.post<MoneyS3Upload>(`${BASE}/uploads`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data)
  },
  uploadChunked,
  show: (token: string): Promise<MoneyS3Upload | MoneyS3UploadPending> =>
    api.get<MoneyS3Upload | MoneyS3UploadPending>(`${BASE}/uploads/${token}`).then(r => r.data),
  attachReport: (token: string, year: number, file: File): Promise<{ year: number; accounts: number; skipped_lines: number }> => {
    const fd = new FormData()
    fd.append('year', String(year))
    fd.append('report', file, file.name)
    return api.post(`${BASE}/uploads/${token}/reports`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data)
  },
  start: (token: string, params: MoneyS3StartParams): Promise<{ job_id: number; status: string; mode: string }> =>
    api.post(`${BASE}/uploads/${token}/start`, params).then(r => r.data),
  runs: (): Promise<{ items: MoneyS3Run[] }> =>
    api.get<{ items: MoneyS3Run[] }>(`${BASE}/runs`).then(r => r.data),
  run: (id: number): Promise<MoneyS3Run> =>
    api.get<MoneyS3Run>(`${BASE}/runs/${id}`).then(r => r.data),
}
