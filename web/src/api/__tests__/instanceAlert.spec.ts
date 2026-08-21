import { describe, it, expect } from 'vitest'
import { resolveInstanceAlerts } from '../instanceAlert'

/**
 * Pravidlo pro červenou linku nad aplikací (H-31) — kdy svítí a kdy ne.
 *
 * Testuje se funkce, ne vykreslení: rozhodnutí „linku ano/ne" je to, co se dá
 * pokazit, a nemá cenu ho hledat přes snímek DOMu.
 */
describe('resolveInstanceAlerts', () => {
  // ── Self-hosted regrese ───────────────────────────────────────────────────
  //
  // ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: implementace, která se ptá jen
  // „je vyčerpáno / je degradovaná licence", rozsvítí červenou linku každé
  // self-hosted instalaci s prošlým trialem. Tam přitom degradovaná licence
  // znamená legitimní provoz MIT jádra a kvótu tam nikdo nenastavil.
  it('na self-hosted instalaci nesvítí, ani když by oba důvody platily', () => {
    expect(resolveInstanceAlerts({
      managed: false,
      storageExhausted: true,
      licenseState: 'degraded',
      subscriptionState: 'past_due',
    })).toEqual([])
  })

  // ── Vyčerpaná kvóta ───────────────────────────────────────────────────────
  it('vyčerpaná kvóta linku zobrazí', () => {
    expect(resolveInstanceAlerts({ managed: true, storageExhausted: true, licenseState: 'active' }))
      .toEqual(['storage_exhausted'])
  })

  /**
   * ⚠️ Varování na 90 % sem NEPATŘÍ — je to žlutý, neblokující pruh. Kdyby se
   * z docházejícího místa stala červená linka, přestane se rozlišovat
   * „doplňte" od „zastaveno".
   */
  it('zdravá ani varovná kvóta linku nezobrazí', () => {
    expect(resolveInstanceAlerts({ managed: true, storageExhausted: false, licenseState: 'active' }))
      .toEqual([])
  })

  // ── Neuhrazeno ────────────────────────────────────────────────────────────
  it('degradovaná licence linku zobrazí', () => {
    expect(resolveInstanceAlerts({ managed: true, storageExhausted: false, licenseState: 'degraded' }))
      .toEqual(['unpaid'])
  })

  it('prošlý trial linku zobrazí', () => {
    expect(resolveInstanceAlerts({ managed: true, storageExhausted: false, licenseState: 'trial_expired' }))
      .toEqual(['unpaid'])
  })

  it('nezaplacené předplatné linku zobrazí i s běžící licencí', () => {
    expect(resolveInstanceAlerts({
      managed: true,
      storageExhausted: false,
      licenseState: 'active',
      subscriptionState: 'past_due',
    })).toEqual(['unpaid'])
  })

  /** Overage je přečerpaný rozsah, ne dluh — zaplaceno je. */
  it('overage linku nezobrazí', () => {
    expect(resolveInstanceAlerts({ managed: true, storageExhausted: false, licenseState: 'overage' }))
      .toEqual([])
  })

  it('probíhající trial linku nezobrazí', () => {
    expect(resolveInstanceAlerts({ managed: true, storageExhausted: false, licenseState: 'trial' }))
      .toEqual([])
  })

  // ── Neznámý stav ──────────────────────────────────────────────────────────
  it('chybějící stav licence sám o sobě linku nevyrobí', () => {
    expect(resolveInstanceAlerts({ managed: true, storageExhausted: false, licenseState: null }))
      .toEqual([])
  })

  it('oba důvody najednou vrací oba, ne jen první', () => {
    expect(resolveInstanceAlerts({ managed: true, storageExhausted: true, licenseState: 'degraded' }))
      .toEqual(['storage_exhausted', 'unpaid'])
  })
})
