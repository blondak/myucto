import { api } from './client'
// Step-up (heslo / TOTP / passkey proof) je sdílený s EPO — volba podpisového
// certifikátu je rozhodnutí stejné třídy jako správa klíče samotného, takže
// kódování důkazu se nesmí rozejít se zbytkem aplikace.
import { stepUpProofBody, type EpoStepUpProof } from './epoSubmissions'

export type PayrollModuleStatus = 'disabled' | 'setup' | 'active' | 'suspended'
export type PayrollSupportStatus = 'supported' | 'manual_review' | 'not_supported'

export interface PayrollModuleState {
  supplier_id: number
  status: PayrollModuleStatus
  start_period: string | null
  row_version: number
  activated_at: string | null
  suspended_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface PayrollCapability {
  key: string
  status: PayrollSupportStatus
  available: boolean
  min_epic: string
}

export interface PayrollSupportMatrix {
  version: string
  supported_years: number[]
  employment_types: PayrollCapability[]
  features: PayrollCapability[]
}

export interface PayrollCapabilitiesResponse {
  state: PayrollModuleState
  support_matrix: PayrollSupportMatrix
}

export type PayrollRelationType = 'employment' | 'small_scale_employment' | 'dpp' | 'dpc' | 'partner_dependent' | 'statutory_body'
export type PayrollEmploymentStatus = 'planned' | 'preregistered' | 'active' | 'suspended' | 'ended' | 'archived' | 'no_show'
export type PayrollInsuranceParticipation = 'automatic' | 'included' | 'excluded' | 'foreign'
export type PayrollTaxRegime = 'advance' | 'withholding' | 'foreign' | 'manual_review'
export type PayrollChecklistStatus = 'pending' | 'completed' | 'not_applicable'

export interface PayrollPersonListItem {
  id: number
  full_name: string
  is_active: boolean
  profile_status: string
  legacy_taxpayer_type: string
  legacy_employment_type: string
  employment_count: number
  relation_types: PayrollRelationType[]
  needs_setup: boolean
}

export interface PayrollEmploymentAccounting {
  gross_debit: string
  gross_credit: string
  employer_insurance_debit: string
  employer_insurance_credit: string
}

export interface PayrollEmployment {
  id: number
  employee_id: number
  office_id: number | null
  office_code: string | null
  office_name: string | null
  code: string
  relation_type: PayrollRelationType
  status: PayrollEmploymentStatus
  is_primary: boolean
  start_date: string | null
  actual_start_date: string | null
  end_date: string | null
  archived_at: string | null
  is_legacy_projection: boolean
  monthly_gross_minor: number | null
  row_version: number
  allowed_transitions: PayrollEmploymentStatus[]
  accounting: PayrollEmploymentAccounting
  terms: PayrollEmploymentTerms[]
  checklist: PayrollEmploymentChecklistItem[]
  timeline: PayrollEmploymentEvent[]
}

export interface PayrollEmploymentTerms {
  id: number
  office_id: number | null
  office_code: string | null
  effective_from: string
  effective_to: string | null
  contract_signed_on: string | null
  planned_start_on: string
  actual_start_on: string | null
  fixed_term_end_on: string | null
  weekly_hours: string | null
  workload_basis_points: number
  work_place: string | null
  regular_workplace: string | null
  jmhz_workplace_municipality_code: string | null
  jmhz_workplace_country_code: string | null
  jmhz_external_codebook_overlay_key?: string | null
  jmhz_external_codebook_manifest_sha256?: string | null
  jmhz_apz_contribution_status: PayrollVerifiedTriState
  jmhz_apz_instrument_code: string | null
  jmhz_functional_benefits_status: PayrollVerifiedTriState
  jmhz_temporary_assignment_status: PayrollVerifiedTriState
  cz_isco_code: string | null
  activity_code: string | null
  jmhz_relationship_detail_code: string | null
  social_insurance_participation: PayrollInsuranceParticipation
  health_insurance_participation: PayrollInsuranceParticipation
  tax_regime: PayrollTaxRegime
  foreign_legislation_country_code: string | null
  a1_certificate_until: string | null
  risky_work: boolean
  tax_declaration_signed: boolean
  is_primary: boolean
  change_reason: string | null
  row_version: number
  created_at: string
}

export interface PayrollEmploymentChecklistItem {
  id: number
  phase: 'onboarding' | 'change' | 'offboarding'
  item_key: string
  status: PayrollChecklistStatus
  due_date: string | null
  completed_at: string | null
  note: string | null
  row_version: number
}

export interface PayrollEmploymentEvent {
  id: number
  event_type: 'created' | 'terms_changed' | 'status_changed' | 'checklist_changed'
  from_status: PayrollEmploymentStatus | null
  to_status: PayrollEmploymentStatus | null
  effective_on: string
  note: string | null
  diff: Record<string, { from: unknown; to: unknown }> | null
  created_at: string
}

export type PayrollEmploymentTermsPayload = Omit<
  PayrollEmploymentTerms,
  'id' | 'office_code' | 'effective_to' | 'jmhz_external_codebook_overlay_key'
    | 'jmhz_external_codebook_manifest_sha256' | 'row_version' | 'created_at'
>

export interface PayrollEmploymentCreatePayload {
  code: string
  relation_type: PayrollRelationType
  monthly_gross_minor: number | null
  terms: PayrollEmploymentTermsPayload
}

export interface PayrollPerson extends PayrollPersonListItem {
  employments: PayrollEmployment[]
}

export interface PayrollPeopleResponse {
  items: PayrollPersonListItem[]
}

export interface PayrollPersonResponse {
  person: PayrollPerson
}

export interface PayrollPersonCreatePayload {
  full_name: string
  birth_date: string | null
  birth_number: string | null
  relation_type: PayrollRelationType
  planned_start_on: string
  monthly_gross: number | null
  office_id?: number | null
}

export type PayrollPersonProfileStatus = 'missing' | 'legacy' | 'setup' | 'ready'
export type PayrollPersonEditableProfileStatus = Exclude<PayrollPersonProfileStatus, 'missing'>
export type PayrollPayoutMethod = 'cash' | 'bank' | 'mixed' | 'partner_settlement'
export type PayrollSecureDeliveryChannel = 'portal' | 'paper'
export type PayrollPersonAddressType = 'residence' | 'mailing'
export type PayrollPersonContactType = 'email' | 'phone'
export type PayrollPersonIdentifierType = 'birth_number' | 'ecp' | 'vcp' | 'foreign_tax_identifier'
export type PayrollPersonAccountVerificationSource =
  | 'employee_confirmation'
  | 'bank_document'
  | 'user_verified'

export interface PayrollPersonIdentityHistory {
  id: number
  full_name: string
  first_name: string | null
  last_name: string | null
  birth_surname_masked: string | null
  effective_from: string
  effective_to: string | null
  row_version: number
}

export interface PayrollPersonAddress {
  id: number
  address_type: PayrollPersonAddressType
  address_masked: string
  effective_from: string
  effective_to: string | null
  row_version: number
}

export interface PayrollPersonContact {
  id: number
  contact_type: PayrollPersonContactType
  value_masked: string
  is_primary: boolean
  is_active: boolean
  row_version: number
}

export interface PayrollPersonIdentifier {
  id: number
  identifier_type: PayrollPersonIdentifierType
  value_masked: string
  row_version: number
}

export interface PayrollPersonAccount {
  id: number
  label: string
  bank_account_masked: string
  allocation_basis_points: number
  effective_from: string
  effective_to: string | null
  is_active: boolean
  row_version: number
  verification_source: PayrollPersonAccountVerificationSource | null
  verified_on: string | null
  verified_by: number | null
}

export interface PayrollPersonVerifiedAccount {
  id: number
  bank_account_masked: string
  verification_source: PayrollPersonAccountVerificationSource
  verified_on: string
  verified_by: number
  row_version: number
}

export interface PayrollPersonProfile {
  employee_id: number
  full_name: string
  profile_status: PayrollPersonProfileStatus
  payout_method: PayrollPayoutMethod
  partner_settlement_account_code: string | null
  cash_allocation_basis_points: number
  payout_effective_on: string | null
  secure_delivery_channel: PayrollSecureDeliveryChannel
  row_version: number
  identity_history: PayrollPersonIdentityHistory[]
  addresses: PayrollPersonAddress[]
  contacts: PayrollPersonContact[]
  identifiers: PayrollPersonIdentifier[]
  accounts: PayrollPersonAccount[]
  created_at: string | null
  updated_at: string | null
}

export interface PayrollPersonIdentityPayload {
  id?: number
  full_name: string
  first_name: string
  last_name: string
  birth_surname?: string | null
  birth_surname_source_id?: number
  effective_from: string
  effective_to: string | null
}

export interface PayrollPersonAddressPayload {
  id?: number
  address_type: PayrollPersonAddressType
  street_line?: string
  city?: string
  postal_code?: string
  country_code?: string
  effective_from: string
  effective_to: string | null
}

export interface PayrollPersonContactPayload {
  id?: number
  contact_type: PayrollPersonContactType
  value?: string | null
  is_primary: boolean
  is_active: boolean
}

export interface PayrollPersonIdentifierPayload {
  id?: number
  identifier_type: PayrollPersonIdentifierType
  value?: string | null
}

export interface PayrollPersonAccountPayload {
  id?: number
  label: string
  bank_account?: string | null
  allocation_basis_points: number
  effective_from: string
  effective_to: string | null
  is_active: boolean
}

export interface PayrollPersonProfilePayload {
  row_version: number
  profile_status: PayrollPersonEditableProfileStatus
  payout_method: PayrollPayoutMethod
  partner_settlement_account_code: string | null
  cash_allocation_basis_points: number
  payout_effective_on: string
  secure_delivery_channel: PayrollSecureDeliveryChannel
  identity_history: PayrollPersonIdentityPayload[]
  addresses: PayrollPersonAddressPayload[]
  contacts: PayrollPersonContactPayload[]
  identifiers: PayrollPersonIdentifierPayload[]
  accounts: PayrollPersonAccountPayload[]
}

export interface PayrollPersonQuickEditEmploymentPayload {
  id: number
  row_version: number
  monthly_gross_minor: number | null
  terms: PayrollEmploymentTermsPayload
}

export interface PayrollPersonQuickEditPayload {
  profile: PayrollPersonProfilePayload
  employment: PayrollPersonQuickEditEmploymentPayload | null
}

export interface PayrollPersonQuickEditResponse {
  profile: PayrollPersonProfile
  employment: PayrollEmployment | null
}

export interface PayrollPersonAccountVerificationPayload {
  verification_source: PayrollPersonAccountVerificationSource
  verified_on: string
  row_version: number
}

export type PayrollPayoutDestinationKind = 'bank' | 'cash' | 'partner_settlement'
export type PayrollPayoutAllocationKind = 'fixed' | 'percentage' | 'remainder'

/**
 * Výplatní pravidlo osoby — teprve ono říká, kam čistá mzda skutečně odejde.
 * `payout_method` na kartě je jen deklarace; bez pravidla se výplata nedá
 * zpracovat.
 *
 * `allocation_reference` generuje server (drží identitu vůči zmrazeným
 * alokacím), klient ji nikdy neposílá ani nemění.
 */
export interface PayrollPayoutRule {
  id: number
  supplier_id: number
  employee_id: number
  allocation_reference: string
  destination_kind: PayrollPayoutDestinationKind
  /** `account:<id>` u banky, kód účtu z osnovy u zápočtu, NULL u hotovosti. */
  destination_reference: string | null
  allocation_kind: PayrollPayoutAllocationKind
  amount_minor: number | null
  basis_points: number | null
  priority_no: number
  is_active: boolean
  row_version: number
  created_at: string | null
  updated_at: string | null
}

export interface PayrollPayoutRuleProposalRule {
  destination_kind: PayrollPayoutDestinationKind
  destination_reference: string | null
  allocation_kind: PayrollPayoutAllocationKind
  amount_minor: number | null
  basis_points: number | null
  priority_no: number
}

export interface PayrollPayoutRuleProposal {
  payout_method: PayrollPayoutMethod | null
  available: boolean
  applicable: boolean
  has_active_rules: boolean
  /** Česká věta ze serveru — zobrazuje se uživateli tak, jak přijde. */
  blocked_reason: string | null
  rules: PayrollPayoutRuleProposalRule[]
}

export interface PayrollPayoutRulesResponse {
  rules: PayrollPayoutRule[]
  proposal: PayrollPayoutRuleProposal
}

export interface PayrollPayoutRulePayload {
  destination_kind: PayrollPayoutDestinationKind
  destination_reference: string | null
  allocation_kind: PayrollPayoutAllocationKind
  amount_minor?: number | null
  basis_points?: number | null
  priority_no: number
  is_active: boolean
}

export type PayrollTimeCategory =
  | 'regular'
  | 'overtime'
  | 'night'
  | 'weekend'
  | 'holiday'
  | 'difficult_environment'

export interface PayrollTimeMonthState {
  id: number | null
  employment_id: number
  period_start: string
  status: 'open' | 'approved'
  revision_no: number
  row_version: number
  approved_at: string | null
  reopened_at: string | null
  reopen_reason: string | null
}

export interface PayrollCalendarDay {
  date: string
  weekday: number
  is_weekend: boolean
  is_holiday: boolean
  day_kind: 'workday' | 'non_working' | 'holiday'
  planned_minutes: number
  holiday_code: string | null
  holiday_name: string | null
}

export interface PayrollWorkCalendar {
  id: number
  employment_id: number
  name: string
  timezone_name: string
  schedule_type: 'regular' | 'irregular' | 'shift'
  week_pattern: Record<string, number>
  weekly_minutes: number
  valid_from: string
  valid_to: string | null
  row_version: number
  fund_minutes: number
  days: PayrollCalendarDay[]
}

export interface PayrollShift {
  id: number
  employment_id: number
  calendar_id: number | null
  series_key: string
  revision_no: number
  starts_at: string
  ends_at: string
  timezone_name: string
  break_minutes: number
  net_minutes: number
  remote_work: boolean
  standby_minutes: number
  status: 'draft' | 'published'
  row_version: number
}

export interface PayrollTimeEntry {
  id: number
  employment_id: number
  series_key: string
  revision_no: number
  category: PayrollTimeCategory
  starts_at: string
  ends_at: string
  timezone_name: string
  break_minutes: number
  net_minutes: number
  source_kind: 'manual' | 'import' | 'schedule'
  status: 'draft' | 'approved'
  row_version: number
}

export interface PayrollJmhzWorkSummaryPreview {
  derivation_version: string
  control_catalog_key: string | null
  control_manifest_sha256: string | null
  source_snapshot_sha256: string
  suggestions: {
    standard_fund_hours: string | null
    agreed_fund_hours: string | null
    weekly_work_hours: string | null
    evidence_days: number
    worked_hours: string | null
  }
  issues: Array<{ code: string; message: string }>
  requires_unworked_hours_followup: boolean
}

export interface PayrollJmhzWorkSummaryRevision {
  id: number
  time_month_id: number
  time_month_revision_no: number
  derivation_version: string
  source_snapshot_sha256: string
  summary_sha256: string
  confirmation_note: string
  conditional_blocks_confirmed: 1 | null
  unworked_hours_occurred: 0 | 1 | null
  work_obstacles_occurred: 0 | 1 | null
  unworked_total_millihours: number | null
  unworked_paid_millihours: number | null
  dpn_without_employer_compensation_millihours: number | null
  dpn_with_employer_compensation_millihours: number | null
  vacation_millihours: number | null
  care_millihours: number | null
  employee_obstacle_paid_millihours: number | null
  employer_obstacle_millihours: number | null
  approved_at: string
}

export interface PayrollJmhzWorkSummaryApproval {
  source_snapshot_sha256: string
  standard_fund_hours: string
  agreed_fund_hours: string
  weekly_work_hours: string
  worked_hours: string
  unworked_hours_occurred: boolean
  work_obstacles_occurred: boolean
  unworked_total_hours: string | null
  unworked_paid_hours: string | null
  dpn_without_employer_compensation_hours: string | null
  dpn_with_employer_compensation_hours: string | null
  vacation_hours: string | null
  care_hours: string | null
  employee_obstacle_paid_hours: string | null
  employer_obstacle_hours: string | null
  confirmation_note: string
}

export interface PayrollTimeOverviewItem {
  employment: {
    id: number
    employee_id: number
    code: string
    relation_type: PayrollRelationType
    status: string
    start_date: string | null
    end_date: string | null
    full_name: string
  }
  calendar: PayrollWorkCalendar | null
  month: PayrollTimeMonthState
  summary: {
    fund_minutes: number
    planned_minutes: number
    actual_minutes: number
    difference_minutes: number
    category_minutes: Record<PayrollTimeCategory, number>
    incomplete: boolean
  }
  jmhz_work_summary: {
    preview: PayrollJmhzWorkSummaryPreview | null
    current_revision: PayrollJmhzWorkSummaryRevision | null
  }
  shifts: PayrollShift[]
  entries: PayrollTimeEntry[]
}

export interface PayrollTimeOverview {
  period: string
  incomplete_only: boolean
  items: PayrollTimeOverviewItem[]
}

export interface PayrollTimeImportError {
  row_number: number
  error_code: string
  field_name: string | null
  error_message: string
}

export interface PayrollTimeImportPreview {
  format: 'csv' | 'xlsx'
  supported: boolean
  status: 'preview' | 'manual_review'
  period: string
  original_name: string
  total_rows: number
  accepted_rows: number
  rejected_rows: number
  duplicate_rows: number
  errors: PayrollTimeImportError[]
}

export type PayrollComponentKind =
  | 'base_wage'
  | 'hourly_wage'
  | 'task_wage'
  | 'bonus'
  | 'premium'
  | 'commission'
  | 'allowance'
  | 'compensation'
  | 'severance'
  | 'competitive_clause'
  | 'backpay'
  | 'non_cash'
  | 'benefit_meal'
  | 'benefit_vehicle'
  | 'benefit_pension'
  | 'benefit_care'
  | 'benefit_education'
  | 'benefit_recreation'
  | 'benefit_health'
  | 'risky_savings'
  | 'travel_reimbursement'
  | 'other'

export type PayrollComponentValueKind = 'monetary' | 'non_monetary'
export type PayrollComponentFrequency = 'regular' | 'one_off'
export type PayrollComponentTaxTreatment = 'included' | 'exempt' | 'withholding_candidate' | 'manual_review'
export type PayrollComponentInclusion = 'included' | 'excluded' | 'manual_review'

export interface PayrollComponent {
  id: number
  supplier_id: number
  code: string
  name: string
  component_kind: PayrollComponentKind
  value_kind: PayrollComponentValueKind
  frequency_kind: PayrollComponentFrequency
  tax_treatment: PayrollComponentTaxTreatment
  social_participation_treatment: PayrollComponentInclusion
  social_treatment: PayrollComponentInclusion
  health_participation_treatment: PayrollComponentInclusion
  health_treatment: PayrollComponentInclusion
  average_earning_treatment: PayrollComponentInclusion
  enforcement_treatment: PayrollComponentInclusion
  jmhz_treatment: PayrollComponentInclusion
  statistics_treatment: PayrollComponentInclusion
  accounting_debit_code: string | null
  accounting_credit_code: string | null
  annual_limit_minor: number | null
  valid_from: string
  valid_to: string | null
  is_active: boolean
  row_version: number
  created_at: string
  updated_at: string
}

export type PayrollComponentPayload = Omit<
  PayrollComponent,
  'id' | 'supplier_id' | 'row_version' | 'created_at' | 'updated_at'
>

export interface PayrollComponentJmhzTarget {
  attribute_id: string
  name: string
  xsd_mapping: string
  data_type: string
  monthly_marker: string
  parent_attribute_id: string | null
  ancestor_attribute_ids: string[]
  aggregation_role: 'detail' | 'catch_all_total'
  aggregation_scope: 'employment' | 'employee_summary'
}

export type PayrollVerifiedTriState = 'unverified' | 'no' | 'yes'

export interface PayrollEmploymentJmhzEvidenceOptions {
  package_key: string
  manifest_sha256: string
  external_codebooks: {
    overlay_key: string
    manifest_sha256: string
    snapshot_date: string
    effective_from: string
    verified_through: string
    base_spec_manifest_sha256: string
  }
  activity_codes: Array<{ code: string; label: string }>
  relationship_detail_codes: Array<{ code: string; label: string }>
  apz_instruments: Array<{ code: string; label: string }>
  countries: Array<{ code: string; label: string }>
}

export interface PayrollJmhzMunicipalityOption {
  code: string
  label: string
}

export interface PayrollComponentJmhzMapping {
  id: number
  component_definition_id: number
  package_key: string
  spec_manifest_sha256: string
  target_attribute_id: string
  target_attribute_name: string
  target_xsd_mapping: string
  is_active: boolean
  disabled_at: string | null
  row_version: number
  parent_attribute_id: string | null
  ancestor_attribute_ids: string[]
  aggregation_role: 'detail' | 'catch_all_total' | null
  aggregation_scope: 'employment' | 'employee_summary' | null
  topology_hash: string | null
  is_current_package: boolean
}

export type PayrollComponentJmhzMappingStatus =
  | 'configured'
  | 'missing'
  | 'excluded'
  | 'manual_review'

export interface PayrollComponentJmhzMappingState {
  component_id: number
  jmhz_treatment: PayrollComponentInclusion
  status: PayrollComponentJmhzMappingStatus
  mapping: PayrollComponentJmhzMapping | null
}

export type PayrollRecurringCalculationKind =
  | 'fixed_amount'
  | 'employment_gross_basis_points'
  | 'manual_review'

export type PayrollRecurringAllocationRule =
  | 'full_month'
  | 'calendar_days'
  | 'working_days'
  | 'hours'
  | 'manual_review'

export interface PayrollRecurringComponent {
  id: number
  supplier_id: number
  employee_id: number
  employment_id: number
  employment_code: string
  employee_name: string
  component_id: number
  component_code: string
  component_name: string
  calculation_kind: PayrollRecurringCalculationKind
  amount_minor: number | null
  rate_basis_points: number | null
  valid_from: string
  valid_to: string | null
  allocation_rule: PayrollRecurringAllocationRule
  maximum_amount_minor: number | null
  note: string | null
  is_active: boolean
  row_version: number
  created_by: number | null
  updated_by: number | null
  created_at: string
  updated_at: string
}

export interface PayrollRecurringComponentPayload {
  employment_id: number
  component_id: number
  calculation_kind: PayrollRecurringCalculationKind
  amount_minor: number | null
  rate_basis_points: number | null
  valid_from: string
  valid_to: string | null
  allocation_rule: PayrollRecurringAllocationRule
  maximum_amount_minor: number | null
  note: string | null
  is_active: boolean
}

export type PayrollInputSourceKind = 'manual' | 'recurring' | 'time' | 'absence' | 'import' | 'correction'
export type PayrollInputStatus = 'draft' | 'approved' | 'locked' | 'cancelled'

export interface PayrollInput {
  id: number
  supplier_id: number
  employee_id: number
  employee_name: string
  employment_id: number
  employment_code: string
  relation_type: PayrollRelationType
  component_id: number
  component_code: string
  component_name: string
  component_kind: PayrollComponentKind
  value_kind: PayrollComponentValueKind
  period_start: string
  source_period_start: string | null
  amount_minor: number
  quantity_milliunits: number | null
  source_kind: PayrollInputSourceKind
  external_id: string | null
  import_id: number | null
  recurring_component_id?: number | null
  status: PayrollInputStatus
  component_snapshot_json: string | null
  row_version: number
  created_by: number | null
  approved_by: number | null
  approved_at: string | null
  created_at: string
  updated_at: string
}

export interface PayrollInputPayload {
  employee_id: number
  employment_id: number
  component_id: number
  period: string
  source_period: string | null
  amount_minor: number
  quantity_milliunits: number | null
  source_kind: PayrollInputSourceKind
  external_id: string | null
}

export interface PayrollQuickInputRef {
  id: number
  amount_minor: number
  quantity_milliunits: number | null
  source_kind: PayrollInputSourceKind
  status: PayrollInputStatus
  row_version: number
  source_snapshot: Record<string, unknown> | null
}

export interface PayrollQuickInputRow {
  employee_id: number
  employment_id: number
  employment_row_version: number
  full_name: string
  birth_number_masked: string | null
  employment_code: string
  relation_type: PayrollRelationType
  effective_status: PayrollEmploymentStatus
  suspended_in_month: boolean
  base_amount_minor: number
  base_managed_elsewhere: boolean
  base_conflict: boolean
  partial_month: boolean
  base_requires_entry: boolean
  overtime_mode: 'hours' | 'amount'
  overtime_hours_milli: number | null
  overtime_amount_minor: number
  overtime_hourly_rate_minor: number | null
  overtime_average_snapshot_id: number | null
  overtime_average_snapshot_version: number | null
  overtime_hours_available: boolean
  overtime_hours_relation_supported: boolean
  overtime_managed_elsewhere: boolean
  overtime_conflict: boolean
  bonus_amount_minor: number
  bonus_managed_elsewhere: boolean
  bonus_conflict: boolean
  other_amount_minor: number
  non_monetary_amount_minor: number
  excluded_from_gross_amount_minor: number
  gross_preview_minor: number
  inputs: {
    base: PayrollQuickInputRef | null
    overtime: PayrollQuickInputRef | null
    bonus: PayrollQuickInputRef | null
  }
  blockers: string[]
}

export interface PayrollQuickInputMonth {
  period: string
  items: PayrollQuickInputRow[]
}

export interface PayrollQuickInputSavePayload {
  period: string
  rows: Array<{
    employment_id: number
    employment_row_version: number
    base_amount_minor: number
    overtime_mode: 'hours' | 'amount'
    overtime_hours_milli: number | null
    overtime_amount_minor: number | null
    overtime_average_snapshot_id: number | null
    overtime_average_snapshot_version: number | null
    bonus_amount_minor: number
    versions: {
      base: number | null
      overtime: number | null
      bonus: number | null
    }
  }>
}

export interface PayrollInputImpactMoney {
  minor_units: number
  currency: string
}

export interface PayrollInputPreview {
  support_status: PayrollSupportStatus
  blocker: string | null
  component_snapshot: Record<string, unknown>
  impact: Record<string, PayrollInputImpactMoney> | null
  annual_limit_minor: number | null
  annual_used_minor: number
  annual_after_minor: number
  annual_limit_exceeded: boolean
}

export interface PayrollRecurringMaterialization {
  period: string
  created_count: number
  replayed_count: number
  manual_review_count: number
  created: Array<{ recurring_component_id: number; input_id: number; amount_minor: number }>
  replayed: Array<{ recurring_component_id: number; input_id: number; amount_minor: number }>
  manual_review: Array<{
    recurring_component_id: number
    employment_id: number
    component_id: number
    reason: string
  }>
}

export interface PayrollInputImportIssue {
  row_number: number
  error_code: string
  field_name: string | null
  error_message: string
}

export interface PayrollInputImportPreviewRow {
  row_number: number
  payload: Record<string, unknown>
  impact: PayrollInputPreview
}

export interface PayrollInputImportPreview {
  format: 'csv' | 'xlsx'
  source_name: string
  period: string
  content_hash: string
  row_count: number
  accepted_count: number
  rejected_count: number
  duplicate_count: number
  rows: PayrollInputImportPreviewRow[]
  errors: PayrollInputImportIssue[]
  duplicates: PayrollInputImportIssue[]
}

export interface PayrollInputImportRow {
  id: number
  source_row_number: number
  external_id: string | null
  status: 'valid' | 'error' | 'accepted' | 'duplicate'
  input_id: number | null
  normalized_payload: Record<string, unknown>
  errors: Array<{ code: string; field: string | null; message: string }>
  created_at: string
}

export interface PayrollInputImportResult {
  id: number
  supplier_id: number
  period_start: string
  source_kind: 'csv' | 'xlsx' | 'api'
  source_name: string
  content_hash: string
  status: 'preview' | 'accepted' | 'partial' | 'rejected'
  row_count: number
  accepted_count: number
  rejected_count: number
  duplicate_count: number
  row_version: number
  accepted_at: string | null
  created_by: number | null
  created_at: string
  replayed: boolean
  rows: PayrollInputImportRow[]
}

export interface PayrollInputImportPayload {
  period: string
  format: 'csv' | 'xlsx'
  source_name: string
  content_base64: string
}

export interface PayrollEmployerAccounts {
  employment_gross_debit: string
  employment_gross_credit: string
  partner_gross_debit: string
  partner_gross_credit: string
  statutory_gross_debit: string
  statutory_gross_credit: string
  employer_insurance_debit: string
  social_insurance_credit: string
  health_insurance_credit: string
  income_tax_credit: string
  other_deductions_credit: string
  partner_settlement_credit: string
}

export interface PayrollAccountOption {
  id: number
  account_code: string
  name: string
  account_type: 'expense' | 'liability'
  is_synthetic: boolean
  parent_id: number | null
  is_active: boolean
}

export interface PayrollOffice {
  id: number
  code: string
  name: string
  social_security_variable_symbol: string | null
  is_active: boolean
  row_version: number
}

export interface PayrollEmployerSettings {
  supplier_id: number
  row_version: number
  employer_registration_number: string | null
  social_security_office_code: string | null
  default_health_insurer_code: string | null
  payroll_contact_name: string | null
  payroll_contact_email: string | null
  payroll_contact_phone: string | null
  default_office_code: string | null
  accounts: PayrollEmployerAccounts
  offices: PayrollOffice[]
  created_at: string | null
  updated_at: string | null
}

export interface PayrollEmployerSettingsResponse {
  settings: PayrollEmployerSettings
}

export interface PayrollOfficePayload {
  code: string
  name: string
  social_security_variable_symbol: string | null
  is_active: boolean
}

export interface PayrollEmployerSettingsPayload {
  row_version: number
  default_office_code: string
  employer_registration_number: string | null
  social_security_office_code: string | null
  default_health_insurer_code: string | null
  payroll_contact_name: string | null
  payroll_contact_email: string | null
  payroll_contact_phone: string | null
  accounts: PayrollEmployerAccounts
  offices: PayrollOfficePayload[]
}

export type PayrollRegzelEnvironment = 'production' | 'test'

export type PayrollSubmissionObligationStatus =
  | 'open'
  | 'prepared'
  | 'submitted'
  | 'fulfilled'
  | 'overdue'
  | 'cancelled'
  | 'manual_review'

export type PayrollSubmissionDeadlinePhase =
  | 'not_open'
  | 'open'
  | 'due_soon'
  | 'due_today'
  | 'overdue'
  | 'awaiting_result'
  | 'fulfilled'
  | 'action_required'
  | 'cancelled'

export interface PayrollSubmissionOverviewItem {
  id: number
  environment: PayrollRegzelEnvironment
  agenda_code: string
  subject_type: string
  subject_reference: string
  period_start: string
  period_end: string
  obligation_kind: string
  preferred_channel: string
  status: PayrollSubmissionObligationStatus
  row_version: number
  earliest_submission_on: string
  due_on: string
  calendar_basis: string
  deadline: {
    phase: PayrollSubmissionDeadlinePhase
    days_to_due: number
    is_action_required: boolean
    is_overdue: boolean
  }
  latest_submission: {
    id: number
    status: string
    submission_kind: string
    channel: string
    submitted_at: string | null
    decided_at: string | null
  } | null
}

export interface PayrollSubmissionOverviewResponse {
  environment: PayrollRegzelEnvironment
  period: string
  summary: {
    total: number
    open: number
    prepared: number
    submitted: number
    fulfilled: number
    overdue: number
    manual_review: number
    other: number
  }
  deadline_summary: Record<PayrollSubmissionDeadlinePhase, number>
  items: PayrollSubmissionOverviewItem[]
}

export interface PayrollSubmissionDetail {
  submission: {
    id: number
    environment: PayrollRegzelEnvironment
    obligation_id: number
    agenda_code: string
    subject_type: string
    subject_reference: string
    period_start: string
    period_end: string
    submission_kind: string
    channel: string
    status: string
    row_version: number
    source_revision_id: number | null
    corrects_submission_id: number | null
    correlation_reference: string | null
    submitted_at: string | null
    decided_at: string | null
    created_at: string
    updated_at: string
  }
  parts: Array<{
    id: number
    part_reference: string
    agenda_code: string
    subject_reference: string
    status: string
    source_entity_type: string
    source_entity_reference: string
    row_version: number
    created_at: string
    updated_at: string
  }>
  artifacts: Array<{
    id: number
    part_id: number | null
    artifact_kind: string
    direction: string
    mime_type: string
    byte_size: number
    xsd_version: string | null
    catalog_version: string | null
    channel: string
    created_at: string
  }>
  receipts: Array<{
    id: number
    part_id: number | null
    artifact_id: number
    receipt_reference: string
    correlation_reference: string | null
    protocol_code: string
    remote_status: string | null
    verification_status: string
    received_at: string
    created_at: string
  }>
  issues: Array<{
    id: number
    part_id: number | null
    severity: string
    validation_stage: string
    issue_code: string
    entity_type: string | null
    entity_reference: string | null
    is_resolved: boolean
    row_version: number
    resolved_at: string | null
    created_at: string
    updated_at: string
  }>
}

export type PayrollSubmissionInboxProblemKind =
  | 'due_soon'
  | 'due_today'
  | 'overdue'
  | 'rejected'
  | 'waiting_for_identity'
  | 'manual_review'

export type PayrollSubmissionInboxEscalationLevel = 'due_soon' | 'due_today' | 'overdue'

export type PayrollSubmissionInboxStatus = 'open' | 'acknowledged' | 'snoozed' | 'resolved'

export interface PayrollSubmissionInboxItem {
  id: number
  obligation_id: number
  submission_id: number | null
  agenda_code: string
  subject_type: string
  subject_reference: string
  period_start: string
  period_end: string
  due_on: string
  problem_kind: PayrollSubmissionInboxProblemKind
  escalation_level: PayrollSubmissionInboxEscalationLevel
  status: PayrollSubmissionInboxStatus
  snoozed_until: string | null
  snooze_reason: string | null
  acknowledged_at: string | null
  resolved_at: string | null
  row_version: number
  created_at: string
  updated_at: string
}

export interface PayrollSubmissionInboxResponse {
  environment: PayrollRegzelEnvironment
  summary: {
    total: number
    open: number
    acknowledged: number
    snoozed: number
  }
  items: PayrollSubmissionInboxItem[]
}

export interface PayrollHealthPaymentOverview {
  schema_reference: 'payroll-health-payment-overview.v1'
  document_kind: 'internal_health_payment_overview'
  official_submission: {
    supported: false
    reason_code: string
  }
  supplier_id: number
  run_id: number
  revision_id: number
  revision_no: number
  period: string
  currency_code: 'CZK'
  insurer: {
    code: string
  }
  source: {
    statutory_result_id: number
    statutory_result_hash: string
    ruleset_id: string
    ruleset_hash: string
  }
  totals: {
    person_count: number
    assessment_base_minor_units: number
    employee_contribution_minor_units: number
    employer_contribution_minor_units: number
    total_contribution_minor_units: number
  }
  people: Array<{
    employee_reference: string
    display_name: string
    assessment_base_minor_units: number
    employee_contribution_minor_units: number
    employer_contribution_minor_units: number
    total_contribution_minor_units: number
  }>
  sha256: string
  filename: string
}

export interface PayrollJmhzPvpojPreview {
  schema_reference: 'payroll-jmhz-pvpoj-preview.v1'
  document_kind: 'internal_jmhz_pvpoj_preview'
  workflow_status: 'preview_only'
  official_submission: {
    supported: false
    reason_code: string
  }
  xsd: {
    bundle_version: string
    schema_version: string
    entry_point: string
    namespace: string
  }
  supplier_id: number
  run_id: number
  revision_id: number
  revision_no: number
  period: string
  currency_code: 'CZK'
  source: {
    revision_input_hash: string
    statutory_result_id: number
    statutory_result_hash: string
    ruleset_id: string
    ruleset_hash: string
    social_liability_id: number
    social_liability_hash: string
  }
  pvpoj: {
    pojistne: {
      zakladZamestnavateleA: number
      pojistneZamestnavateleA: number
      pojistneZamestnavateleCelkem: number
      pojistneZamestnance: number
      pojistneCelkem: number
    }
    slevaZamestnavatele?: {
      pocetZamestnancu: number
      uhrnVymerovacichZakladu: number
      pojistneSleva: number
    }
    slevyZamestnancu?: {
      pocetZamestnancu: number
      uhrnVymerovacichZakladu: number
      pojistneSleva: number
    }
    pojistneUhrada: number
  }
  reconciliation: Array<{
    employee_reference: string
    relationship_references: string[]
    capped_assessment_base_minor_units: number
    employee_contribution_before_discount_minor_units: number
    employee_discount_minor_units: number
    employee_contribution_minor_units: number
  }>
  sha256: string
  filename: string
}

export interface PayrollJmhzOrdinaryEvidenceFacts {
  reportable_wage_deductions_recorded: false
  employee_social_discount_claimed: false
  specific_legal_fact_occurred: false
  ozp_employment_support_claimed: false
  deep_mining_work_occurred: false
}

export interface PayrollJmhzOrdinaryEvidence {
  id: number
  run_id: number
  revision_id: number
  revision_no: number
  period_start: string
  schema_reference: 'payroll-jmhz-ordinary-evidence.v1'
  source_manifest_sha256: string
  facts: PayrollJmhzOrdinaryEvidenceFacts
  confirmed_at: string
  created_at: string
  created: boolean
}

export interface PayrollJmhzPreparation {
  id: number
  environment: 'test' | 'production'
  run_id: number
  source_revision_id: number
  period_start: string
  scenario_key: string
  builder_version: string
  readiness_status: 'blocked' | 'source_ready'
  issue_count: number
  issues: Array<{
    code: string
    entity_type: string
    count: number
    attribute_ids: string[]
  }>
  source_manifest_sha256: string
  readiness_sha256: string
  snapshot_fingerprint: string
  official_submission_supported: false
  created: boolean
}

export interface PayrollJmhzXmlDryRunBlocker {
  code: string
  entity_type: string
  entity_id: number | null
  attribute_ids: string[]
}

export type PayrollJmhzControlOutcome =
  | 'passed'
  | 'failed'
  | 'not_applicable'
  | 'not_evaluable'
  | 'unimplemented'

export interface PayrollJmhzControlFinding {
  control_id: number
  name: string
  outcome: PayrollJmhzControlOutcome
  scope: string
  passability: 'blocking' | 'passable' | 'unavailable'
  technical: boolean
  part: string
  form_ordinal: number | null
  message: string
  attribute_ids: string[]
  error_code: number | null
}

export interface PayrollJmhzControlReport {
  schema_reference: string
  catalog_key: string
  catalog_manifest_sha256: string
  submittable: boolean
  counts: Record<PayrollJmhzControlOutcome, number>
  deviations: { control_id: number, reason: string }[]
  blocking: PayrollJmhzControlFinding[]
  warnings: PayrollJmhzControlFinding[]
  coverage_gaps: PayrollJmhzControlFinding[]
  evaluated: PayrollJmhzControlFinding[]
}

export interface PayrollJmhzXmlDryRun {
  status: 'blocked' | 'dry_run_valid' | 'dry_run_incomplete'
  preparation_id: number
  blockers: PayrollJmhzXmlDryRunBlocker[]
  controls?: PayrollJmhzControlReport
  deadline?: {
    period_start: string
    earliest_submission_on: string
    due_on: string
    calendar_basis: string
    ruleset_id: string
  } | null
  xml?: string
  xml_sha256?: string
  schema?: {
    package_key: string
    data_version: string
    bundle_sha256: string
    document_sha256: string
  }
  official_submission: {
    supported: false
    reason_code: string
    reason: string
  }
}

export interface PayrollRegzelProfile {
  supplier_id: number
  social_enterprise: boolean
  employment_agency: boolean
  protected_labor_market: boolean
  evidence_confirmed_at: string
  row_version: number
  updated_at: string
}

export interface PayrollRegzelProfilePayload {
  row_version: number
  social_enterprise: boolean
  employment_agency: boolean
  protected_labor_market: boolean
  evidence_confirmed: boolean
}

export interface PayrollRegzelSnapshot {
  id: number
  environment: PayrollRegzelEnvironment
  office_id: number
  document_type: 'REGZELDOPL25'
  interaction_code: 'supplemental_information'
  mapping_version: string
  xsd_version: string
  source_snapshot_hash: string
  xml_sha256: string
  xml_byte_size: number
  request_fingerprint?: string
  created_at?: string
  created?: boolean
}

export type PayrollBusinessDayRule = 'none' | 'previous_business_day' | 'next_business_day'
export type PayrollBalanceRoundingMode = 'exact_minor_units' | 'nearest_crown' | 'up_to_crown'
export type PayrollOptionalPolicyState = 'not_used' | 'manual_review' | 'configured'
export type PayrollDeliveryChannel = 'disabled' | 'employee_portal' | 'smime_email' | 'manual_handover'
export type PayrollPolicySourceKind = 'manual' | 'import' | 'migration' | 'system'

export interface PayrollEmployerPolicy {
  id: number
  supplier_id: number
  valid_from: string
  valid_to: string | null
  payday_day: number
  payday_month_offset: 0 | 1
  payday_business_day_rule: PayrollBusinessDayRule
  balance_rounding_mode: PayrollBalanceRoundingMode
  home_office_policy: PayrollOptionalPolicyState
  travel_expense_policy: PayrollOptionalPolicyState
  four_eyes_required: boolean
  automatic_calculation_enabled: boolean
  automatic_posting_enabled: boolean
  automatic_payments_enabled: boolean
  delivery_channel: PayrollDeliveryChannel
  delivery_verified_on: string | null
  source_kind: PayrollPolicySourceKind
  source_reference: string | null
  created_by: number | null
  updated_by: number | null
  row_version: number
  created_at: string
  updated_at: string
}

export type PayrollEmployerPolicyPayload = Omit<
  PayrollEmployerPolicy,
  'id' | 'supplier_id' | 'created_by' | 'updated_by' | 'created_at' | 'updated_at'
>

export type PayrollDimensionType = 'cost_center' | 'project' | 'activity'

export interface PayrollDimension {
  id: number
  supplier_id: number
  dimension_type: PayrollDimensionType
  code: string
  name: string
  valid_from: string
  valid_to: string | null
  is_active: boolean
  default_account_code: string | null
  created_by: number | null
  updated_by: number | null
  row_version: number
  created_at: string
  updated_at: string
}

export type PayrollDimensionPayload = Omit<
  PayrollDimension,
  'id' | 'supplier_id' | 'created_by' | 'updated_by' | 'created_at' | 'updated_at'
>

export interface PayrollEmploymentDimension {
  id: number
  supplier_id: number
  employment_id: number
  dimension_id: number
  dimension_type: PayrollDimensionType
  dimension_code: string
  dimension_name: string
  valid_from: string
  valid_to: string | null
  created_by: number | null
  updated_by: number | null
  row_version: number
  created_at: string
  updated_at: string
}

export interface PayrollEmploymentDimensionPayload {
  dimension_id: number
  valid_from: string
  valid_to: string | null
  row_version?: number
}

export interface PayrollSetupCheckItem {
  code: string
  status: 'ok' | 'blocked'
  message: string
}

export interface PayrollSetupCheck {
  ready: boolean
  effective_on: string
  policy_id: number | null
  checks: PayrollSetupCheckItem[]
  blockers: string[]
}

export type PayrollInstitutionType =
  | 'social_security'
  | 'tax_office'
  | 'health_insurer'
  | 'statutory_insurance'
  | 'other_recipient'

export type PayrollInstitutionAccountSource =
  | 'official_registry'
  | 'official_document'
  | 'institution_notice'
  | 'user_verified'
  | 'imported'

export interface PayrollInstitutionAccount {
  id: number
  supplier_id: number
  institution_id: number
  institution_type: PayrollInstitutionType
  institution_code: string
  institution_name: string
  bank_account_masked: string
  currency_code: string
  variable_symbol: string | null
  specific_symbol: string | null
  constant_symbol: string | null
  valid_from: string
  valid_to: string | null
  source_kind: PayrollInstitutionAccountSource
  source_reference: string
  verified_on: string
  verified_by: number | null
  row_version: number
  created_at: string
  updated_at: string
}

export interface PayrollInstitutionAccountCreatePayload {
  institution_type: PayrollInstitutionType
  institution_code: string
  institution_name: string
  bank_account: string
  currency_code: string
  variable_symbol: string | null
  specific_symbol: string | null
  constant_symbol: string | null
  valid_from: string
  valid_to: string | null
  source_kind: PayrollInstitutionAccountSource
  source_reference: string
  verified_on: string
}

export interface PayrollInstitutionAccountUpdatePayload {
  row_version: number
  institution_name: string
  variable_symbol: string | null
  specific_symbol: string | null
  constant_symbol: string | null
  valid_to: string | null
  source_kind: PayrollInstitutionAccountSource
  source_reference: string
  verified_on: string
}

export type PayrollDocumentKind =
  | 'payslip'
  | 'payroll_sheet'
  | 'taxable_income_advance_certificate'
  | 'taxable_income_withholding_certificate'
  | 'employment_certificate'
  | 'average_earnings_certificate'
  | 'monthly_bundle'

export type PayrollTaxCertificateKind = Extract<
  PayrollDocumentKind,
  | 'taxable_income_advance_certificate'
  | 'taxable_income_withholding_certificate'
>

export interface PayrollTaxCertificateGenerationPayload {
  supersedes_document_id: number | null
  correction_reason: string | null
}

export interface PayrollDocument {
  id: number
  run_id: number | null
  revision_id: number | null
  annual_revision_id?: number | null
  annual_revision_no?: number
  tax_year?: number
  purpose?: string
  revision_no?: number
  revision_status?: 'approved' | 'superseded'
  office_id?: number | null
  office_name?: string | null
  employee_id: number | null
  employee_name?: string | null
  employment_id?: number
  employment_end_date?: string
  employment_exit_revision_id?: number | null
  employment_exit_revision_no?: number
  document_kind: PayrollDocumentKind
  document_revision_no?: number
  supersedes_document_id?: number | null
  file_sha256: string
  size_bytes: number
  mime_type: 'application/pdf' | 'application/zip'
  suggested_filename: string
  created_at: string
}

export interface PayrollDocumentRevision {
  run_id: number
  revision_id: number
  revision_no: number
  status: 'approved' | 'superseded'
  office_id: number | null
  office_name: string | null
}

export interface PayrollDocumentList {
  period: string
  revisions: PayrollDocumentRevision[]
  items: PayrollDocument[]
}

export interface PayrollAnnualDocumentList {
  year: number
  items: PayrollDocument[]
}

export interface PayrollEmploymentExitReadinessItem {
  available: boolean
  readiness_code: string | null
}

export interface PayrollEmploymentExitDocumentList {
  employment_id: number
  readiness: {
    employment_certificate: PayrollEmploymentExitReadinessItem & {
      deduction_claim_ids: number[]
    }
    average_earnings_certificate: PayrollEmploymentExitReadinessItem & {
      decisive_year: number | null
      decisive_quarter: number | null
    }
  }
  items: PayrollDocument[]
}

export interface PayrollEmploymentCertificateDeductionEvidence {
  source_claim_id: number
  beneficiary: string
  ordering_authority: string
  decision_reference: string
}

export interface PayrollEmploymentCertificatePensionPeriod {
  category: 'I' | 'II'
  from: string
  to: string
}

export interface PayrollEmploymentCertificateEvidence {
  work_description: string
  achieved_qualification: string
  exposure_assessment_complete: boolean
  exposure_facts: string[]
  deduction_assessment_complete: boolean
  deductions: PayrollEmploymentCertificateDeductionEvidence[]
  pension_category_assessment_complete: boolean
  pre1993_pension_category_periods: PayrollEmploymentCertificatePensionPeriod[]
  dpp_issuance_basis: null | 'wage_deductions' | 'sickness_insurance'
  correction_reason: string | null
}

export type PayrollRunStatus =
  | 'draft'
  | 'inputs_locked'
  | 'calculated'
  | 'reviewed'
  | 'approved'
  | 'posted'
  | 'payment_ready'
  | 'paid'
  | 'closed'
  | 'correction_pending'
  | 'reopened'
  | 'cancelled'

export type PayrollRunCommand =
  | 'lock_inputs'
  | 'calculate'
  | 'review'
  | 'approve'
  | 'request_correction'
  | 'reopen'
  | 'cancel'
  | 'close'

export interface PayrollRunValidation {
  id: number
  severity: 'blocker' | 'warning' | 'info'
  code: string
  entity_type: string
  entity_id: number | null
  message: string
  remediation_path: string | null
  requires_override: boolean
}

export interface PayrollIncomeTaxRate {
  decimal: string
  numerator: number
  scale: number
  denominator: number
}

export interface PayrollIncomeTaxRateStep {
  label: string
  input_minor_units: number
  rate: PayrollIncomeTaxRate
  unrounded_numerator: number
  unrounded_denominator: number
  rounding_mode: string
  output_minor_units: number
}

export interface PayrollIncomeTaxAdvanceResult {
  taxable_income_minor_units: number
  rounded_tax_base_minor_units: number
  low_rate_base_minor_units: number
  high_rate_base_minor_units: number
  rate_steps: PayrollIncomeTaxRateStep[]
  tax_before_credits_minor_units: number
  non_refundable_credits_minor_units: number
  child_credit_minor_units: number
  tax_bonus_eligible: boolean
  tax_after_credits_minor_units: number
  tax_bonus_minor_units: number
  ruleset_id: string
  ruleset_hash: string
}

export interface PayrollIncomeTaxRelationshipResult {
  relationship_reference: string
  kind:
    | 'employment'
    | 'small-scale-employment'
    | 'dpp'
    | 'dpc'
    | 'managing-partner-dependent'
    | 'statutory-body'
  taxable_base_minor_units: number
  regime: 'advance' | 'withholding' | 'manual-review'
  withholding_group: string | null
}

export interface PayrollIncomeTaxWithholdingGroup {
  group: string
  base_minor_units: number
  tax_minor_units: number
  rate_step: PayrollIncomeTaxRateStep
}

export interface PayrollIncomeTaxResult {
  status: 'calculated' | 'manual-review'
  calculation_date: string
  employee_reference: string
  payer_reference: string
  relationships: PayrollIncomeTaxRelationshipResult[]
  advance_tax: PayrollIncomeTaxAdvanceResult | null
  withholding_groups: PayrollIncomeTaxWithholdingGroup[]
  withholding_base_minor_units: number
  withholding_tax_minor_units: number
  claimed_non_refundable_credits_minor_units: number
  applied_non_refundable_credits_minor_units: number
  claimed_child_credit_minor_units: number
  applied_child_credit_minor_units: number
  annual_accumulator: Record<string, unknown>
  issues: string[]
  policy_id: string
  policy_hash: string
  ruleset_id: string
  ruleset_hash: string
}

export interface PayrollRunResultPerson {
  employee_id: number
  statutory?: {
    person_reference: string
    status: 'calculated' | 'manual_review' | 'error'
    income_tax?: PayrollIncomeTaxResult
    issues?: string[]
  }
}

export interface PayrollRunResultSnapshot {
  totals?: {
    cash_payable_minor?: number
    enforcement_withheld_minor?: number
    payable_after_enforcement_minor?: number
  }
  people?: PayrollRunResultPerson[]
}

export interface PayrollRun {
  id: number
  supplier_id: number
  office_id: number | null
  period_start: string
  payment_date: string
  status: PayrollRunStatus
  current_revision_no: number
  row_version: number
  revision_id: number | null
  revision_no: number | null
  revision_status: string | null
  payment_materialization_supported: boolean
  can_delete: boolean
  result_snapshot: PayrollRunResultSnapshot | null
  available_commands: PayrollRunCommand[]
  validations: PayrollRunValidation[]
}

export interface PayrollRunCommandResponse {
  command: PayrollRunCommand
  from_status: PayrollRunStatus
  to_status: PayrollRunStatus
  run: PayrollRun
  revision: Record<string, unknown> | null
  idempotent_replay: boolean
}

export type PayrollDependantRelation =
  | 'child_own'
  | 'child_adopted'
  | 'child_in_care'
  | 'child_of_spouse'
  | 'grandchild'
  | 'spouse'
  | 'partner'

export type PayrollDependantClaimReason =
  | 'own_household'
  | 'shared_custody'
  | 'adoption'
  | 'foster_care'
  | 'study_continues'
  | 'other'

export type PayrollDependantBlocker =
  | 'relation_not_child'
  | 'evidence_unverified'
  | 'shared_household_unconfirmed'
  | 'other_claimant_not_excluded'
  | 'declaration_missing'
  | 'outside_existence'
  | 'superseded'

export interface PayrollDependantCredit {
  status: 'calculated' | 'manual_review'
  rate_key: string | null
  monthly_credit_minor_units: number | null
  manual_review_reason: string | null
}

export interface PayrollDependantClaim {
  id: number
  child_reference: string
  child_order: number
  claim_reason: PayrollDependantClaimReason | null
  ztp_p: boolean
  evidence_status: 'verified' | 'unverified'
  evidence_reference: string | null
  shared_household_confirmed: boolean
  other_claimant_excluded: boolean
  effective_from: string
  effective_to: string | null
  superseded_by_id: number | null
  is_frozen: boolean
  blockers: PayrollDependantBlocker[]
  credit: PayrollDependantCredit
  row_version: number
}

export interface PayrollDependant {
  id: number
  relation: PayrollDependantRelation
  full_name: string
  birth_date: string
  birth_number_masked: string | null
  has_birth_number: boolean
  ztp_p: boolean
  student: boolean
  existence_from: string
  existence_to: string | null
  note: string | null
  can_claim_monthly: boolean
  row_version: number
  claims: PayrollDependantClaim[]
}

export interface PayrollDependantsResponse {
  employee_id: number
  effective_on: string
  frozen_through: string | null
  dependants: PayrollDependant[]
}

export interface PayrollDependantPayload {
  relation: PayrollDependantRelation
  full_name: string
  birth_date: string
  birth_number?: string | null
  ztp_p: boolean
  student: boolean
  existence_from: string
  existence_to: string | null
  note: string | null
  row_version?: number
}

export interface PayrollDependantClaimPayload {
  child_order: number
  claim_reason: PayrollDependantClaimReason | null
  evidence_status: 'verified' | 'unverified'
  evidence_reference: string | null
  shared_household_confirmed: boolean
  other_claimant_excluded: boolean
  ztp_p: boolean
  effective_from: string
  effective_to: string | null
  row_version?: number
}

/**
 * Volba podpisového certifikátu pro mzdová podání na ČSSZ.
 *
 * Certifikáty se nahrávají v jednom trezoru (Systém → Elektronické podpisy);
 * tady se jen vybírá, KTERÝ z nich podepisuje podání téhle firmy — a odděleně
 * pro testovací a produkční prostředí, protože testovací certifikát bývá jiný
 * a záměna se pozná až z protokolu ČSSZ, typicky po termínu.
 */
export type PayrollSigningEnvironment = 'production' | 'test'

export interface PayrollSigningCertificate {
  id: number
  label: string
  subject: string
  issuer: string
  /** Kanonický hex (bez oddělovačů a vedoucích nul); `null`, když ho neznáme. */
  serial_hex: string | null
  /** Totéž decimálně — ČSSZ tiskne sériové číslo na papíře v tomhle zápisu. */
  serial_decimal: string | null
  valid_from: string | null
  valid_to: string | null
  expired: boolean
  not_yet_valid: boolean
  usable_now: boolean
  expires_in_days: number | null
  enabled_for_supplier: boolean
  ik_mpsv_present: boolean
}

export interface PayrollSigningWarning {
  code: string
  message: string
}

export interface PayrollSigningProfile {
  environment: string
  credential_id: number
  owner_user_id: number
  cssz_registered_serial: string | null
  row_version: number
  created_at: string | null
  updated_at: string | null
  /** `false`, když volbu uložil jiný uživatel svým certifikátem. */
  certificate_accessible: boolean
  certificate: PayrollSigningCertificate | null
  expired: boolean
}

export interface PayrollSigningProfileView {
  environment: PayrollSigningEnvironment
  environments: PayrollSigningEnvironment[]
  storage_available: boolean
  profile: PayrollSigningProfile | null
  certificates: PayrollSigningCertificate[]
  warnings: PayrollSigningWarning[]
}

export interface PayrollSigningProfileResult {
  environment: PayrollSigningEnvironment
  profile: PayrollSigningProfile
  warnings: PayrollSigningWarning[]
}

export interface PayrollSigningProfilePayload {
  environment: PayrollSigningEnvironment
  credential_id: number
  /** Prázdné = uložit bez ověření proti oznámení o pověření. */
  cssz_registered_serial?: string | null
  /** Posílá se jen při ZMĚNĚ existující volby — u prvního uložení ho backend odmítne. */
  row_version?: number | null
}

/**
 * Ledger odeslaných měsíčních hlášení na ČSSZ.
 *
 * Přírůstkový a nikdy se nepřepisuje: každý pokus o odeslání zakládá vlastní
 * řádek, takže několik pokusů k jednomu podání je normální stav a zároveň
 * doklad o tom, co se dělo — ne nepořádek, který by se měl schovat.
 */
export type PayrollJmhzTransportEnvironment = 'test' | 'production'

/**
 * Šest stavů pokusu. `awaiting_protocol` NENÍ přijaté podání: ČSSZ potvrzuje
 * převzetí okamžitě a o výsledku rozhoduje až později. Hotovo znamená teprve
 * `completed`, tedy „dotáhli jsme protokol o zpracování".
 */
export type PayrollJmhzTransportStatus =
  | 'prepared'
  | 'sent'
  | 'awaiting_protocol'
  | 'completed'
  | 'failed'
  | 'expired'

export interface PayrollJmhzTransportAttempt {
  id: number
  supplier_id: number
  environment: string
  submission_id: number
  channel: string
  attempt_no: number
  status: PayrollJmhzTransportStatus
  /** CorrelationID přidělené branou VREP; bez něj se na výsledek nelze zeptat. */
  correlation_reference: string | null
  request_sha256: string | null
  response_http_status: number | null
  error_code: string | null
  error_message: string | null
  next_retry_at: string | null
  sent_at: string | null
  completed_at: string | null
  row_version: number
  created_by: number | null
  created_at: string
  updated_at: string
}

export interface PayrollJmhzTransportHistory {
  environment: PayrollJmhzTransportEnvironment
  attempts: PayrollJmhzTransportAttempt[]
}

/** Potvrzení o PŘEVZETÍ zprávy, ne o přijetí podání. */
export interface PayrollJmhzTransportAcknowledgement {
  correlation_id: string
  poll_interval_seconds: number | null
  gateway_timestamp: string | null
}

/** Kontrola z katalogu ČSSZ dohledaná ke kódu chyby. */
export interface PayrollJmhzProtocolControl {
  name: string
  detail: string | null
  area: string | null
  category: string | null
  /** Atributy, kterých se kontrola týká — bez nich se hláška nedá dohledat v datech. */
  attribute_ids: string[]
}

export interface PayrollJmhzProtocolError {
  /** Číselný kód z protokolu (DIS = ID kontroly + 20000, cJMHZ = + 40000). */
  code: number
  message: string
  origin: 'dis' | 'cjmhz' | 'platform'
  control_id: number | null
  form_guid: string | null
  ik_mpsv: string | null
  id_ppv: string | null
  /**
   * `null` u chyby, kterou náš katalog nezná — prostor kódů ČSSZ je širší.
   * Taková chyba se ukazuje syrová, nikdy se neskrývá.
   */
  control: PayrollJmhzProtocolControl | null
}

/** `status` je jméno případu výčtu na backendu, tedy PascalCase. */
export type PayrollJmhzProtocolStatus =
  | 'ProcessedAndComplete'
  | 'NotAccepted'
  | 'Rejected'
  | 'PartiallyAccepted'
  | 'Processing'
  | 'ContainsPassableErrors'

export interface PayrollJmhzProtocolReport {
  status: PayrollJmhzProtocolStatus
  errors: PayrollJmhzProtocolError[]
}

export interface PayrollJmhzTransportPoll {
  attempt: PayrollJmhzTransportAttempt
  acknowledgement: PayrollJmhzTransportAcknowledgement | null
  /** `true` teprve tehdy, když ČSSZ vrátila protokol o zpracování. */
  settled: boolean
  report: PayrollJmhzProtocolReport | null
}

/**
 * Protokol ČSSZ načtený ze souboru z datové schránky.
 *
 * Podání odeslané cizím softwarem naše aplikace nezná, takže přehled stavu
 * odeslání by u takové firmy zůstal prázdný, i když podala. Načtený protokol
 * je doklad o podání — ale NENÍ to náš pokus o odeslání, a v přehledu se tak
 * ani nesmí tvářit.
 */
export type PayrollJmhzImportedProtocolKind =
  | 'processing'
  | 'completeness'
  | 'partial_submission'

export interface PayrollJmhzImportedProtocol {
  id: number
  supplier_id: number
  environment: string
  protocol_kind: PayrollJmhzImportedProtocolKind
  /** Ověřený variabilní symbol; cizí protokol se neuloží. */
  variable_symbol: string
  period_month: number | null
  period_year: number | null
  /** `idPodani` — GUID, kterým se protokol páruje k podání. */
  submission_guid: string | null
  correlation_reference: string | null
  /** Kód stavu hlášení 1–6 podle číselníku ČSSZ. */
  status_code: number
  status_name: PayrollJmhzProtocolStatus
  error_count: number
  protocol_dated_at: string | null
  submitted_at: string | null
  source_filename: string | null
  payload_sha256: string
  row_version: number
  imported_by: number | null
  created_at: string
  updated_at: string
  /** Vysvětlené chyby; počítají se z uloženého originálu při každém čtení. */
  errors?: PayrollJmhzProtocolError[]
  /** `false`, když se uložený originál nepodařilo znovu přečíst. */
  detail_available?: boolean
}

export interface PayrollJmhzImportedProtocolHistory {
  environment: PayrollJmhzTransportEnvironment
  protocols: PayrollJmhzImportedProtocol[]
}

export interface PayrollJmhzImportedProtocolResult {
  environment: PayrollJmhzTransportEnvironment
  protocol: PayrollJmhzImportedProtocol
  /** `false` u opakovaného načtení téhož protokolu — řádek se přepsal. */
  created: boolean
  errors: PayrollJmhzProtocolError[]
}

export const payrollApi = {
  capabilities: () =>
    api.get<PayrollCapabilitiesResponse>('/payroll/capabilities').then(response => response.data),
  activation: () =>
    api.get<{ state: PayrollModuleState }>('/payroll/settings/activation').then(response => response.data.state),
  setActivation: (payload: { enabled: boolean; start_period: string | null; row_version: number }) =>
    api.put<{ state: PayrollModuleState }>('/payroll/settings/activation', payload).then(response => response.data.state),
  people: () =>
    api.get<PayrollPeopleResponse>('/payroll/people').then(response => response.data.items),
  createPerson: (payload: PayrollPersonCreatePayload) =>
    api.post<PayrollPersonResponse>('/payroll/people', payload)
      .then(response => response.data.person),
  person: (id: number) =>
    api.get<PayrollPersonResponse>(`/payroll/people/${id}`).then(response => response.data.person),
  personProfile: (id: number) =>
    api.get<{ profile: PayrollPersonProfile }>(`/payroll/people/${id}/profile`)
      .then(response => response.data.profile),
  savePersonProfile: (id: number, payload: PayrollPersonProfilePayload) =>
    api.put<{ profile: PayrollPersonProfile }>(`/payroll/people/${id}/profile`, payload)
      .then(response => response.data.profile),
  personDependants: (id: number) =>
    api.get<PayrollDependantsResponse>(`/payroll/people/${id}/dependants`)
      .then(response => response.data),
  createPersonDependant: (id: number, payload: PayrollDependantPayload) =>
    api.post<PayrollDependantsResponse>(`/payroll/people/${id}/dependants`, payload)
      .then(response => response.data),
  savePersonDependant: (id: number, dependantId: number, payload: PayrollDependantPayload) =>
    api.put<PayrollDependantsResponse>(
      `/payroll/people/${id}/dependants/${dependantId}`,
      payload,
    ).then(response => response.data),
  createPersonDependantClaim: (
    id: number,
    dependantId: number,
    payload: PayrollDependantClaimPayload,
  ) =>
    api.post<PayrollDependantsResponse>(
      `/payroll/people/${id}/dependants/${dependantId}/claims`,
      payload,
    ).then(response => response.data),
  savePersonDependantClaim: (
    id: number,
    dependantId: number,
    claimId: number,
    payload: PayrollDependantClaimPayload,
  ) =>
    api.put<PayrollDependantsResponse>(
      `/payroll/people/${id}/dependants/${dependantId}/claims/${claimId}`,
      payload,
    ).then(response => response.data),
  savePersonQuickEdit: (id: number, payload: PayrollPersonQuickEditPayload) =>
    api.put<PayrollPersonQuickEditResponse>(`/payroll/people/${id}/quick-edit`, payload)
      .then(response => response.data),
  verifyPersonAccount: (
    personId: number,
    accountId: number,
    payload: PayrollPersonAccountVerificationPayload,
  ) =>
    api.post<{ account: PayrollPersonVerifiedAccount }>(
      `/payroll/people/${personId}/accounts/${accountId}/verify`,
      payload,
    ).then(response => response.data.account),
  personPayoutRules: (personId: number) =>
    api.get<PayrollPayoutRulesResponse>(
      `/payroll/people/${personId}/payout-rules`,
    ).then(response => response.data),
  createPersonPayoutRule: (personId: number, payload: PayrollPayoutRulePayload) =>
    api.post<{ rule: PayrollPayoutRule }>(
      `/payroll/people/${personId}/payout-rules`,
      payload,
    ).then(response => response.data.rule),
  updatePersonPayoutRule: (
    personId: number,
    ruleId: number,
    payload: PayrollPayoutRulePayload & { row_version: number },
  ) =>
    api.put<{ rule: PayrollPayoutRule }>(
      `/payroll/people/${personId}/payout-rules/${ruleId}`,
      payload,
    ).then(response => response.data.rule),
  // Server pravidlo jen deaktivuje (zmrazené alokace na něj odkazují), proto
  // DELETE vrací celý řádek. `row_version` jde v těle — axios ho u DELETE
  // posílá přes `data`.
  deactivatePersonPayoutRule: (personId: number, ruleId: number, rowVersion: number) =>
    api.delete<{ rule: PayrollPayoutRule }>(
      `/payroll/people/${personId}/payout-rules/${ruleId}`,
      { data: { row_version: rowVersion } },
    ).then(response => response.data.rule),
  applyPersonPayoutRuleDefaults: (personId: number) =>
    api.post<PayrollPayoutRulesResponse>(
      `/payroll/people/${personId}/payout-rules/apply-defaults`,
    ).then(response => response.data),
  createEmployment: (personId: number, payload: PayrollEmploymentCreatePayload) =>
    api.post<{ employment: PayrollEmployment }>(`/payroll/people/${personId}/employments`, payload)
      .then(response => response.data.employment),
  addEmploymentTerms: (employmentId: number, rowVersion: number, payload: PayrollEmploymentTermsPayload) =>
    api.put<{ employment: PayrollEmployment }>(`/payroll/employments/${employmentId}/terms`, {
      row_version: rowVersion,
      ...payload,
    }).then(response => response.data.employment),
  employmentJmhzEvidenceOptions: () =>
    api.get<{ options: PayrollEmploymentJmhzEvidenceOptions }>(
      '/payroll/jmhz/employment-evidence-options',
    ).then(response => response.data.options),
  searchJmhzMunicipalities: (query: string, limit = 20) =>
    api.get<{ items: PayrollJmhzMunicipalityOption[] }>(
      '/payroll/jmhz/municipalities',
      { params: { q: query, limit } },
    ).then(response => response.data.items),
  transitionEmployment: (
    employmentId: number,
    target: PayrollEmploymentStatus,
    payload: { row_version: number; effective_on: string; note?: string | null },
  ) =>
    api.post<{ employment: PayrollEmployment }>(
      `/payroll/employments/${employmentId}/transitions/${target}`,
      payload,
    ).then(response => response.data.employment),
  updateEmploymentChecklist: (
    employmentId: number,
    itemKey: string,
    payload: { row_version: number; status: PayrollChecklistStatus; note?: string | null },
  ) =>
    api.put<{ employment: PayrollEmployment }>(
      `/payroll/employments/${employmentId}/checklist/${itemKey}`,
      payload,
    ).then(response => response.data.employment),
  accountOptions: () =>
    api.get<{ accounts: PayrollAccountOption[] }>('/payroll/settings/account-options')
      .then(response => response.data.accounts),
  employerSettings: () =>
    api.get<PayrollEmployerSettingsResponse>('/payroll/settings/employer').then(response => response.data.settings),
  saveEmployerSettings: (payload: PayrollEmployerSettingsPayload) =>
    api.put<PayrollEmployerSettingsResponse>('/payroll/settings/employer', payload).then(response => response.data.settings),
  submissionOverview: (
    environment: PayrollRegzelEnvironment,
    period: string,
  ) =>
    api.get<PayrollSubmissionOverviewResponse>('/payroll/submissions/overview', {
      params: { environment, period },
    }).then(response => response.data),
  submissionDetail: (submissionId: number) =>
    api.get<PayrollSubmissionDetail>(`/payroll/submissions/${submissionId}`)
      .then(response => response.data),
  submissionInbox: (environment: PayrollRegzelEnvironment) =>
    api.get<PayrollSubmissionInboxResponse>('/payroll/submissions/inbox', {
      params: { environment },
    }).then(response => response.data),
  acknowledgeSubmissionInboxItem: (itemId: number, rowVersion: number) =>
    api.post<{ id: number; status: string; row_version: number }>(
      `/payroll/submissions/inbox/${itemId}/acknowledge`,
      { row_version: rowVersion },
    ).then(response => response.data),
  snoozeSubmissionInboxItem: (
    itemId: number,
    rowVersion: number,
    snoozedUntil: string,
    reason: string,
  ) =>
    api.post<{ id: number; status: string; row_version: number; snoozed_until: string }>(
      `/payroll/submissions/inbox/${itemId}/snooze`,
      { row_version: rowVersion, snoozed_until: snoozedUntil, reason },
    ).then(response => response.data),
  downloadSubmissionArtifact: async (
    submissionId: number,
    artifact: PayrollSubmissionDetail['artifacts'][number],
  ): Promise<void> => {
    const grant = await api.post<{ token: string; expires_at: string }>(
      `/payroll/submissions/${submissionId}/artifacts/${artifact.id}/download-grant`,
    ).then(response => response.data)
    let response
    try {
      response = await api.get<Blob>(
        `/payroll/submissions/${submissionId}/artifacts/${artifact.id}/download`,
        {
          responseType: 'blob',
          headers: { 'X-Payroll-Download-Token': grant.token },
        },
      )
    } catch (error: any) {
      const data = error?.response?.data
      if (data instanceof Blob) {
        try {
          error.response.data = JSON.parse(await data.text())
        } catch {
          error.response.data = data
        }
      }
      throw error
    }
    const disposition = response.headers['content-disposition']
    const matchedFilename = typeof disposition === 'string'
      ? /filename="([^"]+)"/u.exec(disposition)?.[1]
      : undefined
    const extension = artifact.mime_type === 'application/xml'
      ? 'xml'
      : artifact.mime_type === 'application/pdf'
        ? 'pdf'
        : artifact.mime_type === 'application/zip'
          ? 'zip'
          : artifact.mime_type === 'application/json'
            ? 'json'
            : 'bin'
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = matchedFilename
        ?? `mzdove-podani-${submissionId}-artefakt-${artifact.id}.${extension}`
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  jmhzPvpojPreview: (revisionId: number) =>
    api.get<PayrollJmhzPvpojPreview>(
      `/payroll/submissions/jmhz-pvpoj/${revisionId}`,
    ).then(response => response.data),
  jmhzOrdinaryEvidence: (revisionId: number) =>
    api.get<{ evidence: PayrollJmhzOrdinaryEvidence | null }>(
      `/payroll/submissions/jmhz-ordinary-evidence/${revisionId}`,
    ).then(response => response.data.evidence),
  confirmJmhzOrdinaryEvidence: (
    revisionId: number,
    idempotencyKey: string,
  ) => api.post<PayrollJmhzOrdinaryEvidence>(
    `/payroll/submissions/jmhz-ordinary-evidence/${revisionId}`,
    {
      facts: {
        reportable_wage_deductions_recorded: false,
        employee_social_discount_claimed: false,
        specific_legal_fact_occurred: false,
        ozp_employment_support_claimed: false,
        deep_mining_work_occurred: false,
      },
      evidence_confirmed: true,
    },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  ).then(response => response.data),
  freezeJmhzPreparation: (
    revisionId: number,
    idempotencyKey: string,
    environment: 'test' | 'production' = 'test',
  ) => api.post<PayrollJmhzPreparation>(
    `/payroll/submissions/jmhz-preparation/${revisionId}`,
    { environment },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  ).then(response => response.data),
  jmhzXmlDryRun: (
    preparationId: number,
    environment: 'test' | 'production' = 'test',
  ) => api.get<PayrollJmhzXmlDryRun>(
    `/payroll/submissions/jmhz-xml-dry-run/${preparationId}`,
    { params: { environment } },
  ).then(response => response.data),
  downloadJmhzPvpojPreview: async (
    preview: PayrollJmhzPvpojPreview,
  ): Promise<void> => {
    const response = await api.get<Blob>(
      `/payroll/submissions/jmhz-pvpoj/${preview.revision_id}/download`,
      { responseType: 'blob' },
    )
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = preview.filename
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  healthPaymentOverviews: (revisionId: number) =>
    api.get<{
      items: PayrollHealthPaymentOverview[]
      electronic_submission: { supported: false; reason_code: string }
    }>(`/payroll/submissions/health-overviews/${revisionId}`)
      .then(response => response.data),
  downloadHealthPaymentOverview: async (
    overview: PayrollHealthPaymentOverview,
  ): Promise<void> => {
    const response = await api.get<Blob>(
      `/payroll/submissions/health-overviews/${overview.revision_id}/${overview.insurer.code}/download`,
      { responseType: 'blob' },
    )
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = overview.filename
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  regzelProfile: () =>
    api.get<{ profile: PayrollRegzelProfile | null }>('/payroll/submissions/regzel/profile')
      .then(response => response.data.profile),
  saveRegzelProfile: (payload: PayrollRegzelProfilePayload) =>
    api.put<{ profile: PayrollRegzelProfile }>('/payroll/submissions/regzel/profile', payload)
      .then(response => response.data.profile),
  regzelSnapshots: (environment: PayrollRegzelEnvironment) =>
    api.get<{ items: PayrollRegzelSnapshot[] }>('/payroll/submissions/regzel/snapshots', {
      params: { environment },
    }).then(response => response.data.items),
  prepareRegzel: (payload: {
    office_id: number
    environment: PayrollRegzelEnvironment
    evidence_confirmed: boolean
    idempotency_key: string
  }) =>
    api.post<{ snapshot: PayrollRegzelSnapshot }>('/payroll/submissions/regzel/prepare', payload)
      .then(response => response.data.snapshot),
  downloadRegzelSnapshot: async (snapshot: PayrollRegzelSnapshot): Promise<void> => {
    const response = await api.get<Blob>(
      `/payroll/submissions/regzel/snapshots/${snapshot.id}/xml`,
      {
        params: { environment: snapshot.environment },
        responseType: 'blob',
      },
    )
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = `REGZELDOPL25-${snapshot.environment === 'test' ? 'TEST' : 'PRODUKCE'}-${snapshot.id}.xml`
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  employerPolicies: (effectiveOn?: string) =>
    api.get<{ policies: PayrollEmployerPolicy[] }>('/payroll/settings/policies', {
      params: effectiveOn ? { effective_on: effectiveOn } : undefined,
    }).then(response => response.data.policies),
  createEmployerPolicy: (payload: PayrollEmployerPolicyPayload) =>
    api.post<{ policy: PayrollEmployerPolicy }>('/payroll/settings/policies', payload)
      .then(response => response.data.policy),
  updateEmployerPolicy: (id: number, payload: PayrollEmployerPolicyPayload) =>
    api.put<{ policy: PayrollEmployerPolicy }>(`/payroll/settings/policies/${id}`, payload)
      .then(response => response.data.policy),
  payrollSetupCheck: (effectiveOn: string) =>
    api.get<{ setup: PayrollSetupCheck }>('/payroll/setup-check', {
      params: { effective_on: effectiveOn },
    }).then(response => response.data.setup),
  institutionAccounts: (effectiveOn?: string) =>
    api.get<{ accounts: PayrollInstitutionAccount[] }>('/payroll/settings/institution-accounts', {
      params: effectiveOn ? { effective_on: effectiveOn } : undefined,
    }).then(response => response.data.accounts),
  createInstitutionAccount: (payload: PayrollInstitutionAccountCreatePayload) =>
    api.post<{ account: PayrollInstitutionAccount }>('/payroll/settings/institution-accounts', payload)
      .then(response => response.data.account),
  updateInstitutionAccount: (id: number, payload: PayrollInstitutionAccountUpdatePayload) =>
    api.put<{ account: PayrollInstitutionAccount }>(`/payroll/settings/institution-accounts/${id}`, payload)
      .then(response => response.data.account),
  payrollDimensions: (dimensionType?: PayrollDimensionType) =>
    api.get<{ dimensions: PayrollDimension[] }>('/payroll/settings/dimensions', {
      params: dimensionType ? { type: dimensionType } : undefined,
    }).then(response => response.data.dimensions),
  createPayrollDimension: (payload: PayrollDimensionPayload) =>
    api.post<{ dimension: PayrollDimension }>('/payroll/settings/dimensions', payload)
      .then(response => response.data.dimension),
  updatePayrollDimension: (id: number, payload: PayrollDimensionPayload) =>
    api.put<{ dimension: PayrollDimension }>(`/payroll/settings/dimensions/${id}`, payload)
      .then(response => response.data.dimension),
  deletePayrollDimension: (id: number) =>
    api.delete<{ deleted: boolean }>(`/payroll/settings/dimensions/${id}`)
      .then(response => response.data.deleted),
  employmentDimensions: (employmentId: number) =>
    api.get<{ dimensions: PayrollEmploymentDimension[] }>(`/payroll/employments/${employmentId}/dimensions`)
      .then(response => response.data.dimensions),
  createEmploymentDimension: (employmentId: number, payload: PayrollEmploymentDimensionPayload) =>
    api.post<{ dimension: PayrollEmploymentDimension }>(
      `/payroll/employments/${employmentId}/dimensions`,
      payload,
    ).then(response => response.data.dimension),
  updateEmploymentDimension: (
    employmentId: number,
    assignmentId: number,
    payload: PayrollEmploymentDimensionPayload,
  ) =>
    api.put<{ dimension: PayrollEmploymentDimension }>(
      `/payroll/employments/${employmentId}/dimensions/${assignmentId}`,
      payload,
    ).then(response => response.data.dimension),
  listDocuments: (period: string) =>
    api.get<PayrollDocumentList>('/payroll/documents', { params: { period } })
      .then(response => response.data),
  listAnnualDocuments: (year: number) =>
    api.get<PayrollAnnualDocumentList>('/payroll/documents/annual', { params: { year } })
      .then(response => response.data),
  generatePayrollSheet: (employeeId: number, year: number) =>
    api.post<PayrollDocument>(
      `/payroll/people/${employeeId}/documents/payroll-sheet/${year}`,
      {},
    ).then(response => response.data),
  generateTaxCertificate: (
    employeeId: number,
    year: number,
    kind: PayrollTaxCertificateKind,
    payload: PayrollTaxCertificateGenerationPayload,
  ) => {
    const routeKind = kind === 'taxable_income_advance_certificate'
      ? 'advance'
      : 'withholding'
    return api.post<PayrollDocument>(
      `/payroll/people/${employeeId}/documents/tax-certificate/${routeKind}/${year}`,
      payload,
    ).then(response => response.data)
  },
  generateMonthlyBundle: (runId: number, revisionId: number, idempotencyKey: string) =>
    api.post<PayrollDocument>(
      `/payroll/runs/${runId}/revisions/${revisionId}/documents/monthly-bundle`,
      {},
      { headers: { 'Idempotency-Key': idempotencyKey } },
    ).then(response => response.data),
  employmentExitDocuments: (employmentId: number) =>
    api.get<PayrollEmploymentExitDocumentList>(
      `/payroll/employments/${employmentId}/documents/exit`,
    ).then(response => response.data),
  generateEmploymentCertificate: (
    employmentId: number,
    payload: PayrollEmploymentCertificateEvidence,
    idempotencyKey: string,
  ) =>
    api.post<PayrollDocument>(
      `/payroll/employments/${employmentId}/documents/exit/employment-certificate`,
      payload,
      { headers: { 'Idempotency-Key': idempotencyKey } },
    ).then(response => response.data),
  runs: (period?: string) =>
    api.get<{ runs: PayrollRun[] }>('/payroll/runs', {
      params: period ? { period } : undefined,
    }).then(response => response.data.runs),
  createRun: (payload: {
    period_start: string
    payment_date: string
    office_id: number | null
  }) =>
    api.post<{ run: PayrollRun }>('/payroll/runs', payload)
      .then(response => response.data.run),
  deleteRun: (runId: number, rowVersion: number) =>
    api.delete<void>(`/payroll/runs/${runId}`, {
      data: { row_version: rowVersion },
    }).then(() => undefined),
  commandRun: (
    runId: number,
    command: PayrollRunCommand,
    payload: { row_version: number; reason?: string },
    idempotencyKey: string,
  ) =>
    api.post<PayrollRunCommandResponse>(
      `/payroll/runs/${runId}/commands/${command}`,
      payload,
      { headers: { 'Idempotency-Key': idempotencyKey } },
    ).then(response => response.data),
  downloadDocument: async (payrollDocument: PayrollDocument): Promise<void> => {
    const grant = await api.post<{ token: string; expires_at: string }>(
      `/payroll/documents/${payrollDocument.id}/download-grant`,
    ).then(response => response.data)
    const response = await api.get<Blob>(
      `/payroll/documents/${payrollDocument.id}/download`,
      {
        responseType: 'blob',
        headers: { 'X-Payroll-Download-Token': grant.token },
      },
    )
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = payrollDocument.suggested_filename
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  timeMonth: (period: string, incomplete = false) =>
    api.get<PayrollTimeOverview>('/payroll/time/month', { params: { period, incomplete: incomplete ? 1 : 0 } })
      .then(response => response.data),
  saveTimeCalendar: (employmentId: number, payload: Record<string, unknown>) =>
    api.put<{ calendar: PayrollWorkCalendar }>(`/payroll/time/calendars/${employmentId}`, payload)
      .then(response => response.data.calendar),
  saveShift: (payload: Record<string, unknown>) =>
    api.post<{ shift: PayrollShift; month: PayrollTimeMonthState }>('/payroll/time/shifts', payload)
      .then(response => response.data),
  saveTimeEntry: (payload: Record<string, unknown>) =>
    api.post<{ entry: PayrollTimeEntry; month: PayrollTimeMonthState }>('/payroll/time/entries', payload)
      .then(response => response.data),
  previewTimeImport: (payload: { period: string; format: 'csv' | 'xlsx'; original_name: string; content: string }) =>
    api.post<{ preview: PayrollTimeImportPreview }>('/payroll/time/imports/preview', payload)
      .then(response => response.data.preview),
  importTime: (payload: { period: string; format: 'csv' | 'xlsx'; original_name: string; content: string }) =>
    api.post<{ import: Record<string, unknown> }>('/payroll/time/imports', payload)
      .then(response => response.data.import),
  approveTimeMonth: (period: string, payload: {
    employment_id: number
    row_version: number
    jmhz_work_summary?: PayrollJmhzWorkSummaryApproval
  }) =>
    api.post<{ month: PayrollTimeMonthState }>(`/payroll/time/months/${period}/approve`, payload)
      .then(response => response.data.month),
  reopenTimeMonth: (period: string, payload: { employment_id: number; row_version: number; reason: string }) =>
    api.post<{ month: PayrollTimeMonthState }>(`/payroll/time/months/${period}/reopen`, payload)
      .then(response => response.data.month),
  components: (effectiveOn?: string) =>
    api.get<{ components: PayrollComponent[] }>('/payroll/components', {
      params: effectiveOn ? { effective_on: effectiveOn } : undefined,
    }).then(response => response.data.components),
  createComponent: (payload: PayrollComponentPayload) =>
    api.post<{ component: PayrollComponent }>('/payroll/components', payload)
      .then(response => response.data.component),
  updateComponent: (id: number, rowVersion: number, payload: PayrollComponentPayload) =>
    api.put<{ component: PayrollComponent }>(`/payroll/components/${id}`, {
      ...payload,
      row_version: rowVersion,
    }).then(response => response.data.component),
  componentJmhzTargets: () =>
    api.get<{
      package_key: string
      manifest_sha256: string
      topology_hash: string
      targets: PayrollComponentJmhzTarget[]
    }>('/payroll/components/jmhz-targets').then(response => response.data),
  componentJmhzMappings: () =>
    api.get<{ items: PayrollComponentJmhzMappingState[] }>('/payroll/components/jmhz-mappings')
      .then(response => response.data.items),
  saveComponentJmhzMapping: (
    componentId: number,
    targetAttributeId: string,
    rowVersion: number | null,
  ) => api.put<PayrollComponentJmhzMappingState>(
    `/payroll/components/${componentId}/jmhz-mapping`,
    { target_attribute_id: targetAttributeId, row_version: rowVersion },
  ).then(response => response.data),
  removeComponentJmhzMapping: (componentId: number, rowVersion: number) =>
    api.delete(`/payroll/components/${componentId}/jmhz-mapping`, {
      data: { row_version: rowVersion },
    }),
  recurringComponents: (employmentId?: number) =>
    api.get<{ recurring_components: PayrollRecurringComponent[] }>('/payroll/recurring-components', {
      params: employmentId ? { employment_id: employmentId } : undefined,
    }).then(response => response.data.recurring_components),
  createRecurringComponent: (payload: PayrollRecurringComponentPayload) =>
    api.post<{ recurring_component: PayrollRecurringComponent }>('/payroll/recurring-components', payload)
      .then(response => response.data.recurring_component),
  updateRecurringComponent: (
    id: number,
    rowVersion: number,
    payload: PayrollRecurringComponentPayload,
  ) =>
    api.put<{ recurring_component: PayrollRecurringComponent }>(`/payroll/recurring-components/${id}`, {
      ...payload,
      row_version: rowVersion,
    }).then(response => response.data.recurring_component),
  materializeRecurringComponents: (period: string) =>
    api.post<{ materialization: PayrollRecurringMaterialization }>(
      '/payroll/recurring-components/materialize',
      { period },
    ).then(response => response.data.materialization),
  inputs: (period: string) =>
    api.get<{ inputs: PayrollInput[] }>('/payroll/inputs', { params: { period } })
      .then(response => response.data.inputs),
  quickInputs: (period: string) =>
    api.get<{ month: PayrollQuickInputMonth }>('/payroll/quick-inputs', { params: { period } })
      .then(response => response.data.month),
  saveQuickInputs: (payload: PayrollQuickInputSavePayload) =>
    api.put<{ month: PayrollQuickInputMonth }>('/payroll/quick-inputs', payload)
      .then(response => response.data.month),
  previewInput: (payload: PayrollInputPayload) =>
    api.post<{ preview: PayrollInputPreview }>('/payroll/inputs/preview', payload)
      .then(response => response.data.preview),
  createInput: (payload: PayrollInputPayload) =>
    api.post<{ input: PayrollInput }>('/payroll/inputs', payload)
      .then(response => response.data.input),
  updateInput: (id: number, rowVersion: number, payload: PayrollInputPayload) =>
    api.put<{ input: PayrollInput }>(`/payroll/inputs/${id}`, {
      ...payload,
      row_version: rowVersion,
    }).then(response => response.data.input),
  approveInput: (id: number, rowVersion: number) =>
    api.post<{ input: PayrollInput }>(`/payroll/inputs/${id}/approve`, {
      row_version: rowVersion,
    }).then(response => response.data.input),
  previewInputImport: (payload: PayrollInputImportPayload) =>
    api.post<{ preview: PayrollInputImportPreview }>('/payroll/input-imports/preview', payload)
      .then(response => response.data.preview),
  applyInputImport: (payload: PayrollInputImportPayload) =>
    api.post<{ import: PayrollInputImportResult }>('/payroll/input-imports/apply', payload)
      .then(response => response.data.import),
  signingProfile: (environment: PayrollSigningEnvironment) =>
    api.get<PayrollSigningProfileView>('/payroll/submissions/signing-profile', {
      params: { environment },
    }).then(response => response.data),
  saveSigningProfile: (
    payload: PayrollSigningProfilePayload,
    proof: EpoStepUpProof,
  ) => api.put<PayrollSigningProfileResult>('/payroll/submissions/signing-profile', {
    environment: payload.environment,
    credential_id: payload.credential_id,
    cssz_registered_serial: payload.cssz_registered_serial ?? '',
    // Klíč se vynechá úplně, když volba ještě neexistuje: backend bere i `null`
    // jako „neposláno", ale posílat pole, které nemá význam, jen svádí k tomu
    // začít ho posílat i s nesmyslnou hodnotou.
    ...(payload.row_version ? { row_version: payload.row_version } : {}),
    ...stepUpProofBody(proof),
  }).then(response => response.data),
  deleteSigningProfile: (
    environment: PayrollSigningEnvironment,
    proof: EpoStepUpProof,
  ) => api.delete<{ environment: PayrollSigningEnvironment; deleted: boolean }>(
    '/payroll/submissions/signing-profile',
    { data: { environment, ...stepUpProofBody(proof) } },
  ).then(response => response.data),
  /** Posledních 50 pokusů o odeslání, od nejnovějšího. */
  jmhzTransportHistory: (environment: PayrollJmhzTransportEnvironment) =>
    api.get<PayrollJmhzTransportHistory>('/payroll/submissions/jmhz-transport', {
      params: { environment },
    }).then(response => response.data),
  /**
   * Dotaz na výsledek. Variabilní symbol zaměstnavatele je povinný — brána VREP
   * si jím ověřuje, že se ptá ten, kdo podával.
   */
  pollJmhzTransportAttempt: (
    attemptId: number,
    variableSymbol: string,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.get<PayrollJmhzTransportPoll>(
    `/payroll/submissions/jmhz-transport/${attemptId}`,
    { params: { variable_symbol: variableSymbol, environment } },
  ).then(response => response.data),
  /**
   * Uzavření transakce. Podací protokol ho vyžaduje, ale až po dotažení
   * protokolu — uzavřít dřív znamená přijít o výsledek.
   */
  closeJmhzTransportAttempt: (
    attemptId: number,
    variableSymbol: string,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.post<{ closed: boolean }>(
    `/payroll/submissions/jmhz-transport/${attemptId}/close`,
    { environment },
    { params: { variable_symbol: variableSymbol, environment } },
  ).then(response => response.data),
  /** Protokoly načtené ze souboru, od nejnovějšího období. */
  jmhzImportedProtocols: (environment: PayrollJmhzTransportEnvironment) =>
    api.get<PayrollJmhzImportedProtocolHistory>(
      '/payroll/submissions/jmhz-protocol-import',
      { params: { environment } },
    ).then(response => response.data),
  /**
   * Načte XML protokol z datové schránky. Server ho odmítne, pokud jeho
   * variabilní symbol nepatří téhle firmě — cizí doklad se neuloží.
   */
  importJmhzProtocol: (
    file: File,
    environment: PayrollJmhzTransportEnvironment,
  ) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('environment', environment)
    return api.post<PayrollJmhzImportedProtocolResult>(
      '/payroll/submissions/jmhz-protocol-import',
      fd,
      {
        params: { environment },
        headers: { 'Content-Type': 'multipart/form-data' },
      },
    ).then(response => response.data)
  },
}
