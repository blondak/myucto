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
  /**
   * Účinné jméno osoby — totéž, které ukazuje seznam osob. Není to citlivý údaj
   * ve smyslu `payroll.person.read_sensitive`; ten hlídá rodná čísla, účty,
   * adresy a rodné příjmení, a ta se odsud nevydávají.
   */
  full_name: string
  last_record_year: number
  governing_category: string | null
  governing_source: string | null
  governing_source_status: RetentionSourceStatus | null
  retained_until: string | null
  expired: boolean
  action: 'erase' | 'anonymize' | null
  /** Co u osoby zmizí — počty řádků podle skupiny dat. */
  identity: Record<string, number>
  /** Osobní údaj, který ve zmrazeném obsahu (PDF, XML) zůstane. */
  residue: Record<string, number>
  holds: PayrollRetentionHold[]
  proposable: boolean
  blocked_by: PayrollRetentionBlock | null
}

export interface PayrollRetentionAssessment {
  as_of: string
  items: PayrollRetentionAssessmentItem[]
  proposable: number
}

// ── Zadržení výmazu (legal hold) ────────────────────────────────────────────
// § 32 ZoÚ a mzdové důvody. Zadržení drží výmaz i po uplynutí lhůty; uvolnění
// je vědomý úkon, po kterém záznam zůstává jen s datem uvolnění.

export type PayrollRetentionHoldReason =
  | 'tax_audit'
  | 'appeal'
  | 'litigation'
  | 'enforcement'
  | 'insolvency'
  | 'other'

export const PAYROLL_RETENTION_HOLD_REASONS: PayrollRetentionHoldReason[] = [
  'tax_audit',
  'appeal',
  'litigation',
  'enforcement',
  'insolvency',
  'other',
]

export interface PayrollRetentionHold {
  id: number
  subject_kind: 'company' | 'payroll_employee'
  subject_id: number | null
  period_year: number | null
  reason: string
  description: string
  placed_on: string
  released_on: string | null
  /** Dopojené jméno; `null` u firemního zadržení a u osoby, která už neexistuje. */
  employee_full_name?: string | null
}

export interface PayrollRetentionHoldPayload {
  employee_id: number
  reason: PayrollRetentionHoldReason
  description: string
  placed_on: string
}

// ── Odchylka firmy od katalogové lhůty ──────────────────────────────────────
// Doména pustí jen prodloužení (`extra_years`) a dodání lhůty tam, kde ji
// katalog nemá (`override_years`). Zkrácení odmítne server, ne formulář.

export interface PayrollRetentionPolicyPayload {
  extra_years: number
  override_years: number | null
  reason: string
}

// ── Návrh výmazu ────────────────────────────────────────────────────────────

export type PayrollErasureStatus = 'pending' | 'approved' | 'rejected' | 'executed'
export type PayrollErasureOutcome = 'pending' | 'done' | 'skipped_hold' | 'skipped_changed'

export interface PayrollErasureProposal {
  id: number
  as_of: string
  status: PayrollErasureStatus
  note: string | null
  created_at: string
  created_by: number | null
  approved_at: string | null
  approved_by: number | null
  rejected_at: string | null
  executed_at: string | null
  item_count?: number
}

export interface PayrollErasureProposalItem {
  id: number
  employee_id: number
  /**
   * Jméno se k položce DOPOJUJE při čtení, v tabulce návrhu uložené není —
   * doklad o výmazu si osobní údaj nechat nesmí. Po provedeném úplném výmazu
   * je proto `null`, po anonymizaci nese anonymizovanou náhradu.
   */
  full_name: string | null
  action: 'erase' | 'anonymize'
  governing_category: string
  governing_source: string
  governing_source_status: RetentionSourceStatus
  retained_until: string
  last_record_year: number
  /**
   * Náhled dopadu: `{identity, residue}` při sestavení návrhu, po provedení
   * skutečné počty smazaných řádků. Server ho rozebírá za nás, tady je objekt.
   */
  cascade_counts: {
    identity?: Record<string, number>
    residue?: Record<string, number>
  } & Record<string, unknown> | null
  outcome: PayrollErasureOutcome
  skip_reason: string | null
  executed_at: string | null
}

export interface PayrollErasureProposalDetail {
  proposal: PayrollErasureProposal
  items: PayrollErasureProposalItem[]
}

export interface PayrollErasureSummary {
  done: number
  skipped_hold: number
  skipped_changed: number
}

export const payrollRetentionApi = {
  overview: () =>
    api.get<PayrollRetentionOverview>('/payroll/retention').then(r => r.data),

  assessment: (asOf?: string) =>
    api.get<PayrollRetentionAssessment>('/payroll/retention/assessment', {
      params: asOf ? { as_of: asOf } : {},
    }).then(r => r.data),

  putPolicy: (category: string, payload: PayrollRetentionPolicyPayload) =>
    api.put<{ ok: true }>(`/payroll/retention/policies/${category}`, payload).then(r => r.data),

  deletePolicy: (category: string) =>
    api.delete<{ ok: true }>(`/payroll/retention/policies/${category}`).then(r => r.data),

  holds: (includeReleased = false) =>
    api.get<{ holds: PayrollRetentionHold[] }>('/payroll/retention/holds', {
      params: includeReleased ? { include_released: '1' } : {},
    }).then(r => r.data.holds),

  placeHold: (payload: PayrollRetentionHoldPayload) =>
    api.post<{ id: number }>('/payroll/retention/holds', payload).then(r => r.data),

  releaseHold: (id: number) =>
    api.delete<{ ok: true }>(`/payroll/retention/holds/${id}`).then(r => r.data),

  proposals: () =>
    api.get<{ proposals: PayrollErasureProposal[] }>('/payroll/retention/erasure')
      .then(r => r.data.proposals),

  createProposal: (asOf: string, note?: string) =>
    api.post<{ id: number }>('/payroll/retention/erasure', { note: note ?? null }, {
      params: { as_of: asOf },
    }).then(r => r.data),

  proposal: (id: number) =>
    api.get<PayrollErasureProposalDetail>(`/payroll/retention/erasure/${id}`).then(r => r.data),

  approveProposal: (id: number) =>
    api.post<{ ok: true }>(`/payroll/retention/erasure/${id}/approve`).then(r => r.data),

  rejectProposal: (id: number) =>
    api.post<{ ok: true }>(`/payroll/retention/erasure/${id}/reject`).then(r => r.data),

  /** Nevratné. Volá se až po výslovném potvrzení v UI, ne z jednoho kliknutí. */
  executeProposal: (id: number) =>
    api.post<{ summary: PayrollErasureSummary }>(`/payroll/retention/erasure/${id}/execute`)
      .then(r => r.data.summary),
}
