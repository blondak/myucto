import { api } from './client'

// PAM-11 — kontrolní sestava „naše přepočtená mzda vs. mzda převzatá z původního
// systému". Read-only report; žádné mutační metody.

/** Zdroj převzatých mezd; musí sedět na ENUM v migraci 1849. */
export type PayrollMigrationSource = 'pamica' | 'pohoda' | 'money_s3'

/**
 * Stav porovnání jedné částky. `reference_missing` / `calculated_missing` NENÍ
 * nulový rozdíl — je to přiznání, že jedna strana chybí a rozdíl nejde změřit.
 */
export type PayrollMigrationCellStatus =
  | 'match'
  | 'differs'
  | 'reference_missing'
  | 'calculated_missing'

/** Porovnávané veličiny na řádku osoby. `employer_social` je jen v součtech. */
export type PayrollMigrationRowMetric =
  | 'gross'
  | 'net'
  | 'social_base'
  | 'health_base'
  | 'employee_social'
  | 'employee_health'
  | 'employer_health'
  | 'advance_tax'
  | 'withholding_tax'
  | 'tax_bonus'

export type PayrollMigrationTotalMetric = PayrollMigrationRowMetric | 'employer_social'

export interface PayrollMigrationCell {
  reference_minor: number | null
  calculated_minor: number | null
  /** `null`, když jedna strana chybí — nikdy 0. */
  difference_minor: number | null
  status: PayrollMigrationCellStatus
}

export interface PayrollMigrationTotalCell extends PayrollMigrationCell {
  /** Do součtu nepřispěly všechny řádky (některé nemají protějšek). */
  incomplete: boolean
}

export interface PayrollMigrationRelationship {
  external_relationship_ref: string
  employment_id: number | null
  gross_minor: number
}

export interface PayrollMigrationRow {
  period: string
  employee_id: number | null
  full_name: string | null
  /** Identifikátor osoby v původním systému; jediné, co zbývá u nenapárované osoby. */
  external_person_ref: string | null
  presence: 'both' | 'reference_only' | 'calculated_only'
  relationships: PayrollMigrationRelationship[]
  metrics: Record<PayrollMigrationRowMetric, PayrollMigrationCell>
  max_abs_difference_minor: number | null
  has_deviation: boolean
}

export interface PayrollMigrationMonth {
  period: string
  rows: PayrollMigrationRow[]
  totals: Record<PayrollMigrationTotalMetric, PayrollMigrationTotalCell>
  row_count: number
  deviation_count: number
  missing_counterpart_count: number
  /** Stav revize, ze které se čte naše strana; `mixed` = víc běhů v různém stavu. */
  calculated_revision_status: string | null
}

export interface PayrollMigrationDeviation {
  period: string
  employee_id: number | null
  full_name: string | null
  external_person_ref: string | null
  metric: PayrollMigrationRowMetric
  reference_minor: number | null
  calculated_minor: number | null
  difference_minor: number | null
  status: PayrollMigrationCellStatus
}

export interface PayrollMigrationReconciliation {
  year: number
  row_metrics: PayrollMigrationRowMetric[]
  total_metrics: PayrollMigrationTotalMetric[]
  months: PayrollMigrationMonth[]
  totals: Record<PayrollMigrationTotalMetric, PayrollMigrationTotalCell>
  deviations: PayrollMigrationDeviation[]
  summary: {
    row_count: number
    deviation_count: number
    missing_counterpart_count: number
    max_abs_difference_minor: number | null
  }
  sources: PayrollMigrationSource[]
  source: PayrollMigrationSource | null
}

export const payrollMigrationReconciliationApi = {
  report: (year: number, source?: PayrollMigrationSource | null) =>
    api.get<{ report: PayrollMigrationReconciliation }>(
      `/payroll/reports/migration-reconciliation/${year}${source ? `?source=${encodeURIComponent(source)}` : ''}`,
    ).then(response => response.data.report),
}
