import { api } from './client'

/** Kontrola souběhu se starým účetním programem (K1, K5–K13). */

export type ParallelRunInputKind =
  | 'trial_balance' | 'document_counts' | 'saldo' | 'bank_balances' | 'vat_return'
  | 'control_statement' | 'assets' | 'cost_centers' | 'balance_sheet' | 'income_statement'

export type ParallelRunCategory = 'source' | 'migration' | 'interpretation'
export type ParallelRunStatus = 'ok' | 'differences' | 'incomplete'
export type ParallelRunCriterionStatus = 'ok' | 'differences' | 'error'

export interface ParallelRunSource {
  key: string
  inputs: ParallelRunInputKind[]
  reads_backup: boolean
}

export interface ParallelRunSources {
  sources: ParallelRunSource[]
  inputs: Record<ParallelRunInputKind, string[]>
  categories: ParallelRunCategory[]
}

export interface ParallelRunBackup {
  token: string
  file_name: string
  uploaded_at: string
  agenda_name: string
  agenda_ico: string
  years: number[]
}

export interface ParallelRunValue {
  field: string
  mine: number | null
  theirs: number | null
}

export interface ParallelRunDifference {
  id: string
  subject: string
  label: string | null
  values: ParallelRunValue[]
  note: string | null
  link: { type: string; id: number } | null
}

export interface ParallelRunCriterion {
  key: string
  status: ParallelRunCriterionStatus
  tolerance: string | null
  summary: Record<string, unknown>
  difference_count: number
  differences: ParallelRunDifference[]
  message?: string
}

export interface ParallelRunInputRecord {
  kind: ParallelRunInputKind | 'backup'
  name: string
  sha256: string
  size: number
  note?: string
}

export interface ParallelRunClassification {
  category: ParallelRunCategory
  note: string | null
  by: number | null
  at: string
  carried_from?: number
}

export interface ParallelRunClassificationSummary {
  differences: number
  unclassified: number
  source: number
  migration: number
  interpretation: number
  errors: number
  can_close: boolean
}

export interface ParallelRunCheckHeader {
  id: number
  month: string
  source: string
  status: ParallelRunStatus
  cycle_status: 'open' | 'closed'
  inputs: ParallelRunInputRecord[]
  classifications: Record<string, ParallelRunClassification>
  note: string | null
  created_by: number | null
  created_at: string
  closed_by: number | null
  closed_at: string | null
}

export interface ParallelRunHistoryItem extends ParallelRunCheckHeader {
  classification: Record<ParallelRunCategory, number>
}

export interface ParallelRunCheck extends ParallelRunCheckHeader {
  result: {
    month: string
    period: { id: number; fiscal_year: number; starts_on: string; ends_on: string }
    as_of: string
    source: string
    status: ParallelRunStatus
    criteria: ParallelRunCriterion[]
    warnings: string[]
    inputs: ParallelRunInputRecord[]
  } | null
  classification: ParallelRunClassificationSummary
}

export interface ParallelRunRunPayload {
  month: string
  source: string
  files: Partial<Record<ParallelRunInputKind, File>>
  statementUnit: number
  backupToken: string | null
}

export const parallelRunApi = {
  sources: () => api.get<ParallelRunSources>('/accounting/parallel-run/sources').then(r => r.data),
  backups: () => api.get<{ backups: ParallelRunBackup[] }>('/accounting/parallel-run/backups').then(r => r.data.backups),
  history: () => api.get<{ checks: ParallelRunHistoryItem[] }>('/accounting/parallel-run/checks').then(r => r.data.checks),
  get: (id: number) => api.get<ParallelRunCheck>(`/accounting/parallel-run/checks/${id}`).then(r => r.data),
  run: (payload: ParallelRunRunPayload) => {
    const fd = new FormData()
    fd.append('month', payload.month)
    fd.append('source', payload.source)
    fd.append('statement_unit', String(payload.statementUnit))
    if (payload.backupToken) fd.append('backup_token', payload.backupToken)
    for (const [kind, file] of Object.entries(payload.files)) {
      if (file) fd.append(kind, file, file.name)
    }
    return api.post<ParallelRunCheck>('/accounting/parallel-run/checks', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  classify: (id: number, differenceId: string, category: ParallelRunCategory | null, note: string | null) =>
    api.put<ParallelRunCheck>(`/accounting/parallel-run/checks/${id}/classification`, {
      difference_id: differenceId, category, note,
    }).then(r => r.data),
  close: (id: number, note: string | null) =>
    api.post<ParallelRunCheck>(`/accounting/parallel-run/checks/${id}/close`, { note }).then(r => r.data),
  reopen: (id: number) =>
    api.post<ParallelRunCheck>(`/accounting/parallel-run/checks/${id}/reopen`, {}).then(r => r.data),
  remove: (id: number) =>
    api.delete<{ deleted: boolean }>(`/accounting/parallel-run/checks/${id}`).then(r => r.data),
}
