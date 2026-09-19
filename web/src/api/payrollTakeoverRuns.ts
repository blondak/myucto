import { api } from './client'
import type { PayrollRun } from './payroll'

/**
 * Převzatý mzdový běh roku přechodu (PAM-17) a doložení jeho plateb (PAM-18).
 *
 * Vlastní klient, ne rozšíření `payrollApi`: převzatý běh neprochází workflow
 * mzdového běhu, takže nepatří ani mezi jeho příkazy.
 */

export type PayrollTakeoverPresence =
  | 'takeover_only'
  | 'calculated_only'
  | 'both'
  | 'none'

export interface PayrollTakeoverOverviewPeriod {
  period: string
  /** Předchází měsíc aktivaci mzdového modulu? Jen takový jde převzít. */
  historical: boolean
  presence: PayrollTakeoverPresence
  has_takeover_run: boolean
  /** Id převzatého běhu měsíce; `null`, dokud běh neexistuje. */
  run_id: number | null
  row_count: number
}

export interface PayrollTakeoverOverview {
  year: number
  payroll_start_period: string | null
  sources: string[]
  periods: PayrollTakeoverOverviewPeriod[]
}

export interface PayrollTakeoverSnapshot {
  id: number
  run_id: number
  period_start: string
  sources: string[]
  result_snapshot: {
    schema_reference: string
    /** U převzatého běhu vždy `false` — výsledek nevznikl výpočtem. */
    calculated: boolean
    period: string
    people: Array<{
      employee_id: number | null
      external_person_ref: string
      relationship_count: number
      payout_date: string | null
      totals: Record<string, number>
    }>
    totals: Record<string, number>
  }
  result_snapshot_hash: string
  input_snapshot_hash: string
  employee_count: number
  relationship_count: number
  built_at: string
}

export type PayrollTakeoverEvidenceKind =
  | 'net_wage'
  | 'deduction'
  | 'social_insurance'
  | 'health_insurance'
  | 'advance_tax'
  | 'withholding_tax'
  | 'tax_bonus'

/**
 * `reported` = částku i datum nese sám převzatý záznam. `derived` = částka je
 * součet složek převzaté mzdy, ne doklad o odeslané platbě; datum u ní není.
 */
export type PayrollTakeoverEvidenceCertainty = 'reported' | 'derived'

export interface PayrollTakeoverPaymentEvidence {
  id: number
  evidence_kind: PayrollTakeoverEvidenceKind
  certainty: PayrollTakeoverEvidenceCertainty
  employee_id: number | null
  external_person_ref: string
  employee_name: string | null
  amount_minor: number
  currency_code: string
  paid_on: string | null
}

export interface PayrollTakeoverRunDetail {
  run: PayrollRun
  takeover: PayrollTakeoverSnapshot
  payment_evidence: PayrollTakeoverPaymentEvidence[]
}

export const payrollTakeoverRunsApi = {
  overview: (year: number) =>
    api.get<{ takeover_overview: PayrollTakeoverOverview }>(
      `/payroll/runs/takeover/${year}`,
    ).then(response => response.data.takeover_overview),
  build: (period: string) =>
    api.post<PayrollTakeoverRunDetail>('/payroll/runs/takeover', { period })
      .then(response => response.data),
  detail: (runId: number) =>
    api.get<PayrollTakeoverRunDetail>(`/payroll/runs/${runId}/takeover`)
      .then(response => response.data),
  discard: (runId: number, reason: string) =>
    api.post<{ run_id: number; discarded: boolean }>(
      `/payroll/runs/${runId}/takeover/discard`,
      { reason },
    ).then(response => response.data),
}
