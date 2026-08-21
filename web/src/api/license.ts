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
  /**
   * Stav předplatného na licenčním serveru, ne stav licence.
   *
   * Licence může být pořád platná, a přitom je zákazník po splatnosti: token
   * doběhne až na konci zaplaceného období. Bez toho by se výzva k úhradě
   * objevila teprve ve chvíli, kdy se komerční moduly zavřou.
   *
   * `null` = server stav neposlal (starší instalace, nebo se instalace ještě
   * nedovolala) — není to „zaplaceno".
   */
  subscription_state: string | null
  /** Počty pro overage banner — kolik aktivních vs. licencováno. */
  users_active: number
  users_licensed: number
  companies_active: number
  max_companies: number | null
}

/**
 * Stav předplatného za licencí (z licenčního serveru). `null` = licence se
 * automaticky neprodlužuje (trial, doživotní licence) nebo to server nehlásí.
 * Časy jsou unix timestampy.
 */
export interface SubscriptionInfo {
  state: 'active' | 'past_due' | 'cancelled' | 'expired'
  period: 'month' | 'year'
  /** Chystá se další stržení? */
  auto_renew: boolean
  next_charge_at: number | null
  cancelled_at: number | null
  valid_until: number | null
}

/**
 * Obsazení místa spravované instalace.
 *
 * ⚠️ `null` znamená „nevím", NIKDY nulu:
 *  - `measured === false` → spotřeba se ještě neměřila; `usage_bytes` je null
 *    a obrazovka musí říct „zatím neměřeno", ne „0 %, vše v pořádku".
 *  - `quota_bytes === null` → neznáme zaplacený objem; pak se NEKRESLÍ pruh
 *    ani procenta, jen absolutní obsazení. Dělit něčím, co neznáme, znamená
 *    vymyslet si číslo.
 */
export interface ManagedStorageInfo {
  measured: boolean
  measured_at: string | null
  usage_bytes: number | null
  /** ZAPLACENÝ objem (ne disková kvóta hostingu, ta obsahuje rezervu na dumpy). */
  quota_bytes: number | null
  percent: number | null
  warn_percent: number
  read_only_percent: number
  /** Skutečný stav vynucení — instalace je právě teď jen pro čtení. */
  blocks_writes: boolean
}

/**
 * Stav spravované instalace (SaaS). Přítomné POUZE ve spravovaném režimu —
 * na self-hosted instalaci klíč v odpovědi vůbec není a obrazovka aktivace
 * zůstává beze změny.
 */
export interface ManagedInstanceInfo {
  managed: true
  /** Kód tarifu provozu; null = neuvedeno. */
  plan: string | null
  /** Datum zřízení (ISO); null = neuvedeno. */
  managed_since: string | null
  /** Správa předplatného na webu; null = adresa není nakonfigurovaná → kontakt. */
  subscription_url: string | null
  storage: ManagedStorageInfo
  billing: ManagedBillingInfo
  links: ManagedLinks
}

/**
 * Co instalace SKUTEČNĚ ví o (ne)uhrazení — nic víc.
 *
 * ⚠️ Není tu částka, splatnost ani datum přijetí platby: instalace je nezná
 * a dopočítat se nedají. `unpaid` je jediná otázka, na kterou smí odpovídat.
 */
export interface ManagedBillingInfo {
  /** Komerční moduly jsou zavřené / server hlásí nezaplacené předplatné. */
  unpaid: boolean
  license_state: LicenseStateKind
  /** Stav předplatného ze serveru; null = nehlásí ho (trial, doživotní). */
  subscription_state: SubscriptionInfo['state'] | null
  valid_until: number | null
  /** Kdy se instalace naposledy ptala serveru — bez toho „neuhrazeno" nelze číst. */
  last_check_at: string | null
  last_check_ok: boolean
}

/**
 * Adresy na myucto.cz z konfigurace (`license.server_url`). `null` = adresa
 * není nastavená a obrazovka odkaz NEKRESLÍ — mrtvý odkaz je horší než žádný.
 */
export interface ManagedLinks {
  subscription: string | null
  expand_storage: string | null
  support: string | null
  terms: string | null
  privacy: string | null
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
  /** Automatické prodlužování a datum dalšího stržení; null = neprodlužuje se. */
  subscription: SubscriptionInfo | null
  /** Fakturační údaje aktuální firmy — předvyplnění webového checkoutu (nevynucené). */
  company: LicenseCompany
  /** Jen spravovaná instalace; na self-hosted klíč v odpovědi není. */
  instance?: ManagedInstanceInfo
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

/** Výsledek vypnutí automatického prodlužování — licence běží dál do valid_until. */
export interface CancelRenewalResult {
  /** Předplatné už zrušené bylo (opakované volání) — pořád úspěch. */
  already_cancelled: boolean
  /** Konec zaplaceného období, do kterého licence poběží. */
  valid_until: number | null
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

/**
 * Odkaz na portál podpory na myucto.cz. U placené licence nese jednorázový
 * token, kterým se zákazník na portálu rovnou identifikuje; jinak je to prostý
 * veřejný odkaz.
 */
export interface SupportLink {
  url: string
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

  /** Admin — vypnutí automatického prodlužování. NENÍ deaktivace: licence běží
   *  do konce zaplaceného období, jen se nestrhne další platba. Idempotentní. */
  cancelRenewal: () =>
    api.post<CancelRenewalResult>('/license/cancel-renewal').then((r) => r.data),

  /** Admin — kalkulace poměrného doplatku za navýšení na `users` (nic se nestrhává). */
  upgradeQuote: (users: number) =>
    api.post<UpgradeQuote>('/license/upgrade/quote', { users }).then((r) => r.data),

  /** Admin — in-place navýšení na `users` (strhne doplatek z uložené karty). */
  upgrade: (users: number) =>
    api.post<UpgradeResult>('/license/upgrade', { users }).then((r) => r.data),

  /** Admin — přihlášený přechod na portál podpory (jednorázový token v URL). */
  supportLink: () => api.post<SupportLink>('/license/support-link').then((r) => r.data),
}
