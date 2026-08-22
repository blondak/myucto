import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import type { LicenseSummary } from '@/api/license'

/**
 * Backend posílá `tier_commercial` a `new_user_blocked` odjakživa, obrazovky
 * je ale nečetly.
 *
 * Důsledky byly dva a oba se projevily až u zákazníka: admin vyplnil celý
 * formulář nového uživatele včetně hesla a teprve uložení skončilo na 403,
 * a bezplatnému tarifu aplikace nabízela zaplatit něco, co má zaplacené —
 * nerozlišila „licence propadla" od „tenhle tarif to nikdy neměl".
 */
describe('stav licence v auth store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  function summary(over: Partial<LicenseSummary> = {}): LicenseSummary {
    return {
      state: 'active',
      tier: 'invoicing',
      trial_ends_at: null,
      valid_until: null,
      overage_deadline: null,
      perpetual: false,
      commercial_features: false,
      tier_commercial: false,
      new_user_blocked: null,
      subscription_state: 'active',
      users_active: 1,
      users_licensed: 1,
      companies_active: 1,
      max_companies: null,
      ...over,
    }
  }

  it('rozliší bezplatný tarif od propadlé licence', () => {
    const store = useAuthStore()

    store.license = summary({ tier_commercial: false, commercial_features: false })
    expect(store.tierUnlocksCommercial).toBe(false)

    store.license = summary({ tier_commercial: true, commercial_features: false, state: 'degraded' })
    expect(store.tierUnlocksCommercial).toBe(true)
    expect(store.hasCommercialFeatures).toBe(false)
  })

  /**
   * Fail-open je tu správně: token vydaný před zavedením příznaku ho nenese
   * a všechny takové licence jsou placené. Opačný default by zavřel moduly
   * každému platícímu zákazníkovi až do příští obnovy tokenu.
   */
  it('chybějící příznak tarifu bere jako placený', () => {
    const store = useAuthStore()
    const { tier_commercial: _omitted, ...withoutFlag } = summary()
    store.license = withoutFlag as LicenseSummary

    expect(store.tierUnlocksCommercial).toBe(true)
  })

  it('hlásí, proč nejde přidat dalšího zapisujícího uživatele', () => {
    const store = useAuthStore()

    store.license = summary({ new_user_blocked: 'no_license' })
    expect(store.newUserBlocked).toBe('no_license')

    store.license = summary({ new_user_blocked: 'seat_limit' })
    expect(store.newUserBlocked).toBe('seat_limit')

    store.license = summary({ new_user_blocked: null })
    expect(store.newUserBlocked).toBeNull()
  })
})
