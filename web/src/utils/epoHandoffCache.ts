/**
 * Cache odkazu do interaktivního formuláře EPO.
 *
 * EPO nám o odkazu neřekne nic než URL — ani platnost, ani jestli je jednorázový.
 * `expiresAt` je proto jen odhad backendu (viz `EpoClient::ESTIMATED_LINK_LIFETIME_SECONDS`)
 * a nesmí se z něj dělat slib, že odkaz ještě žije. Kdo si na tom slibu postavil
 * nabídku „otevřít znovu", posílal uživatele na hlášku portálu o neexistujícím podání.
 *
 * Cache proto drží odkaz jako JEDNORÁZOVÝ: nabízí se pouze do prvního otevření
 * (`opened`). Jakmile odkaz jednou odešel do prohlížeče — ať už ho otevřel popup
 * hned po vytvoření, nebo uživatel ručně — přestává být nabízený a jediná dál
 * nabízená cesta je vytvořit nový handoff. To je správně bez ohledu na to, jestli
 * je skutečnou příčinou jednorázovost odkazu, nebo životnost kratší než náš odhad.
 *
 * `attemptId` v záznamu zůstává i po spotřebování odkazu — párují se přes něj
 * nahrané artefakty s pokusem o podání.
 */
export interface CachedEpoHandoffLink {
  url: string
  /** Odhad backendu, ne údaj od EPO. Slouží jen jako horní mez, ne jako záruka. */
  expiresAt: string
  attemptId: number
  /** Odkaz už jednou odešel do prohlížeče — dál se nenabízí. */
  opened: boolean
}

type HandoffLinks = Record<number, CachedEpoHandoffLink>

interface StorageLike {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

const STORAGE_PREFIX = 'myinvoice.epo_handoff_links.v1'
const MAX_LINK_LIFETIME_MS = 35 * 60_000
const ALLOWED_HOST = 'adisspr.mfcr.cz'

function storageKey(userId: number, supplierId: number): string {
  return `${STORAGE_PREFIX}.${userId}.${supplierId}`
}

function validLink(value: unknown, now: number): value is CachedEpoHandoffLink {
  if (typeof value !== 'object' || value === null) return false
  const link = value as Partial<CachedEpoHandoffLink>
  if (
    typeof link.url !== 'string'
    || typeof link.expiresAt !== 'string'
    // Záznamy bez `opened` pocházejí ze starší verze, kde se odkaz nabízel opakovaně.
    // Nevíme o nich, jestli už byly spotřebované, takže je zahazujeme celé.
    || typeof link.opened !== 'boolean'
    || !Number.isInteger(link.attemptId)
    || (link.attemptId ?? 0) <= 0
  ) return false

  const expiresAt = new Date(link.expiresAt).getTime()
  if (!Number.isFinite(expiresAt) || expiresAt <= now || expiresAt > now + MAX_LINK_LIFETIME_MS) {
    return false
  }

  try {
    const url = new URL(link.url)
    return url.protocol === 'https:' && url.hostname.toLowerCase() === ALLOWED_HOST
  } catch {
    return false
  }
}

/**
 * Smí se odkaz ještě nabídnout k otevření? Jen dokud neodešel do prohlížeče
 * a zároveň nepřekročil odhadovanou mez. Po spotřebování zbývá jedině nový handoff.
 */
export function canOfferHandoffLink(
  link: CachedEpoHandoffLink | undefined,
  now = Date.now(),
): boolean {
  if (!link || link.opened) return false
  const expiresAt = new Date(link.expiresAt).getTime()
  return Number.isFinite(expiresAt) && expiresAt > now
}

export function loadEpoHandoffLinks(
  storage: StorageLike,
  userId: number,
  supplierId: number,
  now = Date.now(),
): HandoffLinks {
  const key = storageKey(userId, supplierId)
  const raw = storage.getItem(key)
  if (!raw) return {}

  try {
    const parsed = JSON.parse(raw) as Record<string, unknown>
    const links: HandoffLinks = {}
    for (const [submissionId, value] of Object.entries(parsed)) {
      if (/^[1-9]\d*$/.test(submissionId) && validLink(value, now)) {
        links[Number(submissionId)] = value
      }
    }
    if (Object.keys(links).length === 0) storage.removeItem(key)
    else storage.setItem(key, JSON.stringify(links))
    return links
  } catch {
    storage.removeItem(key)
    return {}
  }
}

export function saveEpoHandoffLinks(
  storage: StorageLike,
  userId: number,
  supplierId: number,
  links: HandoffLinks,
  now = Date.now(),
): HandoffLinks {
  const key = storageKey(userId, supplierId)
  const valid = Object.fromEntries(
    Object.entries(links).filter(([, link]) => validLink(link, now)),
  ) as HandoffLinks
  if (Object.keys(valid).length === 0) storage.removeItem(key)
  else storage.setItem(key, JSON.stringify(valid))
  return valid
}
