import { api } from './client'

export type CodebookKind = 'accounts' | 'posting-rules' | 'assets'

export interface ImportRow {
  line: number
  key: string
  status: 'create' | 'update' | 'skip' | 'error'
  changes?: Record<string, { from: unknown; to: unknown }>
  message?: string
}

export interface ImportReport {
  ok: boolean
  dry_run: boolean
  created: number
  updated: number
  skipped: number
  failed: number
  rows: ImportRow[]
}

export const codebookTransferApi = {
  // Export MUSÍ jít přes axios (interceptor doplňuje X-Supplier-Id + auth) — prostý
  // <a href> by hlavičky nenesl a server by spadl na jinou firmu multi-supplier uživatele.
  download: async (kind: CodebookKind) => {
    const r = await api.get(`/accounting/${kind}/export`, { responseType: 'blob' })
    const cd = String(r.headers?.['content-disposition'] ?? '')
    const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd)
    const filename = m ? decodeURIComponent(m[1]) : `${kind}.xlsx`
    const url = URL.createObjectURL(r.data as unknown as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)
  },
  import: (kind: CodebookKind, file: File, dryRun: boolean) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('dry_run', dryRun ? '1' : '0')
    return api.post<ImportReport>(`/accounting/${kind}/import`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
}
