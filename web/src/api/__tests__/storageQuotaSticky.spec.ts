import { describe, it, expect, beforeEach } from 'vitest'
import { readStorageQuotaHeaders, storageQuota } from '../storageQuota'

/**
 * Lepkavý blokující stav kvóty (H-31) — podklad pro červenou linku.
 *
 * Rozdíl proti `storageQuota.isExhausted`: ten je momentka poslední odpovědi
 * a smí zmizet. Blokující stav ne — zápisy jsou zastavené a jediná linka, která
 * to uživateli říká, se nesmí schovat jen proto, že další požadavek spadl.
 * „Nevím" není „v pořádku".
 */
describe('storageQuota — lepkavý blokující stav', () => {
  beforeEach(() => {
    // Důvěryhodná odpověď bez hlaviček = čistý start.
    readStorageQuotaHeaders({})
  })

  it('vyčerpaná kvóta blokující stav nastaví', () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted', 'x-storage-quota-percent': '101' })

    expect(storageQuota.isCriticallyExhausted.value).toBe(true)
  })

  /** Varování na 90 % je žluté a neblokující — červenou linku nespouští. */
  it('varování na 90 % blokující stav nenastaví', () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'warning', 'x-storage-quota-percent': '90.0' })

    expect(storageQuota.isWarning.value).toBe(true)
    expect(storageQuota.isCriticallyExhausted.value).toBe(false)
  })

  /**
   * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: původní implementace mazala stav
   * při KAŽDÉ odpovědi bez hlaviček — tedy i při 401, 500 nebo odpovědi
   * z jiného původu. Jeden neúspěšný požadavek by tak schoval jedinou zprávu
   * o tom, že se nic neukládá.
   */
  it('neúspěšná odpověď bez hlaviček blokující stav NESCHOVÁ', () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' })
    readStorageQuotaHeaders({}, { trusted: false })

    expect(storageQuota.isCriticallyExhausted.value).toBe(true)
  })

  /** Ani opakované selhání stav nesmí odbourat. */
  it('ani několik selhání za sebou blokující stav nesmaže', () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' })
    for (let i = 0; i < 5; i++) readStorageQuotaHeaders({}, { trusted: false })

    expect(storageQuota.isCriticallyExhausted.value).toBe(true)
  })

  /** Odmítnutý zápis (507) hlavičky nese — chybová odpověď stav nastavit SMÍ. */
  it('507 s hlavičkami blokující stav nastaví, i když je odpověď chybová', () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' }, { trusted: false })

    expect(storageQuota.isCriticallyExhausted.value).toBe(true)
  })

  /** Uklidit smí jen důvěryhodná (úspěšná) odpověď — tam mlčení opravdu znamená klid. */
  it('úspěšná odpověď bez hlaviček blokující stav zhasne', () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' })
    readStorageQuotaHeaders({})

    expect(storageQuota.isCriticallyExhausted.value).toBe(false)
  })

  /** Pokles zpátky na varování zámek ruší — pruh zůstane, linka zhasne. */
  it('pokles z vyčerpáno na varování blokující stav ruší', () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' })
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'warning', 'x-storage-quota-percent': '92' })

    expect(storageQuota.isCriticallyExhausted.value).toBe(false)
    expect(storageQuota.isWarning.value).toBe(true)
  })

  /** Dosavadní chování žlutého pruhu se nesmí změnit. */
  it('důvěryhodná odpověď bez hlaviček pořád maže i běžný stav', () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'warning', 'x-storage-quota-percent': '90' })
    readStorageQuotaHeaders({})

    expect(storageQuota.state.value).toBeNull()
    expect(storageQuota.percent.value).toBeNull()
  })
})
