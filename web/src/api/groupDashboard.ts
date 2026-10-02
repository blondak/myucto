import { api } from './client'

export type GroupSection = 'overview' | 'trends' | 'forecast' | 'cashflow' | 'balances' | 'receivables' | 'risks'
export interface GroupAnnualForecast {
  year: number; currency: string
  revenue_model: number | null; costs_model: number | null; revenue_current_year: number | null; costs_current_year: number | null
  other_revenue: number | null; other_costs: number | null; other_posted: number | null; other_draft: number | null
  revenue: number | null; costs: number | null; profit: number | null
  revenue_low: number | null; revenue_high: number | null; profit_low: number | null; profit_high: number | null
}
export interface Amounts { currency: string; revenue: number | null; costs: number | null; profit: number | null }
export interface Financial extends Amounts { previous_revenue: number | null; previous_costs: number | null; previous_profit: number | null }
export interface Accounting extends Amounts { from: string; to: string }
export interface Monthly extends Amounts { period: string }
export interface Balance { id: number; name: string; currency: string; source_currency?: string; balance: number | null; date: string | null }
export interface Aging { currency: string; bucket: 'not_due' | 'overdue_30' | 'overdue_60' | 'overdue_90' | 'overdue_90_plus'; count: number; total: number | null }
export interface CashWeek { week_start: string; week_end: string; in: number | null; out: number | null; net: number | null; running: number | null }
export interface Forecast { currency: string; weeks: CashWeek[]; total_in: number | null; total_out: number | null; total_net: number | null }
export interface Risk { kind: 'overdue_receivables' | 'overdue_payables' | 'document_loss' | 'client_concentration' | 'vendor_concentration'; currency: string; source_currency?: string; amount?: number | null; count?: number; bucket?: Aging['bucket']; percentage?: number; level?: 'medium' | 'high' }
export interface GroupCompany {
  id: number; name: string; accounting_mode: 'double_entry' | 'tax_evidence'; document_basis: 'net' | 'gross'
  available: { documents: boolean; accounting: boolean; bank: boolean; cash: boolean; receivables: boolean; payables: boolean; cashflow: boolean; cashflow_tax: boolean; cashflow_payroll: boolean; forecast: boolean }
  issues: string[]
  financial?: Financial[] | null
  accounting?: Accounting | null
  monthly?: Monthly[] | null
  bank?: Balance[] | null
  cash?: Balance[] | null
  receivables?: Aging[] | null
  payables?: Aging[] | null
  cashflow?: Forecast[] | null
  risks?: Risk[] | null
  forecast?: GroupAnnualForecast[] | null
}
export type Total<T> = T & { companies: number; missing_values: number }
export interface GroupDashboard {
  section: GroupSection; months: number; weeks: number; as_of: string; generated_at: string; company_count: number; companies: GroupCompany[]
  period: { from: string; to: string; previous_from: string; previous_to: string; mode: 'rolling' | 'custom' }
  concentration_period?: { months: number; from: string; to: string | null; basis: string }
  converted_czk?: {
    companies: GroupCompany[]; totals: GroupDashboard['totals']; as_of: string; missing_currencies: string[]
    rates: { supplier_id: number; currency: string; rate: number | null; date: string | null; basis: 'document_recorded' | 'balance_recorded' | 'cached_rate' }[]
  }
  totals: {
    financial: Total<Financial>[]; accounting: Total<Accounting>[]; monthly: Total<Monthly>[]
    bank: Total<{ currency: string; balance: number | null }>[]; cash: Total<{ currency: string; balance: number | null }>[]
    receivables: Total<Aging>[]; payables: Total<Aging>[]
    cashflow: Total<CashWeek & { currency: string }>[]
    forecast: Total<GroupAnnualForecast>[]
  }
}

export interface GroupDashboardQuery { section: GroupSection; months?: number; weeks?: number; from?: string; to?: string; include_related?: 0 | 1 }

export const groupDashboardApi = {
  get: (params: GroupDashboardQuery, signal?: AbortSignal) =>
    api.get<GroupDashboard>('/portfolio/group-dashboard', { params, signal }).then(response => response.data),
}
