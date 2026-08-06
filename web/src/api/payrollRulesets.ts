import { api } from './client'

/**
 * Legislativní rulesety mezd — globální číselník (default v kódu + DB override),
 * stejný model jako roční daňové konstanty. Samostatný modul, aby se sdílený
 * `payroll.ts` nemusel kvůli téhle sekci rozšiřovat.
 */

export type PayrollRulesetLifecycle = 'draft' | 'reviewed' | 'approved' | 'active' | 'superseded'
export type PayrollRulesetCapability = 'supported' | 'manual_review'
export type PayrollRulesetCommand = 'review' | 'approve' | 'activate' | 'supersede'
export type PayrollRuleValueType =
  | 'decimal_rate'
  | 'money_minor'
  | 'integer'
  | 'text'
  | 'boolean'
  | 'manual_review'

export interface PayrollRulesetIssue {
  code: string
  message: string
  context: Record<string, unknown>
}

export interface PayrollRuleParameter {
  key: string
  type: PayrollRuleValueType
  value: string | number | boolean | null
  capability: PayrollRulesetCapability | null
  note: string | null
}

export interface PayrollRulesetSource {
  id: string | null
  title: string | null
  url: string | null
  retrieved_on: string | null
}

export interface PayrollRulesetEvidence {
  checked_by?: string
  checked_on?: string
  reviewed_by?: string
  reviewed_on?: string
  approved_by?: string
  approved_on?: string
  evidence?: string
}

export interface PayrollRulesetSummary {
  ruleset_id: string
  domain: string
  version: string
  effective_from: string
  effective_to: string
  lifecycle: PayrollRulesetLifecycle
  capability: PayrollRulesetCapability
  canonical_hash: string
  is_override: boolean
  has_default: boolean
  checksum_valid: boolean
  calculation_ready: boolean
  reason: string | null
  technical_review: PayrollRulesetEvidence | null
  approval: PayrollRulesetEvidence | null
  updated_by: number | null
  updated_at: string | null
  reviewed_by: number | null
  approved_by: number | null
  activated_by: number | null
  row_version: number
  next_command: PayrollRulesetCommand | null
  blockers: PayrollRulesetIssue[]
  warnings: PayrollRulesetIssue[]
}

export interface PayrollRulesetDiffEntry {
  key: string
  before?: { type: PayrollRuleValueType; value: unknown; capability?: string; note?: string | null }
  after?: { type: PayrollRuleValueType; value: unknown; capability?: string; note?: string | null }
}

export interface PayrollRulesetDiff {
  domain: string
  left: { ruleset_id: string; label: string; version: string }
  right: { ruleset_id: string; label: string; version: string }
  effective: {
    left: { from: string; to: string }
    right: { from: string; to: string }
  }
  parameters: {
    added: PayrollRulesetDiffEntry[]
    removed: PayrollRulesetDiffEntry[]
    changed: PayrollRulesetDiffEntry[]
    unchanged_count: number
    identical: boolean
  }
}

export interface PayrollRulesetAuditRow {
  id: number
  action: string
  reason: string
  lifecycle: PayrollRulesetLifecycle
  snapshot_hash: string
  previous_hash: string | null
  actor_user_id: number | null
  created_at: string
}

export interface PayrollRulesetDetail extends PayrollRulesetSummary {
  parameters: PayrollRuleParameter[]
  sources: PayrollRulesetSource[]
  audit: PayrollRulesetAuditRow[]
  default_diff: PayrollRulesetDiff['parameters'] | null
  previous_ruleset_id: string | null
}

export interface PayrollRulesetDomainGroup {
  domain: string
  version_count: number
  active_count: number
  calculation_ready: boolean
  coverage_issues: PayrollRulesetIssue[]
  versions: PayrollRulesetSummary[]
}

export interface PayrollRulesetOverview {
  domains: PayrollRulesetDomainGroup[]
  override_storage_available: boolean
  degraded_reason: string | null
  generated_at: string
}

export interface PayrollRulesetSavePayload {
  reason: string
  row_version: number
  domain?: string
  version?: string
  effective_from?: string
  effective_to?: string
  capability?: PayrollRulesetCapability
  parameters?: Record<string, Record<string, unknown> | null>
  sources?: PayrollRulesetSource[]
}

/** Haléře → koruny (interní jednotka se v UI nikdy neukazuje). */
export function minorToCrowns(minor: number): number {
  return minor / 100
}

/** Koruny → haléře; zaokrouhlení na celý haléř, nikdy float do API. */
export function crownsToMinor(crowns: number): number {
  return Math.round(crowns * 100)
}

/** Desetinná sazba → procenta pro zobrazení (0.145 → 14,5). */
export function rateToPercent(rate: string): number {
  return Number(rate) * 100
}

/**
 * Procenta → kanonický desetinný řetězec. Přes řetězec, ne přes float:
 * `0.145` v JS není přesná hodnota a API očekává kanonický zápis sazby.
 */
export function percentToRate(percent: number): string {
  const scaled = Math.round(percent * 1e8) / 1e10
  return scaled.toFixed(10).replace(/0+$/, '').replace(/\.$/, '') || '0'
}

export const payrollRulesetsApi = {
  overview: () =>
    api.get<PayrollRulesetOverview>('/payroll/rulesets').then(response => response.data),
  detail: (rulesetId: string) =>
    api
      .get<{ ruleset: PayrollRulesetDetail }>(`/payroll/rulesets/${encodeURIComponent(rulesetId)}`)
      .then(response => response.data.ruleset),
  diff: (rulesetId: string, against = 'default') =>
    api
      .get<{ diff: PayrollRulesetDiff }>(
        `/payroll/rulesets/${encodeURIComponent(rulesetId)}/diff`,
        { params: { against } },
      )
      .then(response => response.data.diff),
  save: (rulesetId: string, payload: PayrollRulesetSavePayload) =>
    api
      .put<{ ruleset: PayrollRulesetDetail }>(
        `/payroll/rulesets/${encodeURIComponent(rulesetId)}`,
        payload,
      )
      .then(response => response.data.ruleset),
  reset: (rulesetId: string, reason: string) =>
    api
      .delete<{ ruleset: PayrollRulesetDetail | null; removed: boolean }>(
        `/payroll/rulesets/${encodeURIComponent(rulesetId)}`,
        { data: { reason } },
      )
      .then(response => response.data),
  command: (
    rulesetId: string,
    command: PayrollRulesetCommand,
    payload: { reason: string; row_version: number },
  ) =>
    api
      .post<{ ruleset: PayrollRulesetDetail; changed: boolean }>(
        `/payroll/rulesets/${encodeURIComponent(rulesetId)}/commands/${command}`,
        payload,
      )
      .then(response => response.data),
}
