import { api } from './client'

/** Nejbližší termín DPH/KH/SH napříč typy (CrmAggregationService::nextTaxDeadline). */
export interface PortfolioDeadline {
  label: string
  date: string
  days: number
  shv_pending: boolean
}

export interface PortfolioPeriodStatus {
  fiscal_year: number
  status: 'open' | 'closing' | 'closed'
}

export interface PortfolioCompany {
  supplier_id: number
  company_name: string
  ic: string | null
  is_vat_payer: boolean
  accounting_mode: 'tax_evidence' | 'double_entry'
  next_deadline: PortfolioDeadline | null
  unbooked_documents: number
  unmatched_bank_transactions: number
  purchase_drafts: number
  period_status: PortfolioPeriodStatus | null
  last_bank_import_at: string | null
}

export interface PortfolioOverview {
  companies: PortfolioCompany[]
  total: number
  generated_at: string
}

export const portfolioApi = {
  overview: () => api.get<PortfolioOverview>('/portfolio/overview').then(r => r.data),
}
