import { describe, expect, it } from 'vitest'
import { canOfferHandoffLink, loadEpoHandoffLinks, saveEpoHandoffLinks } from '../epoHandoffCache'

function memoryStorage() {
  const values = new Map<string, string>()
  return {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => values.set(key, value),
    removeItem: (key: string) => values.delete(key),
  }
}

const SNAPSHOT_SHA = 'a'.repeat(64)
const RECALCULATED_SHA = 'b'.repeat(64)

describe('EPO handoff cache', () => {
  const now = new Date('2026-07-27T12:00:00Z').getTime()

  it('restores an unexpired official EPO link for the same user and supplier', () => {
    const storage = memoryStorage()
    saveEpoHandoffLinks(storage, 7, 11, {
      1099: {
        url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
        expiresAt: '2026-07-27T12:20:00Z',
        attemptId: 42,
        xmlSha256: SNAPSHOT_SHA,
      },
    }, now)

    expect(loadEpoHandoffLinks(storage, 7, 11, now + 10 * 60_000)[1099]?.attemptId).toBe(42)
    expect(loadEpoHandoffLinks(storage, 8, 11, now + 10 * 60_000)).toEqual({})
  })

  it('removes expired links', () => {
    const storage = memoryStorage()
    saveEpoHandoffLinks(storage, 7, 11, {
      1099: {
        url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
        expiresAt: '2026-07-27T12:20:00Z',
        attemptId: 42,
        xmlSha256: SNAPSHOT_SHA,
      },
    }, now)

    expect(loadEpoHandoffLinks(storage, 7, 11, now + 21 * 60_000)).toEqual({})
  })

  it('rejects links outside the official production host', () => {
    const storage = memoryStorage()
    const saved = saveEpoHandoffLinks(storage, 7, 11, {
      1099: {
        url: 'https://attacker.example/formular?x=synthetic',
        expiresAt: '2026-07-27T12:20:00Z',
        attemptId: 42,
        xmlSha256: SNAPSHOT_SHA,
      },
    }, now)

    expect(saved).toEqual({})
  })

  it('offers a link repeatedly inside the window while the snapshot stays the same', () => {
    const link = {
      url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
      expiresAt: '2026-07-27T12:20:00Z',
      attemptId: 42,
      xmlSha256: SNAPSHOT_SHA,
    }

    // Opakované otevření je v pořádku — odkaz se prvním otevřením nespotřebuje,
    // skutečnou překážkou byla autentizace do aplikace MOSS/OSS, ne jednorázovost.
    expect(canOfferHandoffLink(link, SNAPSHOT_SHA, now)).toBe(true)
    expect(canOfferHandoffLink(link, SNAPSHOT_SHA, now + 19 * 60_000)).toBe(true)
    expect(canOfferHandoffLink(link, SNAPSHOT_SHA, now + 21 * 60_000)).toBe(false)
    expect(canOfferHandoffLink(undefined, SNAPSHOT_SHA, now)).toBe(false)
  })

  it('stops offering a link once the underlying XML has been recalculated', () => {
    const link = {
      url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
      expiresAt: '2026-07-27T12:20:00Z',
      attemptId: 42,
      xmlSha256: SNAPSHOT_SHA,
    }

    expect(canOfferHandoffLink(link, RECALCULATED_SHA, now)).toBe(false)
    // Prázdný nebo nesmyslný otisk se nesmí brát jako shoda.
    expect(canOfferHandoffLink(link, '', now)).toBe(false)
  })

  it('keeps a stale link in storage so uploads stay tied to the attempt', () => {
    const storage = memoryStorage()
    saveEpoHandoffLinks(storage, 7, 11, {
      1099: {
        url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
        expiresAt: '2026-07-27T12:20:00Z',
        attemptId: 42,
        xmlSha256: SNAPSHOT_SHA,
      },
    }, now)

    const restored = loadEpoHandoffLinks(storage, 7, 11, now + 60_000)
    expect(restored[1099]?.attemptId).toBe(42)
    expect(canOfferHandoffLink(restored[1099], RECALCULATED_SHA, now + 60_000)).toBe(false)
  })

  it('stops offering a link that has already been opened, even inside the window', () => {
    // Provozní nález: URL do EPO je jednorázová. Portál ji spotřebuje prvním
    // otevřením a druhý pokus skončí hláškou o neexistujícím podání — i minutu
    // po prvním kliknutí a v tomtéž prohlížeči. Okno platnosti ani nezměněný
    // podklad na tom nic nemění.
    const link = {
      url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
      expiresAt: '2026-07-27T12:20:00Z',
      attemptId: 42,
      xmlSha256: SNAPSHOT_SHA,
    }
    expect(canOfferHandoffLink(link, SNAPSHOT_SHA, now + 60_000)).toBe(true)

    const opened = { ...link, consumedAt: '2026-07-27T12:01:00Z' }
    expect(canOfferHandoffLink(opened, SNAPSHOT_SHA, now + 60_000)).toBe(false)
  })

  it('keeps a consumed link in storage so uploaded artifacts still find their attempt', () => {
    // Spotřebovaný odkaz se přestane nabízet, ale ze záznamu se nemaže —
    // `attemptId` je jediná vazba nahraných artefaktů na pokus o podání.
    const storage = memoryStorage()
    saveEpoHandoffLinks(storage, 7, 11, {
      1099: {
        url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
        expiresAt: '2026-07-27T12:20:00Z',
        attemptId: 42,
        xmlSha256: SNAPSHOT_SHA,
        consumedAt: '2026-07-27T12:01:00Z',
      },
    }, now)

    const restored = loadEpoHandoffLinks(storage, 7, 11, now + 60_000)
    expect(restored[1099]?.attemptId).toBe(42)
    expect(canOfferHandoffLink(restored[1099], SNAPSHOT_SHA, now + 60_000)).toBe(false)
  })

  it('drops legacy entries written before the snapshot fingerprint existed', () => {
    const storage = memoryStorage()
    // Starý formát bez `xmlSha256` — nevíme, ke kterému podkladu odkaz vznikl,
    // takže se nesmí obnovit vůbec.
    storage.setItem('myinvoice.epo_handoff_links.v1.7.11', JSON.stringify({
      1099: {
        url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
        expiresAt: '2026-07-27T12:20:00Z',
        attemptId: 42,
        opened: false,
      },
    }))

    expect(loadEpoHandoffLinks(storage, 7, 11, now)).toEqual({})
    expect(storage.getItem('myinvoice.epo_handoff_links.v1.7.11')).toBeNull()
  })
})
