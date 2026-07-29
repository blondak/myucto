import { api } from './client'

/** Jeden řádek výsledovky/rozvahy (kanonický tvar z FinancialStatementService). */
export interface StatementRow {
  row_code: string
  display_code: string
  label: string
  level: number
  row_type: string
  amount?: number
  prev_amount?: number
  net?: number
  prev_net?: number
}

export interface OverdueItem {
  partner_name: string
  doc_type: string
  doc_id: number
  doc_no: string | null
  issue_date: string | null
  due_date: string
  currency_code: string
  remaining_czk: number
  days_overdue: number
}

export interface UpcomingDeadline {
  advance_kind: string
  due_date: string
  amount: number
  status: string
  is_overdue: boolean
}

export interface MonthlyReportVat {
  period: string
  period_type: string | null
  tax_due: number
  is_excess_deduction: boolean
  submission_deadline: string
}

export interface MonthlyReportData {
  entity: { name: string; ico: string | null; address: string }
  period: { year: number; month: number; as_of: string }
  generated_at: string
  comment: string | null
  income_statement_ytd: { rows: StatementRow[]; checks: { profit_current: number; net_turnover: number } }
  income_statement_month: StatementRow[]
  balance_sheet: {
    assets: StatementRow[]
    liabilities: StatementRow[]
    checks: { assets_net: number; liabilities_total: number; balanced: boolean }
  }
  receivables_overdue: OverdueItem[]
  payables_overdue: OverdueItem[]
  vat: MonthlyReportVat | null
  upcoming_deadlines: UpcomingDeadline[]
}

export interface MonthlyReportSendPayload {
  year: number
  month: number
  comment?: string
  to: string[]
  cc?: string[]
  subject_override?: string
}

export interface MonthlyReportSendResult {
  sent_to: string[]
  cc: string[]
  document_id: number | null
  sent_at: string
}

export interface MonthlyReportSendHistoryItem {
  id: number
  report_year: number
  report_month: number
  sent_to: string[]
  cc: string[]
  comment: string | null
  document_id: number | null
  sent_by_name: string | null
  created_at: string
}

export const monthlyReportApi = {
  preview: (year: number, month: number, comment?: string) =>
    api.get<MonthlyReportData>('/accounting/reports/monthly-report/preview', {
      params: { year, month, ...(comment ? { comment } : {}) },
    }).then(r => r.data),

  exportPdf: (year: number, month: number, comment?: string) =>
    api.get<Blob>('/accounting/reports/monthly-report/download', {
      params: { year, month, ...(comment ? { comment } : {}) },
      responseType: 'blob',
    }),

  send: (payload: MonthlyReportSendPayload) =>
    api.post<MonthlyReportSendResult>('/accounting/reports/monthly-report/send', payload).then(r => r.data),

  history: (limit = 30) =>
    api.get<{ data: MonthlyReportSendHistoryItem[] }>('/accounting/reports/monthly-report/history', {
      params: { limit },
    }).then(r => r.data.data),
}
