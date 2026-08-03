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

export type PayrollRelationType = 'employment' | 'dpp' | 'dpc' | 'partner_dependent' | 'statutory_body'

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
  code: string
  relation_type: PayrollRelationType
  status: string
  start_date: string | null
  end_date: string | null
  is_legacy_projection: boolean
  monthly_gross_minor: number | null
  row_version: number
  accounting: PayrollEmploymentAccounting
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

export interface PayrollOffice {
  id: number
  code: string
  name: string
  is_active: boolean
  row_version: number
}

export interface PayrollEmployerSettings {
  supplier_id: number
  row_version: number
  employer_registration_number: string | null
  social_security_office_code: string | null
  health_insurance_payer_number: string | null
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
  is_active: boolean
}

export interface PayrollEmployerSettingsPayload {
  row_version: number
  default_office_code: string
  employer_registration_number: string | null
  social_security_office_code: string | null
  health_insurance_payer_number: string | null
  default_health_insurer_code: string | null
  payroll_contact_name: string | null
  payroll_contact_email: string | null
  payroll_contact_phone: string | null
  accounts: PayrollEmployerAccounts
  offices: PayrollOfficePayload[]
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
  employerSettings: () =>
    api.get<PayrollEmployerSettingsResponse>('/payroll/settings/employer').then(response => response.data.settings),
  saveEmployerSettings: (payload: PayrollEmployerSettingsPayload) =>
    api.put<PayrollEmployerSettingsResponse>('/payroll/settings/employer', payload).then(response => response.data.settings),
}
