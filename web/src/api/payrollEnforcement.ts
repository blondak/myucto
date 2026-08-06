import { api } from './client'

export type EnforcementCaseKind = 'enforcement' | 'voluntary_agreement'
export type EnforcementCaseStatus =
  | 'received'
  | 'withhold_and_hold'
  | 'remit'
  | 'deferred_no_withholding'
  | 'deferred_hold'
  | 'paid'
  | 'stopped'
export type EnforcementCaseCommand =
  | 'mark_final'
  | 'authorize_remittance'
  | 'defer_no_withholding'
  | 'defer_hold'
  | 'resume_holding'
  | 'resume_remittance'
  | 'mark_paid'
  | 'stop'
export type EnforcementClaimCategory =
  | 'current_maintenance'
  | 'maintenance_arrears'
  | 'substitute_maintenance'
  | 'other_priority'
  | 'non_priority'
export const pensionEvidenceValues = ['unknown', 'none', 'verified'] as const
export type PensionEvidenceValue = typeof pensionEvidenceValues[number]

export interface EnforcementClaim {
  id: number
  case_id: number
  legal_basis: 'statutory' | 'voluntary_agreement'
  category: EnforcementClaimCategory
  outstanding_minor_units: number
  maintenance_weight_minor_units: number | null
  priority_date: string | null
  order_issued_on: string | null
  legal_title_verified: boolean
  order_or_notice_delivered: boolean
  priority_classification_verified: boolean
  agreement_verified: boolean
  due_monetary_claim_verified: boolean
  is_active: boolean
  row_version: number
}

export interface EnforcementEvent {
  id: number
  command_name: EnforcementCaseCommand
  from_status: EnforcementCaseStatus | null
  to_status: EnforcementCaseStatus
  reason: string | null
  decision_document_id?: number | null
  actor_user_id: number | null
  created_at: string
}

export interface EnforcementLedgerEntry {
  id: number
  claim_id: number | null
  month_result_id: number
  entry_kind: 'withheld' | 'held' | 'remitted' | 'released_to_employee' | 'employer_fee' | 'adjustment'
  amount_minor_units: number
  actor_user_id: number | null
  created_at: string
}

export interface EnforcementCaseSummary {
  id: number
  employee_id: number
  full_name: string
  case_kind: EnforcementCaseKind
  status: EnforcementCaseStatus
  effective_from: string
  effective_to: string | null
  evidence_complete: boolean
  recipient_verified: boolean
  row_version: number
  claim_count: number
  outstanding_minor_units: number
  created_at: string
  updated_at: string
}

export interface EnforcementSettlementClaim {
  claim_id: number
  category: EnforcementClaimCategory
  priority_date: string | null
  is_active: boolean
  outstanding_minor: number
  withheld_minor: number
  held_minor: number
  liability_minor: number
  settled_minor: number
  remaining_minor: number
}

export interface EnforcementSettlement {
  claims: EnforcementSettlementClaim[]
  withheld_minor: number
  held_minor: number
  liability_minor: number
  settled_minor: number
  outstanding_minor: number
  remaining_minor: number
}

export interface EnforcementCaseDetail extends EnforcementCaseSummary {
  recipient_institution_id: number | null
  claims: EnforcementClaim[]
  events: EnforcementEvent[]
  ledger: EnforcementLedgerEntry[]
  settlement: EnforcementSettlement
}

export interface EnforcementClaimPayload {
  legal_basis: 'statutory' | 'voluntary_agreement'
  category: EnforcementClaimCategory
  outstanding_minor_units: number
  maintenance_weight_minor_units: number | null
  priority_date: string | null
  order_issued_on: string | null
  legal_title_verified: boolean
  order_or_notice_delivered: boolean
  priority_classification_verified: boolean
  agreement_verified: boolean
  due_monetary_claim_verified: boolean
  same_order_as_claim_id?: number | null
}

