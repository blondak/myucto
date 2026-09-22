import { api } from './client'
import { uploadChunked, type ChunkedUploadProgress } from './chunkedUpload'
import type { MoneyS3ProtocolData, MoneyS3RunStatus } from './moneyS3'

const BASE = '/admin/imports/stereo-nx'

export interface StereoCompany {
  index: number
  label: string
  identity: { ico: string; dic: string; name: string; vat_payer: boolean }
  matches_target: boolean
}

export interface StereoPreview {
  companies: StereoCompany[]
  target: { ico: string; accounting_mode: string; vat_payer: boolean }
}

export interface StereoUpload {
  token: string
  filename: string
  size: number
  received: number
  complete: boolean
  created_at: number
}

export interface StereoReport {
  ok: boolean
  preflight?: Array<{ level: string; code: string; message: string }>
  errors?: Array<string | { level: string; code: string; message: string }>
  warnings?: Array<string | { level: string; code: string; message: string }>
  counts?: Record<string, number>
  written?: Record<string, number>
  review_reasons?: Record<string, number>
  date_bounds?: { from: string; to: string }
  review_documents?: Array<{
    kind: 'issued' | 'purchase'
    source_key: string
    document_no: string
    review_codes: string[]
    target_id: number | null
  }>
  [key: string]: unknown
}

/** Klientský pohled na synchronní výsledek; nemá serverové ID běhu ani čas vytvoření. */
export interface StereoProtocolRun {
  id: null
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  agenda_ico: string | null
  agenda_name: string | null
  agenda_year: null
  created_at: null
  protocol: MoneyS3ProtocolData
}

export const stereoNxApi = {
  uploads: async (): Promise<StereoUpload[]> =>
    (await api.get<{ uploads: StereoUpload[] }>(`${BASE}/uploads`)).data.uploads,
  upload: (file: File, onProgress?: ChunkedUploadProgress, onStarted?: (token: string) => void) =>
    uploadChunked(BASE, file, onProgress, onStarted),
  preview: async (token: string): Promise<StereoPreview> =>
    (await api.post<StereoPreview>(`${BASE}/uploads/${token}/preview`, {})).data,
  run: async (token: string, company: number, mode: 'dry_run' | 'import', blankCountryIsCz: boolean): Promise<StereoReport> =>
    (await api.post<{ report: StereoReport }>(`${BASE}/uploads/${token}/run`, { company, mode, blank_country_is_cz: blankCountryIsCz })).data.report,
  remove: async (token: string): Promise<void> => { await api.delete(`${BASE}/uploads/${token}`) },
}
