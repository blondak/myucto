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

describe('EPO handoff cache', () => {
  const now = new Date('2026-07-27T12:00:00Z').getTime()

  it('restores an unexpired official EPO link for the same user and supplier', () => {
    const storage = memoryStorage()
    saveEpoHandoffLinks(storage, 7, 11, {
      1099: {
        url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
        expiresAt: '2026-07-27T12:30:00Z',
        attemptId: 42,
        opened: false,
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
        opened: false,
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
        opened: false,
      },
    }, now)

    expect(saved).toEqual({})
  })

  it('offers a link only until it has been opened once', () => {
    const link = {
      url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
      expiresAt: '2026-07-27T12:30:00Z',
      attemptId: 42,
      opened: false,
    }

    expect(canOfferHandoffLink(link, now)).toBe(true)
    // Po prvním otevření o odkazu nevíme, jestli ještě žije (EPO nám o životnosti
    // ani jednorázovosti nic neříká) — nabídnout ho znovu by znamenalo poslat
    // uživatele na hlášku portálu o neexistujícím podání.
    expect(canOfferHandoffLink({ ...link, opened: true }, now)).toBe(false)
    expect(canOfferHandoffLink(link, now + 31 * 60_000)).toBe(false)
    expect(canOfferHandoffLink(undefined, now)).toBe(false)
  })

  it('keeps an already opened link in storage so uploads stay tied to the attempt', () => {
    const storage = memoryStorage()
    saveEpoHandoffLinks(storage, 7, 11, {
      1099: {
        url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
        expiresAt: '2026-07-27T12:30:00Z',
        attemptId: 42,
        opened: true,
      },
    }, now)

    const restored = loadEpoHandoffLinks(storage, 7, 11, now + 60_000)
    expect(restored[1099]?.attemptId).toBe(42)
    expect(canOfferHandoffLink(restored[1099], now + 60_000)).toBe(false)
  })

  it('drops legacy entries written before the single-use rule existed', () => {
    const storage = memoryStorage()
    // Starý formát bez `opened` — nevíme, jestli už byl odkaz spotřebovaný,
    // takže se nesmí obnovit ani jako nabídnutelný, ani jako spotřebovaný.
    storage.setItem('myinvoice.epo_handoff_links.v1.7.11', JSON.stringify({
      1099: {
        url: 'https://adisspr.mfcr.cz/dpr/idpr_epo/epo2/formular?x=synthetic',
        expiresAt: '2026-07-27T12:30:00Z',
        attemptId: 42,
      },
    }))

    expect(loadEpoHandoffLinks(storage, 7, 11, now)).toEqual({})
    expect(storage.getItem('myinvoice.epo_handoff_links.v1.7.11')).toBeNull()
  })
})
