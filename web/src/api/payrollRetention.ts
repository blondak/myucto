import { api } from './client'

// ── Retenční lhůty mzdové agendy ────────────────────────────────────────────
// Čtecí pohled na `PayrollRetentionCatalog` — zákonné lhůty, jejich pramen,
// den ověření a odchylky firmy. Nic se odsud nemaže: výmaz je samostatný
// návrh ke schválení (`payroll.erasure`), tenhle modul ho jen shrnuje.

/** Odkud lhůta pochází. `house_policy` NENÍ zákonná lhůta. */
export type RetentionOrigin = 'statute' | 'house_policy' | 'none'

/** Čím je lhůta doložená. `statute_silent` = doložená NEEXISTENCE lhůty. */
export type RetentionSourceStatus =
  | 'statute_verified'
  | 'statute_silent'
  | 'external_unverified'
  | 'undetermined'

/** Od čeho lhůta běží. */
export type RetentionBasis =
  | 'calendar_years_after_record_year'
  | 'calendar_years_after_issue_year'
  | 'years_after_accounting_period_end'

export interface PayrollRetentionCategory {
  category: string
  label: string
  /** Zákonná lhůta z katalogu; `null` = lhůta není určená (ne „nula"). */
  retention_years: number | null
  basis: RetentionBasis
  /** Druhá báze téhož ustanovení, kterou posudek NEAPLIKUJE. */
  alternative_basis: RetentionBasis | null
  origin: RetentionOrigin
  statutory: boolean
  act: string
  section: string | null
  amendment: string | null
  /** Citace do UI — u dodané politiky výslovné přiznání, že za číslem stojí aplikace. */
  source: string
  source_status: RetentionSourceStatus
  verified_on: string | null
  accounting_relevant: boolean
  closing_agenda: boolean
  note: string
  /** Tabulky vázané na osobu. */
  employee_tables: string[]
  /** Tabulky vázané na pracovní vztah. */
  employment_tables: string[]
  /** Lhůta po započtení odchylky firmy; `null` = neurčená, k výmazu se nenavrhne. */
  effective_years: number | null
  determined: boolean
}

export interface PayrollRetentionPolicy {
  id: number
  category: string
  extra_years: number
  override_years: number | null
  reason: string
  updated_at: string
}

export interface PayrollRetentionOverview {
  categories: PayrollRetentionCategory[]
  policies: PayrollRetentionPolicy[]
}

/** Proč se osoba k výmazu nenavrhla. */
export type PayrollRetentionBlock =
  | 'within_retention'
  | 'undetermined_retention'
  | 'legal_hold'
  | 'already_anonymized'
  | 'no_retention_basis'

export interface PayrollRetentionAssessmentItem {
  employee_id: number
  last_record_year: number
  governing_category: string | null
  governing_source: string | null
  governing_source_status: RetentionSourceStatus | null
  retained_until: string | null
  expired: boolean
  action: 'erase' | 'anonymize' | null
  proposable: boolean
  blocked_by: PayrollRetentionBlock | null
}

export interface PayrollRetentionAssessment {
  as_of: string
  items: PayrollRetentionAssessmentItem[]
  proposable: number
}

export const payrollRetentionApi = {
  overview: () =>
    api.get<PayrollRetentionOverview>('/payroll/retention').then(r => r.data),

  assessment: (asOf?: string) =>
    api.get<PayrollRetentionAssessment>('/payroll/retention/assessment', {
      params: asOf ? { as_of: asOf } : {},
    }).then(r => r.data),
}
