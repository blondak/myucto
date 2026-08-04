import { api } from './client'

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
  cz_isco_code: string | null
  activity_code: string | null
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
  'id' | 'office_code' | 'effective_to' | 'row_version' | 'created_at'
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

export type PayrollPersonProfileStatus = 'missing' | 'legacy' | 'setup' | 'ready'
export type PayrollPersonEditableProfileStatus = Exclude<PayrollPersonProfileStatus, 'missing'>
export type PayrollPayoutMethod = 'cash' | 'bank' | 'mixed'
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
  cash_allocation_basis_points: number
  payout_effective_on: string
  secure_delivery_channel: PayrollSecureDeliveryChannel
  identity_history: PayrollPersonIdentityPayload[]
  addresses: PayrollPersonAddressPayload[]
  contacts: PayrollPersonContactPayload[]
  identifiers: PayrollPersonIdentifierPayload[]
  accounts: PayrollPersonAccountPayload[]
}

export interface PayrollPersonAccountVerificationPayload {
  verification_source: PayrollPersonAccountVerificationSource
  verified_on: string
  row_version: number
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
  full_name: string
  birth_number_masked: string | null
  employment_code: string
  relation_type: PayrollRelationType
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
  overtime_managed_elsewhere: boolean
  overtime_conflict: boolean
  bonus_amount_minor: number
  bonus_managed_elsewhere: boolean
  bonus_conflict: boolean
  other_amount_minor: number
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

export const payrollApi = {
  capabilities: () =>
    api.get<PayrollCapabilitiesResponse>('/payroll/capabilities').then(response => response.data),
  activation: () =>
    api.get<{ state: PayrollModuleState }>('/payroll/settings/activation').then(response => response.data.state),
  setActivation: (payload: { enabled: boolean; start_period: string | null; row_version: number }) =>
    api.put<{ state: PayrollModuleState }>('/payroll/settings/activation', payload).then(response => response.data.state),
  people: () =>
    api.get<PayrollPeopleResponse>('/payroll/people').then(response => response.data.items),
  person: (id: number) =>
    api.get<PayrollPersonResponse>(`/payroll/people/${id}`).then(response => response.data.person),
  personProfile: (id: number) =>
    api.get<{ profile: PayrollPersonProfile }>(`/payroll/people/${id}/profile`)
      .then(response => response.data.profile),
  savePersonProfile: (id: number, payload: PayrollPersonProfilePayload) =>
    api.put<{ profile: PayrollPersonProfile }>(`/payroll/people/${id}/profile`, payload)
      .then(response => response.data.profile),
  verifyPersonAccount: (
    personId: number,
    accountId: number,
    payload: PayrollPersonAccountVerificationPayload,
  ) =>
    api.post<{ account: PayrollPersonVerifiedAccount }>(
      `/payroll/people/${personId}/accounts/${accountId}/verify`,
      payload,
    ).then(response => response.data.account),
  createEmployment: (personId: number, payload: PayrollEmploymentCreatePayload) =>
    api.post<{ employment: PayrollEmployment }>(`/payroll/people/${personId}/employments`, payload)
      .then(response => response.data.employment),
  addEmploymentTerms: (employmentId: number, rowVersion: number, payload: PayrollEmploymentTermsPayload) =>
    api.put<{ employment: PayrollEmployment }>(`/payroll/employments/${employmentId}/terms`, {
      row_version: rowVersion,
      ...payload,
    }).then(response => response.data.employment),
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
  approveTimeMonth: (period: string, payload: { employment_id: number; row_version: number }) =>
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
}
