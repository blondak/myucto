import { api } from './client'

export const kbPlusCredentialFields = [
  'client_registration_api_key', 'oauth_api_key', 'adaa_api_key', 'batchda_api_key', 'certificate_p12', 'certificate_password',
] as const
export type KbPlusCredentialField = typeof kbPlusCredentialFields[number]
export type KbPlusCredentials = Record<KbPlusCredentialField, string>
export const kbPlusApiPlans = ['basic', 'plus'] as const
export type KbPlusApiPlan = typeof kbPlusApiPlans[number]

export interface KbPlusOnboardingStatus {
  provider: 'kb_plus'
  status: 'not_registered' | 'registered' | 'registration_pending' | 'authorization_pending' | 'connected' | 'expired'
  server_ready: boolean
  blockers: string[]
  required_fields: KbPlusCredentialField[]
  registration_fields?: KbPlusCredentialField[]
  optional_fields?: KbPlusCredentialField[]
  api_plan?: KbPlusApiPlan | null
  capabilities: { statement_import: boolean; payment_batch_submission: boolean; payment_batch_status?: KbPlusPaymentBatchStatus }
  expires_at?: string | null
}
export type KbPlusPaymentBatchStatus = 'available' | 'not_registered' | 'plan_basic' | 'registration_scope_missing' | 'authorization_scope_missing' | 'unknown'
export type KbPlusStartRequest = Partial<KbPlusCredentials> & { payment_batches?: boolean; api_plan?: KbPlusApiPlan }

export const kbPlusOnboardingApi = {
  status: (currencyId: number) => api.get<KbPlusOnboardingStatus>(`/settings/bank-connections/${currencyId}/kb-plus/onboarding`).then(r => r.data),
  start: (currencyId: number, credentials: KbPlusStartRequest) =>
    api.post<{ status: 'registration_pending' | 'authorization_pending'; redirect_url: string; expires_at: string }>(
      `/settings/bank-connections/${currencyId}/kb-plus/onboarding`, credentials,
    ).then(r => r.data),
  plan: (currencyId: number, plan: KbPlusApiPlan) =>
    api.put<KbPlusOnboardingStatus>(`/settings/bank-connections/${currencyId}/kb-plus/plan`, { api_plan: plan }).then(r => r.data),
}
