import { describe, it, expect } from 'vitest'
import type { LicenseStatus, ManagedInstanceInfo } from '../license'
import { hostingNavAttention, resolveHostingActions } from '../hostingActions'
import { buildPreviewStatus } from '../instancePreview'

const GB = 1024 * 1024 * 1024

function instance(over: Partial<ManagedInstanceInfo> = {}): ManagedInstanceInfo {
  return {
    managed: true,
    plan: 'standard',
    managed_since: '2025-03-01',
    subscription_url: null,
    links: { subscription: null, expand_storage: null, support: null, terms: null, privacy: null },
    billing: {
      unpaid: false,
      license_state: 'active',
      subscription_state: 'active',
      valid_until: 1_800_000_000,
      last_check_at: null,
      last_check_ok: true,
      phase: 'active',
      attempt: null,
      max_attempts: null,
      next_attempt_at: null,
      suspend_at: null,
      access_until: null,
      data_until: null,
      amount_due: null,
      currency: null,
      pay_url: null,
    },
    storage: {
      measured: true,
      measured_at: null,
      usage_bytes: 3 * GB,
      quota_bytes: 7 * GB,
      percent: 42,
      warn_percent: 90,
      read_only_percent: 100,
      blocks_writes: false,
      change_pending: false,
      quota_gb_ordered: 7,
      quota_source: 'license',
    },
    ...over,
  }
}

function managedStatus(
  instanceOver: Partial<ManagedInstanceInfo> = {},
  statusOver: Partial<LicenseStatus> = {},
): LicenseStatus {
  const base = buildPreviewStatus('manual_key', 1_800_000_000)

  return {
    ...base,
    ...statusOver,
    instance: instance(instanceOver),
  }
}

function withStorage(over: Partial<ManagedInstanceInfo['storage']>) {
  return managedStatus({ storage: { ...instance().storage, ...over } })
}

