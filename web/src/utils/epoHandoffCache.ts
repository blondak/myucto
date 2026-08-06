/**
 * Cache odkazu do interaktivního formuláře EPO.
 *
 * EPO nám o odkazu neřekne nic než URL — ani platnost, ani jestli je jednorázový.
 * `expiresAt` je proto jen odhad backendu (viz `EpoClient::ESTIMATED_LINK_LIFETIME_SECONDS`)
 * a nesmí se z něj dělat slib, že odkaz ještě žije.
 *
 * Odkaz se proto smí znovu nabídnout jen tehdy, když platí obojí:
 *
 * 1. **Okno {@link HANDOFF_LINK_LIFETIME_MS}.** Portál v chybové hlášce sám uvádí
 *    session zhruba 30 minut od poslední aktivity; 20 minut je rezerva pod tím.
 * 2. **Podklad se nezměnil.** Když uživatel mezitím opraví doklady a snapshot se
 *    přepočítá, míří starý odkaz na neaktuální písemnost — nabízet ho je horší,
 *    než ho zahodit. Váže se na SHA-256 otisk, který archiv u snapshotu už vede
 *    (`tax_submissions.xml_sha256`); druhý otisk se kvůli tomu nezavádí.
 *
 * `attemptId` v záznamu zůstává i po vypršení okna — párují se přes něj nahrané
 * artefakty s pokusem o podání.
 */
export interface CachedEpoHandoffLink {
  url: string
  /** Odhad backendu, ne údaj od EPO. Slouží jen jako horní mez, ne jako záruka. */
  expiresAt: string
  attemptId: number
  /** Otisk snapshotu v okamžiku vytvoření odkazu — proti němu se pozná přepočtený podklad. */
  xmlSha256: string
}

type HandoffLinks = Record<number, CachedEpoHandoffLink>

interface StorageLike {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

// Klíč zůstává `v1`: starší záznamy (bez otisku podkladu) neprojdou validací
// a `loadEpoHandoffLinks` je i s klíčem rovnou uklidí. Nová verze klíče by je
// nechala v úložišti ležet napořád.
const STORAGE_PREFIX = 'myinvoice.epo_handoff_links.v1'
/** Musí odpovídat `EpoClient::ESTIMATED_LINK_LIFETIME_SECONDS` — jinak si dvě čísla protiřečí. */
export const HANDOFF_LINK_LIFETIME_MS = 20 * 60_000
/** `expiresAt` počítá server, tohle je tolerance na rozdíl jeho a prohlížečových hodin. */
const CLOCK_SKEW_TOLERANCE_MS = 5 * 60_000
const ALLOWED_HOST = 'adisspr.mfcr.cz'
const SHA256_HEX = /^[0-9a-f]{64}$/i

function storageKey(userId: number, supplierId: number): string {
  return `${STORAGE_PREFIX}.${userId}.${supplierId}`
}

function validLink(value: unknown, now: number): value is CachedEpoHandoffLink {
  if (typeof value !== 'object' || value === null) return false
  const link = value as Partial<CachedEpoHandoffLink>
  if (
    typeof link.url !== 'string'
    || typeof link.expiresAt !== 'string'
    // Bez otisku podkladu nelze poznat, jestli odkaz nemíří na přepočtenou písemnost.
    || typeof link.xmlSha256 !== 'string'
    || !SHA256_HEX.test(link.xmlSha256)
    || !Number.isInteger(link.attemptId)
    || (link.attemptId ?? 0) <= 0
  ) return false

  const expiresAt = new Date(link.expiresAt).getTime()
  if (
    !Number.isFinite(expiresAt)
    || expiresAt <= now
    || expiresAt > now + HANDOFF_LINK_LIFETIME_MS + CLOCK_SKEW_TOLERANCE_MS
  ) {
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
 * Smí se odkaz ještě nabídnout k otevření? Jen dokud běží okno platnosti a zároveň
 * je podklad pořád ten, ke kterému odkaz vznikl. Jinak zbývá jedině nový handoff.
 */
export function canOfferHandoffLink(
  link: CachedEpoHandoffLink | undefined,
  currentXmlSha256: string,
  now = Date.now(),
): boolean {
  if (!link) return false
  if (!SHA256_HEX.test(currentXmlSha256) || link.xmlSha256.toLowerCase() !== currentXmlSha256.toLowerCase()) {
    return false
  }
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
