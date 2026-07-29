import { api } from './client'

export type LicenseStateKind = 'trial' | 'trial_expired' | 'active' | 'overage' | 'degraded'

/** Kompaktní stav licence z /auth/me — pro bannery v layoutu. */
export interface LicenseSummary {
  state: LicenseStateKind
  tier: string | null
  trial_ends_at: number | null
  valid_until: number | null
  overage_deadline: number | null
  /** Doživotní licence — neomezená platnost (valid_until je jen TTL tokenu). */
  perpetual: boolean
  commercial_features: boolean
  /** Počty pro overage banner — kolik aktivních vs. licencováno. */
  users_active: number
  users_licensed: number
  companies_active: number
  max_companies: number | null
}

/** Plný stav licence z /api/license/status (admin). */
export interface LicenseStatus {
  state: LicenseStateKind
  instance_id: string
  tier: string | null
  max_companies: number | null
  users_licensed: number
  users_active: number
  companies_active: number
  valid_until: number | null
  trial_ends_at: number | null
  overage_deadline: number | null
  /** Doživotní licence — neomezená platnost (valid_until je jen TTL tokenu). */
  perpetual: boolean
  commercial_features: boolean
  license_key_masked: string | null
  last_check_at: string | null
  last_check_ok: boolean
  buy_url: string
  /** Fakturační údaje aktuální firmy — předvyplnění webového checkoutu (nevynucené). */
  company: LicenseCompany
}

export interface LicenseCompany {
  name: string
  ic: string
  dic: string
  street: string
  city: string
  zip: string
  email: string
}

export interface DeactivateResult {
  transfers_remaining: number | null
  state: LicenseStatus
}

/** Kalkulace poměrného doplatku za in-place navýšení počtu uživatelů. */
export interface UpgradeQuote {
  current_users: number | null
  new_users: number
  amount: number | null
  currency: string | null
  period_end: number | string | null
}

export interface UpgradeResult {
  new_users: number
  amount_charged: number | null
  state: LicenseStatus
}

export const licenseApi = {
  /** Admin — kompletní stav licence + počty. */
  status: () => api.get<LicenseStatus>('/license/status').then((r) => r.data),

  /** Admin — aktivace licenčním klíčem. `takeover` vynutí přenos vazby z jiné instalace
   *  (po chybě already_bound); počítá se do limitu přenosů 2/30 dní. */
  activate: (license_key: string, takeover = false) =>
    api.post<LicenseStatus>('/license/activate', { license_key, takeover }).then((r) => r.data),

  /** Admin — deaktivace (uvolní vazbu, smaže klíč lokálně). */
  deactivate: () => api.post<DeactivateResult>('/license/deactivate').then((r) => r.data),

  /** Admin — kalkulace poměrného doplatku za navýšení na `users` (nic se nestrhává). */
  upgradeQuote: (users: number) =>
    api.post<UpgradeQuote>('/license/upgrade/quote', { users }).then((r) => r.data),

  /** Admin — in-place navýšení na `users` (strhne doplatek z uložené karty). */
  upgrade: (users: number) =>
    api.post<UpgradeResult>('/license/upgrade', { users }).then((r) => r.data),
}
