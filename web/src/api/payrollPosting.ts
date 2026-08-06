import { api } from './client'

// MZ-18-W07 — reconciliation účetního můstku mezd (mzda ↔ deník ↔ platby).
// Read-only report; žádné mutační metody.

export type PayrollPostingReconciliationCategoryKey =
  | 'gross_wages'
  | 'employer_contributions'
  | 'social_health_insurance'
  | 'income_tax'
  | 'other_deductions'
  | 'enforcement'
  | 'net_wage'

export type PayrollPostingReconciliationCategoryStatus = 'match' | 'diff' | 'not_applicable'

export interface PayrollPostingReconciliationCategory {
  key: PayrollPostingReconciliationCategoryKey
  payroll_minor: number
  journal_minor: number | null
  payments_liability_minor: number | null
  payments_paid_minor: number | null
  diff_payroll_journal_minor: number | null
  diff_payroll_payments_minor: number | null
  status: PayrollPostingReconciliationCategoryStatus
}

export interface PayrollPostingReconciliation {
  schema_version: string
  supplier_id: number
  period: string
  accounting_mode: 'double_entry' | 'tax_evidence'
  run: { id: number; status: string } | null
  revision: { id: number; revision_no: number; status: string } | null
  journal_state: 'no_revision' | 'not_applicable' | 'unposted' | 'posted'
  payments_state: 'not_materialized' | 'materialized'
  overall_status: 'info' | 'reconciled' | 'diff'
  categories: PayrollPostingReconciliationCategory[]
}

export const payrollPostingApi = {
  reconciliation: (period: string) =>
    api.get<PayrollPostingReconciliation>('/payroll/posting/reconciliation', {
      params: { period },
    }).then(response => response.data),
}
