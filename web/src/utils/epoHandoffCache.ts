export interface CachedEpoHandoffLink {
  url: string
  expiresAt: string
  attemptId: number
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
