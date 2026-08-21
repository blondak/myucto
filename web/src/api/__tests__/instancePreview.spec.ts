import { describe, it, expect, afterEach } from 'vitest'
import {
  PREVIEW_SCENARIOS,
  buildPreviewStatus,
  instancePreview,
  isPreviewScenario,
  startPreview,
  stopPreview,
} from '../instancePreview'
import { resolveBillingNarrative, resolveStorageLevel } from '../instanceHealth'

const NOW = 1_800_000_000

afterEach(stopPreview)

describe('isPreviewScenario', () => {
  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: hodnota z URL je cizí vstup. Kdyby se
   * cokoli z query stringu pustilo dál jako scénář, `buildPreviewStatus` by
   * spadl na neznámé větvi — a to na stránce, kterou má vidět admin ve chvíli,
   * kdy něco řeší.
   */
  it('pustí dál jen známé scénáře', () => {
    expect(isPreviewScenario('degraded')).toBe(true)
    expect(isPreviewScenario('neco_jineho')).toBe(false)
    expect(isPreviewScenario(undefined)).toBe(false)
    expect(isPreviewScenario(['degraded'])).toBe(false)
  })
})

describe('náhled se nezapne sám', () => {
  it('výchozí stav je vypnuto a stopPreview ho vrátí', () => {
    expect(instancePreview.isActive.value).toBe(false)
    startPreview('degraded')
    expect(instancePreview.isActive.value).toBe(true)
    expect(instancePreview.scenario.value).toBe('degraded')
    stopPreview()
    expect(instancePreview.isActive.value).toBe(false)
    expect(instancePreview.scenario.value).toBeNull()
  })
})

describe('buildPreviewStatus', () => {
  it('každý scénář vrátí použitelný stav spravované instalace', () => {
    for (const scenario of PREVIEW_SCENARIOS) {
      const status = buildPreviewStatus(scenario, NOW)
      expect(status.instance, scenario).not.toBeUndefined()
      expect(status.instance!.managed, scenario).toBe(true)
    }
  })

  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: náhled, který neukáže to, kvůli čemu
   * vznikl, je jen barevný pruh. Každý scénář musí projít TOUTÉŽ logikou jako
   * skutečná data — kdyby si kreslil vlastní text, přestane odpovídat realitě
   * ve chvíli, kdy se pravidla změní.
   */
  it('scénáře plateb dojdou přes společná pravidla ke správné fázi', () => {
    const cases: Array<[Parameters<typeof buildPreviewStatus>[0], string, string]> = [
      ['past_due', 'past_due', 'hosting.phase.next_attempt'],
      ['suspended', 'suspended', 'hosting.phase.next_data_end'],
      ['expired', 'expired', 'hosting.phase.next_data_end'],
      ['cancelled', 'cancelled', 'hosting.phase.next_access_end'],
    ]
    for (const [scenario, phase, nextKey] of cases) {
      const n = resolveBillingNarrative(buildPreviewStatus(scenario, NOW).instance!.billing)!
      expect(n.phase, scenario).toBe(phase)
      expect(n.nextKey, scenario).toBe(nextKey)
    }
  })

  it('zavřené placené funkce ukazuje trial_expired i degraded', () => {
    for (const scenario of ['trial_expired', 'degraded'] as const) {
      const n = resolveBillingNarrative(buildPreviewStatus(scenario, NOW).instance!.billing)!
      expect(n.featuresLocked, scenario).toBe(true)
      expect(n.severity, scenario).toBe('critical')
    }
  })

  it('scénáře místa trefí přesně tři úrovně obsazení', () => {
    expect(resolveStorageLevel(buildPreviewStatus('storage_80', NOW).instance!.storage)).toBe('notice')
    expect(resolveStorageLevel(buildPreviewStatus('storage_95', NOW).instance!.storage)).toBe('warning')
    expect(resolveStorageLevel(buildPreviewStatus('storage_100', NOW).instance!.storage)).toBe('exhausted')
  })

  it('zaváděné rozšíření je označené jako změna, ne jako nabídka', () => {
    const storage = buildPreviewStatus('provisioning', NOW).instance!.storage
    expect(storage.change_pending).toBe(true)
    expect(storage.quota_gb_ordered).toBe(22)
  })

  it('překročený rozsah nesahá na placené funkce', () => {
    const status = buildPreviewStatus('overage', NOW)
    expect(status.users_active).toBeGreaterThan(status.users_licensed)
    expect(status.commercial_features).toBe(true)
  })
})
