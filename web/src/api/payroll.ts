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
}
