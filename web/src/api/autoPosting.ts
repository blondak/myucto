import { api } from './client'

export type PolicyLevel = 'off' | 'suggest' | 'auto'
export type AutomationPreset = 'off' | 'suggest' | 'assisted' | 'full'

export interface PolicyRow {
  operation_type: string
  level: PolicyLevel
  is_default: boolean
  effective_level: PolicyLevel
}

export interface AutoPostingPolicy {
  automation_level: AutomationPreset
  automation_daily_limit_czk: number | null
  automation_digest_enabled: boolean
  automation_digest_hour: number
  rows: PolicyRow[]
}

export interface AutoPostingPolicyPayload {
  automation_level?: AutomationPreset
  automation_daily_limit_czk?: number | null
  automation_digest_enabled?: boolean
  automation_digest_hour?: number
  rows?: Array<{ operation_type: string; level: PolicyLevel }>
}

export interface BankRuleTemplate {
  template_key: string
  name: string
  direction: 'incoming' | 'outgoing'
  operation_type: string
  counterparty_bank: string | null
  counterparty_prefix: string | null
  vs_placeholder: string | null
  vs_value: string | null
  message_contains: string | null
  rule_key: string
  debit_account_code: string
  credit_account_code: string
  default_priority: number
  already_instantiated: boolean
  rule_id: number | null
}

export interface InstantiateTemplatePayload {
  name?: string
  variable_symbol?: string
  amount_min?: number
  amount_max?: number
  auto_amount_cap?: number
}

export const autoPostingApi = {
  getPolicy: () =>
    api.get<AutoPostingPolicy>('/accounting/auto-posting-policy').then(r => r.data),
  putPolicy: (payload: AutoPostingPolicyPayload) =>
    api.put<AutoPostingPolicy>('/accounting/auto-posting-policy', payload).then(r => r.data),
  listTemplates: () =>
    api.get<BankRuleTemplate[]>('/accounting/bank-rule-templates').then(r => r.data),
  instantiateTemplate: <TRule = unknown>(key: string, payload: InstantiateTemplatePayload = {}) =>
    api.post<{ rule: TRule }>(`/accounting/bank-rule-templates/${encodeURIComponent(key)}/instantiate`, payload)
      .then(r => r.data),
}
