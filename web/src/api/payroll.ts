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

export const payrollApi = {
  capabilities: () =>
    api.get<PayrollCapabilitiesResponse>('/payroll/capabilities').then(response => response.data),
  activation: () =>
    api.get<{ state: PayrollModuleState }>('/payroll/settings/activation').then(response => response.data.state),
  setActivation: (payload: { enabled: boolean; start_period: string | null; row_version: number }) =>
    api.put<{ state: PayrollModuleState }>('/payroll/settings/activation', payload).then(response => response.data.state),
}
