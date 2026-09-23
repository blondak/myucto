import { api } from './client'

/** Sekce profilu firmy, zrcadlí CompanyProfileFormat::SECTIONS (pořadí nahrání). */
export const COMPANY_PROFILE_SECTIONS = [
  'company',
  'tax_profile',
  'accounting_settings',
  'statement_overrides',
  'dimensions',
  'dimension_defaults',
  'dimension_rules',
  'posting_rules',
  'bank_rule_templates',
  'bank_posting_rules',
] as const

export type CompanyProfileSection = typeof COMPANY_PROFILE_SECTIONS[number]

export interface CompanyProfile {
  format: string
  version: number
  exported_at?: string
  app_version?: string | null
  company?: { name?: string, ic?: string | null }
  sections: Record<string, unknown>
}

export interface CompanyProfileSectionReport {
  created: number
  updated: number
  unchanged: number
  removed: number
  changes: string[]
  warnings: string[]
}

export interface CompanyProfileImportResult {
  dry_run: boolean
  changed: number
  warnings: string[]
  sections: Partial<Record<CompanyProfileSection, CompanyProfileSectionReport>>
}

export const companyProfileApi = {
  export: () => api.get<CompanyProfile>('/settings/company-profile').then(r => r.data),
  import: (profile: CompanyProfile, dryRun: boolean) =>
    api.post<CompanyProfileImportResult>('/settings/company-profile/import', { profile, dry_run: dryRun }).then(r => r.data),
}