export interface EnforcementMonthEvidence {
  id: number | null
  employee_id: number
  period_start: string
  claim_register_evidence_complete: boolean
  dependants_evidence_complete: boolean
  spouse_evidence_complete: boolean
  pension_evidence: PensionEvidenceValue
  has_multiple_payers: boolean
  protected_amount_override_minor_units: number | null
  protected_amount_override_verified: boolean
  insolvency_mode: 'none' | 'alert_only' | 'approved_standard' | 'court_determined_amount'
  insolvency_decision_verified: boolean
  insolvency_recipient_verified: boolean
  court_determined_amount_minor_units: number | null
  row_version: number | null
}

export interface EnforcementDependant {
  id: number
  employee_id: number
  dependant_kind: 'dependant' | 'spouse_partner'
  valid_from: string
  valid_to: string | null
  eligibility_verified: boolean
  excluded_for_maintenance: boolean
  row_version: number
}

export const payrollEnforcementApi = {
  cases: (filters?: { employee_id?: number; status?: EnforcementCaseStatus }) =>
    api.get<{ cases: EnforcementCaseSummary[] }>('/payroll/enforcement/cases', {
      params: filters,
    }).then(response => response.data.cases),
  detail: (id: number) =>
    api.get<{ case: EnforcementCaseDetail }>(`/payroll/enforcement/cases/${id}`)
      .then(response => response.data.case),
  create: (payload: {
    employee_id: number
    case_kind: EnforcementCaseKind
    effective_from: string
  }) =>
    api.post<{ case: EnforcementCaseDetail }>('/payroll/enforcement/cases', payload)
      .then(response => response.data.case),
  addClaim: (caseId: number, payload: EnforcementClaimPayload) =>
    api.post<{ claim: EnforcementClaim }>(
      `/payroll/enforcement/cases/${caseId}/claims`,
      payload,
    ).then(response => response.data.claim),
  updateEvidence: (
    caseId: number,
    payload: {
      evidence_complete: boolean
      recipient_verified: boolean
      row_version: number
      recipient_institution_id?: number | null
    },
  ) =>
    api.put<{ case: EnforcementCaseDetail }>(
      `/payroll/enforcement/cases/${caseId}/evidence`,
      payload,
    ).then(response => response.data.case),
  transition: (
    caseId: number,
    command: EnforcementCaseCommand,
    payload: {
      row_version: number
      reason?: string | null
      decision_document_id?: number | null
    },
  ) =>
    api.post<{ case: EnforcementCaseDetail }>(
      `/payroll/enforcement/cases/${caseId}/commands/${command}`,
      payload,
    ).then(response => response.data.case),
  monthEvidence: (employeeId: number, period: string) =>
    api.get<{ evidence: EnforcementMonthEvidence }>(
      `/payroll/enforcement/people/${employeeId}/month/${period}/evidence`,
    ).then(response => response.data.evidence),
  saveMonthEvidence: (
    employeeId: number,
    period: string,
    payload: Omit<EnforcementMonthEvidence, 'id' | 'employee_id' | 'period_start'>,
  ) =>
    api.put<{ evidence: EnforcementMonthEvidence }>(
      `/payroll/enforcement/people/${employeeId}/month/${period}/evidence`,
      payload,
    ).then(response => response.data.evidence),
  dependants: (employeeId: number) =>
    api.get<{ dependants: EnforcementDependant[] }>(
      `/payroll/enforcement/people/${employeeId}/dependants`,
    ).then(response => response.data.dependants),
  addDependant: (
    employeeId: number,
    payload: {
      dependant_kind: 'dependant' | 'spouse_partner'
      valid_from: string
      valid_to: string | null
      eligibility_verified: boolean
      excluded_for_maintenance: boolean
    },
  ) =>
    api.post<{ dependant: EnforcementDependant }>(
      `/payroll/enforcement/people/${employeeId}/dependants`,
      payload,
    ).then(response => response.data.dependant),
}
