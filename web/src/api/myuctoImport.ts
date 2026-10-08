import { api } from './client'
import { uploadChunked, type ChunkedUploadProgress } from './chunkedUpload'

const BASE = '/admin/imports/myucto'
export const MYUCTO_IMPORT_MAX_BYTES = 64 * 1024 * 1024

export interface MyuctoImportReport {
  dry_run: boolean
  supplier_id: number
  source_supplier_id: number
  created: Record<string, number>
  reused: Record<string, number>
  existing: Record<string, number>
  outside_scope: Record<string, number>
  files: number
  reconciliation: Record<string, number>
}
export interface MyuctoImportResult {
  token: string
  company_name: string
  ic: string
  report: MyuctoImportReport
}

export const myuctoImportApi = {
  upload: (file: File, onProgress?: ChunkedUploadProgress) => uploadChunked(BASE, file, onProgress),
  async run(token: string, source: string, password: string, apply = false): Promise<MyuctoImportResult> {
    return (await api.post<MyuctoImportResult>(`${BASE}/uploads/${token}/run`, {
      mode: apply ? 'import' : 'dry_run', source, ...(password ? { password } : {}), confirmed: apply,
    })).data
  },
}