describe('resolveHostingActions', () => {
  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: dashboard vidí každý uživatel. Kdyby se
   * seznam počítal i bez bloku `instance`, dostala by self-hosted instalace
   * výzvu k úhradě předplatného, které u nás nemá — a k rozšíření kvóty, kterou
   * jí nikdo nenastavil.
   */
  it('self-hosted instalace nedostane ani řádek', () => {
    expect(resolveHostingActions(null)).toEqual([])
    expect(resolveHostingActions(undefined)).toEqual([])
    expect(resolveHostingActions({ ...managedStatus(), instance: undefined })).toEqual([])
  })

  it('zdravá instalace nemá co řešit', () => {
    expect(resolveHostingActions(managedStatus())).toEqual([])
    expect(hostingNavAttention([])).toBeNull()
  })

  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: nezměřená instalace vypadá v datech skoro
   * jako prázdná. Implementace, která si `usage` přečte jako nulu, buď mlčí
   * navždy, nebo (po přetypování kvóty) hlásí plný disk instalaci, o které
   * nevíme nic.
   */
  it('nezměřené místo mlčí', () => {
    expect(resolveHostingActions(withStorage({ measured: false, usage_bytes: null, percent: null }))).toEqual([])
    expect(resolveHostingActions(withStorage({ quota_bytes: null, percent: null }))).toEqual([])
  })

  it('platba je vždy první a neuhrazená je červená', () => {
    const actions = resolveHostingActions(managedStatus({
      billing: { ...instance().billing, unpaid: true, phase: 'past_due', subscription_state: 'past_due', next_attempt_at: 1_800_100_000 },
      storage: { ...instance().storage, percent: 96 },
    }))
    expect(actions.map(a => a.kind)).toEqual(['unpaid', 'storage_low'])
    expect(actions[0].severity).toBe('high')
    // Text i termín jdou z téže logiky jako obrazovka — nekopírují se.
    expect(actions[0].titleKey).toBe('hosting.phase.happened_past_due')
    expect(actions[0].hintKey).toBe('hosting.phase.next_attempt')
    expect(actions[0].at).toBe(1_800_100_000)
    expect(hostingNavAttention(actions)).toBe('danger')
  })

  it('zrušená obnova je vážná, ale nehoří', () => {
    const actions = resolveHostingActions(managedStatus({
      billing: { ...instance().billing, phase: 'cancelled', subscription_state: 'cancelled', access_until: 1_801_000_000 },
    }))
    expect(actions[0].severity).toBe('medium')
    expect(hostingNavAttention(actions)).toBe('warning')
  })

  it('od 80 % vyzve k rozšíření, od 100 % je červená', () => {
    expect(resolveHostingActions(withStorage({ percent: 82 }))[0]).toMatchObject({ kind: 'storage_low', severity: 'medium' })
    expect(resolveHostingActions(withStorage({ percent: 95 }))[0]).toMatchObject({ kind: 'storage_low', severity: 'medium' })
    expect(resolveHostingActions(withStorage({ percent: 100 }))[0]).toMatchObject({ kind: 'storage_exhausted', severity: 'high' })
  })

  it('překročení uživatelů a firem propaguje jako dvě červené akce', () => {
    const actions = resolveHostingActions(managedStatus({}, {
      state: 'overage',
      users_active: 6,
      users_licensed: 3,
      companies_active: 12,
      max_companies: 10,
      overage_deadline: 1_801_000_000,
    }))

    expect(actions.map(a => a.kind)).toEqual(['users_overage', 'companies_overage'])
    expect(actions[0]).toMatchObject({ severity: 'high', active: 6, limit: 3, link: '/hosting#uzivatele' })
    expect(actions[1]).toMatchObject({ severity: 'high', active: 12, limit: 10, link: '/hosting#tarif' })
    expect(hostingNavAttention(actions)).toBe('danger')
  })

  it('chybějící licenční klíč vede přímo na aktivaci', () => {
    const actions = resolveHostingActions(managedStatus({}, { license_key_masked: null }))

    expect(actions).toHaveLength(1)
    expect(actions[0]).toMatchObject({
      kind: 'license_key_missing',
      severity: 'high',
      link: '/activation/purchase#activate',
    })
  })

  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: zaváděné rozšíření se pozná jen podle
   * `change_pending`; obsazení je pořád vysoké. Implementace, která se dívá jen
   * na procenta, nabídne zákazníkovi koupit místo, které si právě koupil —
   * a on ho na dashboardu, kde stačí jedno kliknutí, klidně koupí podruhé.
   */
  it('zaváděné rozšíření je oznámení, ne výzva k nákupu', () => {
    const actions = resolveHostingActions(withStorage({ percent: 94, change_pending: true, quota_gb_ordered: 22 }))
    expect(actions).toHaveLength(1)
    expect(actions[0].kind).toBe('storage_provisioning')
    expect(actions[0].severity).toBe('info')
    expect(actions[0].quotaGb).toBe(22)
    // Oznámení nesmí rozsvítit tečku v menu — není to úkol.
    expect(hostingNavAttention(actions)).toBeNull()
  })

  it('vyčerpané místo hlásí i při zaváděném rozšíření, ale jinou větou', () => {
    const plain = resolveHostingActions(withStorage({ percent: 100 }))[0]
    const pending = resolveHostingActions(withStorage({ percent: 100, change_pending: true }))[0]
    expect(plain.kind).toBe('storage_exhausted')
    expect(pending.kind).toBe('storage_exhausted')
    // Zápisy stojí v obou případech; pobízet ke koupi se smí jen v jednom.
    expect(pending.hintKey).not.toBe(plain.hintKey)
  })

  it('scénáře náhledu projdou touž logikou', () => {
    const kinds = (s: Parameters<typeof buildPreviewStatus>[0]) =>
      resolveHostingActions(buildPreviewStatus(s, 1_800_000_000)).map(a => a.kind)

    expect(kinds('storage_80')).toEqual(['storage_low'])
    expect(kinds('storage_100')).toEqual(['storage_exhausted'])
    expect(kinds('provisioning')).toEqual(['storage_provisioning'])
    expect(kinds('suspended')).toEqual(['unpaid'])
    expect(kinds('overage')).toEqual(['users_overage', 'companies_overage'])
    expect(kinds('no_license')).toEqual(['license_key_missing'])
  })
})
