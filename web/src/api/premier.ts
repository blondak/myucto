import { api } from './client'
import { uploadChunked, type ChunkedUploadProgress } from './chunkedUpload'
import type { MoneyS3RunStatus, MoneyS3Step } from './moneyS3'

/**
 * Průvodce „Přechod z PREMIER": záloha dat (iZIP/iCAB) vytvořená přímo v programu
 * (Správce → Záloha dat, F11), náhled agend a volba roku, zkouška nanečisto a ostrý
 * převod na pozadí. Záloha obsahuje všechny roky, převádí se ale vždy jeden — od
 * nejstaršího, protože počáteční stavy roku vycházejí z let předchozích. Stav
 * běžícího převodu se čte přes společné `/admin/imports/{id}` (fetchImportJob
 * v api/imports.ts). Na rozdíl od POHODY tu není exportní nástroj (záloha se dělá
 * přímo v PREMIERu) ani druh převodu (mzdy PREMIER nevede v téže záloze).
 */

export interface PremierAgenda {
  dir: string
  ico: string
  dic: string
  company: string
  year: number
  /** Počet zápisů deníku agendy roku — PREMIER na rozdíl od POHODY nevrací rozpad po dokladech. */
  entries: number
  has_accounting: boolean
  has_payroll: boolean
}

export interface PremierMessage {
  level: 'error' | 'warning' | 'info'
  code: string
  message: string
  context?: Record<string, unknown>
}

export interface PremierUpload {
  token: string
  status: 'ready'
  file_name: string
  sha256: string
  uploaded_at: string
  uploaded_by: number
  supplier_ico: string
  default_year: number | null
  agendas: PremierAgenda[]
  /** Kontrola před převodem po letech (klíč = rok), jen pro agendy s IČO firmy. */
  preflight: Record<string, PremierMessage[]>
}

/** Záloha se ještě nahrává po částech nebo ji server na pozadí rozbaluje a čte. */
export interface PremierUploadPending {
  token: string
  status: 'uploading' | 'processing' | 'failed'
  file_name: string
  size: number
  received: number
  job_id: number | null
  error: string | null
}

export function isPremierUploadReady(upload: PremierUpload | PremierUploadPending): upload is PremierUpload {
  return 'agendas' in upload
}

export interface PremierReconciliationYear {
  year: number
  period_id: number
  ok: boolean
  checks: { key: string; ok: boolean }[]
  journal_diffs: import('./moneyS3').MoneyS3Diff[]
  documents: { key: string; documents: number; journal: number; ok: boolean; other_accounts?: unknown }[]
  unmapped_accounts?: unknown
}

export interface PremierProtocolData {
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  failure: string | null
  error?: string
  steps: MoneyS3Step[]
  agenda?: { ico: string; dic: string; company: string; year: number; dir: string }
  preflight?: PremierMessage[]
  reconciliation?: PremierReconciliationYear[]
  orphans?: { type: 'purchase_invoice' | 'invoice' | 'cash' | 'bank'; document_no: string; id: number }[]
  automation?: { before?: string | null; during: string; restored: boolean; after: string | null }
}

export interface PremierRun {
  id: number
  job_id: number | null
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  agenda_ico: string | null
  agenda_year: number | null
  program_version: string | null
  created_at: string
  finished_at: string | null
  protocol?: PremierProtocolData | null
}

export interface PremierStartParams {
  mode: 'dry_run' | 'import'
  year: number
}

export const PREMIER_BASE = '/admin/imports/premier'

export const premierApi = {
  uploadChunked: (file: File, onProgress?: ChunkedUploadProgress, onStarted?: (token: string) => void) =>
    uploadChunked(PREMIER_BASE, file, onProgress, onStarted),
  show: (token: string): Promise<PremierUpload | PremierUploadPending> =>
    api.get<PremierUpload | PremierUploadPending>(`${PREMIER_BASE}/uploads/${token}`).then(r => r.data),
  start: (token: string, params: PremierStartParams): Promise<{ job_id: number; status: string; mode: string }> =>
    api.post(`${PREMIER_BASE}/uploads/${token}/start`, params).then(r => r.data),
  runs: (): Promise<{ items: PremierRun[] }> =>
    api.get<{ items: PremierRun[] }>(`${PREMIER_BASE}/runs`).then(r => r.data),
  run: (id: number): Promise<PremierRun> =>
    api.get<PremierRun>(`${PREMIER_BASE}/runs/${id}`).then(r => r.data),
}
