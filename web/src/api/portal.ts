import { api } from './client'

/** KPI řádek portálu — jen agregáty (žádná jména klientů ani čísla dokladů). */
export interface PortalKpiRow {
  period: string | null
  currency: string
  invoiced: number
  costs: number
  profit: number
  invoiced_czk: number
  costs_czk: number
  profit_czk: number
  invoice_count: number
  purchase_count: number
}

export type PortalKpiPeriod = 'current_month' | 'last_month' | 'ytd' | 'prev_year_ytd' | 'last_12m'

export interface PortalAgingRow {
  bucket: 'not_due' | 'overdue_30' | 'overdue_60' | 'overdue_90' | 'overdue_90_plus'
  currency: string
  count: number
  total: number
}

export interface PortalForecastWeek {
  week_start: string
  week_end: string
  in: number
  out: number
  net: number
  running: number
}

export interface PortalForecast {
  currency: string
  weeks: PortalForecastWeek[]
  total_in: number
  total_out: number
  total_net: number
}

export interface PortalVatStatus {
  period: string
  period_type: 'monthly' | 'quarterly'
  quarter: number | null
  vat_output: number
  vat_input: number
  tax_due: number
  is_excess_deduction: boolean
  submission_deadline: string
}

export interface PortalDeadline {
  type: string
  severity: 'high' | 'medium'
  title: string
  hint: string
  link: string
  days: number
}

export interface PortalSummary {
  company: {
    name: string
    period: { current_month: string; ytd_from: string; today: string }
  }
  kpi: Record<PortalKpiPeriod, PortalKpiRow[]> & { currencies: string[] }
  monthly: PortalKpiRow[]
  cashflow: {
    receivables: PortalAgingRow[]
    payables: PortalAgingRow[]
    forecast: PortalForecast
  }
  vat: {
    is_vat_payer: boolean
    status: PortalVatStatus | null
    deadlines: PortalDeadline[]
  }
  /** Vyžádání chybějících dokladů (Fáze F) — jen agregát. */
  document_requests_open: { open: number; overdue: number }
}

export const portalApi = {
  summary: () => api.get<PortalSummary>('/portal/summary').then(r => r.data),
}
