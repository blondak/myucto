import { api } from './client'

export type DomainPurpose = 'portal' | 'public_links' | 'all'
export type DomainStatus = 'pending' | 'verified' | 'active' | 'disabled' | 'verification_failed'

export interface SupplierDomain {
  id: number
  supplier_id: number
  hostname: string
  purpose: DomainPurpose
  status: DomainStatus
  is_primary: boolean
  is_primary_portal: boolean
  is_primary_public: boolean
  verified_at: string | null
  last_checked_at: string | null
  verification_error: string | null
  dns: { type: 'TXT'; name: string; value: string }
  verification_url: string
  portal_url: string
  public_base_url: string
}

export interface VerificationChecks {
  verified: boolean
  dns: boolean
  https: boolean
  error: string | null
}

export const domainsApi = {
  list: () => api.get<SupplierDomain[]>('/settings/domains').then((r) => r.data),
  create: (hostname: string, purpose: DomainPurpose, isPrimary: boolean) =>
    api.post<SupplierDomain>('/settings/domains', { hostname, purpose, is_primary: isPrimary }).then((r) => r.data),
  update: (id: number, purpose: DomainPurpose, isPrimary: boolean) =>
    api.put<SupplierDomain>(`/settings/domains/${id}`, { purpose, is_primary: isPrimary }).then((r) => r.data),
  rotateChallenge: (id: number) =>
    api.post<SupplierDomain>(`/settings/domains/${id}/challenge`, {}).then((r) => r.data),
  verify: (id: number) =>
    api.post<{ domain: SupplierDomain; checks: VerificationChecks }>(`/settings/domains/${id}/verify`, {}).then((r) => r.data),
  activate: (id: number, stepUpToken: string, isPrimary = true) =>
    api.post<SupplierDomain>(`/settings/domains/${id}/activate`, {
      step_up_token: stepUpToken,
      is_primary: isPrimary,
    }).then((r) => r.data),
  disable: (id: number) => api.post(`/settings/domains/${id}/disable`, {}).then(() => undefined),
  delete: (id: number) => api.delete(`/settings/domains/${id}`).then(() => undefined),
}
