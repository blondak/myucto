/**
 * Stav spravované instalace přeložený do vět, které dávají smysl zákazníkovi.
 *
 * Jedno místo pro tři věci, které se jinak rozsypou po komponentách a rozejdou:
 *   1. PRAHY OBSAZENÍ MÍSTA — od kdy se upozorňuje, od kdy důrazně, od kdy je
 *      zamčeno ({@link STORAGE_NOTICE_PERCENT}, {@link resolveStorageLevel}).
 *   2. VELIKOSTI ÚLOŽIŠTĚ, které se dají dokoupit ({@link STORAGE_SIZES_GB}).
 *   3. FÁZE NEUHRAZENÍ — co se stalo a co bude ({@link resolveBillingNarrative}).
 *
 * Schválně je to čistý TypeScript bez Vue a bez axiosu: jsou to pravidla, ne
 * zobrazení, a mají jít otestovat bez namountované komponenty.
 *
 * ── ⚠️ Dvě věci, které se tu nesmí rozvolnit ──────────────────────────────
 *
 *  1. **„Nevím" se nikdy nečte jako „v pořádku" ani jako nula.** Nezměřená
 *     instalace není prázdná; neznámá kvóta není nekonečná. Obojí končí
 *     stavem, ve kterém se NIC neukazuje, ne uklidňující nulou.
 *  2. **Termín, který neposlal server, se nedopočítává.** Datum, které si
 *     aplikace vymyslí, je slib, který nikdo nedodrží. Když chybí, vybere se
 *     varianta věty BEZ termínu (`*_nodate`) — mlčet je lepší než lhát.
 */

import type { BillingDunningInfo, ManagedStorageInfo } from './license'

// ─────────────────────────────────────────────────────────────────────────────
// Místo na disku
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Od kolika procent se ozveme, že místo dochází.
 *
 * ⚠️ Konstanta ŽIJE TADY a nikde jinde. Práh 90 % (`warn_percent`) a 100 %
 * (`read_only_percent`) posílá backend, protože je vynucuje; tenhle je čistě
 * o tom, kdy má smysl zákazníka upozornit dřív, než ho to začne bolet, takže
 * ho nikdo nevynucuje a rozsypal by se po komponentách nejsnáz.
 *
 * (E-mail od 90 % rozesílá licenční server — aplikace se o poštu nestará.)
 */
export const STORAGE_NOTICE_PERCENT = 80

/**
 * Velikosti úložiště, které se dají objednat (GB).
 *
 * ⚠️ Jsou to CÍLOVÉ hodnoty, ne přírůstky: kdo má 2 GB a chce „+5 GB", objedná
 * `7`. Backend jiné číslo odmítne (`invalid_quota`).
 */
export const STORAGE_SIZES_GB = [2, 7, 22, 102] as const

const BYTES_PER_GB = 1024 * 1024 * 1024

/**
 * Máme čím počítat?
 *  - `unmeasured` — ještě se neměřilo. NENÍ to nula.
 *  - `unknown_quota` — víme kolik, ne z kolika → žádný pruh, žádná procenta.
 *  - `known` — teprve tady má poměr smysl.
 */
export type StorageMode = 'unmeasured' | 'unknown_quota' | 'known'

/**
 * Jak moc to hoří.
 *  - `none` — nevíme (nic se neukazuje), nebo se vůbec neměří.
 *  - `ok` — pod prahem upozornění; řádek NEKŘIČÍ.
 *  - `notice` — od {@link STORAGE_NOTICE_PERCENT}; nebloková výzva.
 *  - `warning` — od `warn_percent`; důrazně, ale pořád nebloková.
 *  - `exhausted` — zápisy jsou odmítané.
 */
export type StorageLevel = 'none' | 'ok' | 'notice' | 'warning' | 'exhausted'

export function resolveStorageMode(storage: ManagedStorageInfo | null | undefined): StorageMode {
  if (!storage || !storage.measured || storage.usage_bytes === null) return 'unmeasured'
  if (storage.quota_bytes === null || storage.percent === null) return 'unknown_quota'
  return 'known'
}

/**
 * Úroveň obsazení. Skutečné vynucení (`blocks_writes`) přebíjí poměr —
 * instalace může být zamčená dřív, než poměr dojde na sto procent (kvótu
 * pro zámek smí provozovatel nastavit zvlášť).
 */
export function resolveStorageLevel(storage: ManagedStorageInfo | null | undefined): StorageLevel {
  if (!storage) return 'none'
  if (storage.blocks_writes) return 'exhausted'
  if (resolveStorageMode(storage) !== 'known') return 'none'

  const percent = storage.percent as number
  if (percent >= storage.read_only_percent) return 'exhausted'
  if (percent >= storage.warn_percent) return 'warning'
  if (percent >= STORAGE_NOTICE_PERCENT) return 'notice'
  return 'ok'
}

/** Má se o obsazení vůbec zmínit? `ok` mlčí — křičí jen to, co se má řešit. */
export function storageNeedsAttention(level: StorageLevel): boolean {
  return level === 'notice' || level === 'warning' || level === 'exhausted'
}

/**
 * Kolik GB má zákazník zaplaceno, jak nejlíp to jde zjistit.
 *
 * Přednost má objednaný objem: po dokoupení je to on, kdo je pravdivý, zatímco
 * `quota_bytes` může ještě chvíli ukazovat starou hodnotu. `null` = nevíme,
 * a pak se nesmí nic dopočítávat.
 */
export function currentQuotaGb(storage: ManagedStorageInfo | null | undefined): number | null {
  if (!storage) return null
  if (typeof storage.quota_gb_ordered === 'number' && storage.quota_gb_ordered > 0) {
    return storage.quota_gb_ordered
  }
  if (storage.quota_bytes === null || storage.quota_bytes <= 0) return null

  return Math.round(storage.quota_bytes / BYTES_PER_GB)
}

/**
 * Cílové velikosti, na které lze předplatné změnit. Vyšší se aktivuje
 * hned po poměrném doplatku, nižší server naplánuje od dalšího období.
 *
 * Když současnou neznáme, nabídnou se všechny a rozhodne autoritativní server.
 */
export function storageUpgradeOptionsGb(currentGb: number | null): number[] {
  const sizes = [...STORAGE_SIZES_GB]
  if (currentGb === null) return sizes

  return sizes.filter((gb) => gb !== currentGb)
}

// ─────────────────────────────────────────────────────────────────────────────
// Fáze neuhrazení
// ─────────────────────────────────────────────────────────────────────────────

export type BillingPhase = 'active' | 'past_due' | 'suspended' | 'expired' | 'cancelled'

const KNOWN_PHASES: BillingPhase[] = ['active', 'past_due', 'suspended', 'expired', 'cancelled']

/** Milník na časové ose. Do seznamu se dostane JEN termín, který poslal server. */
export interface BillingMilestone {
  kind: 'next_attempt' | 'suspend' | 'access_end' | 'data_end'
  /** Unix timestamp. */
  at: number
}

/**
 * Dvě věty o stavu platby: co se stalo a co bude.
 *
 * Vrací i18n KLÍČE, ne hotový text — formátování data i překlad patří
 * komponentě, rozhodování sem. Díky tomu jde pravidlo otestovat bez i18n.
 */
export interface BillingNarrative {
  phase: BillingPhase | null
  severity: 'warning' | 'critical'
  /** Věta „co se stalo". */
  happenedKey: string
  /** Kolikátý pokus selhal; `null` když to server neposlal (věta se vynechá). */
  attempt: number | null
  maxAttempts: number | null
  /** Věta „co bude a kdy". Klíč `*_nodate` = termín neznáme a NEUVÁDÍME ho. */
  nextKey: string
  /** Termín k větě výše; `null` ⇒ `nextKey` končí na `_nodate`. */
  nextAt: number | null
  /** Termíny, které server poslal, chronologicky. Chybějící se prostě vynechá. */
  milestones: BillingMilestone[]
  /** Placené moduly jsou zavřené (uživatel musí vidět, co konkrétně nejde). */
  featuresLocked: boolean
}

const I18N_PREFIX = 'hosting.phase'

function normalizePhase(phase: string | null | undefined): BillingPhase | null {
  return KNOWN_PHASES.includes(phase as BillingPhase) ? (phase as BillingPhase) : null
}

/**
 * Je ten termín ještě před námi?
 *
 * ⚠️ Termín v minulosti se NESMÍ ukazovat jako nadcházející. Stav
 * předplatného se v instalaci obnovuje nejvýš jednou denně, takže když
 * vypadne cron plateb nebo se vymáhání zastaví, jinak by aplikace ještě
 * týdny hlásila „kartu zkusíme znovu 5. 9." o datu, které dávno minulo.
 * Radši neřekneme nic než nepravdu.
 */
function isUpcoming(at: number | null | undefined): at is number {
  return typeof at === 'number' && at > 0 && at * 1000 > Date.now()
}

/** První termín, který server opravdu poslal a který ještě nenastal. */
function firstKnown(
  billing: BillingDunningInfo,
  order: Array<keyof Pick<BillingDunningInfo, 'next_attempt_at' | 'suspend_at' | 'access_until' | 'data_until'>>,
): { field: string; at: number } | null {
  for (const field of order) {
    const at = billing[field]
    if (isUpcoming(at)) return { field, at }
  }
  return null
}

const NEXT_KEY_BY_FIELD: Record<string, string> = {
  next_attempt_at: `${I18N_PREFIX}.next_attempt`,
  suspend_at: `${I18N_PREFIX}.next_suspend`,
  access_until: `${I18N_PREFIX}.next_access_end`,
  data_until: `${I18N_PREFIX}.next_data_end`,
}

function milestones(billing: BillingDunningInfo): BillingMilestone[] {
  const raw: Array<[BillingMilestone['kind'], number | null]> = [
    ['next_attempt', billing.next_attempt_at],
    ['suspend', billing.suspend_at],
    ['access_end', billing.access_until],
    ['data_end', billing.data_until],
  ]

  return raw
    .filter((entry): entry is [BillingMilestone['kind'], number] => isUpcoming(entry[1]))
    .map(([kind, at]) => ({ kind, at }))
    .sort((a, b) => a.at - b.at)
}

/** Stavy licence, ve kterých jsou placené moduly zavřené. */
export function areFeaturesLocked(billing: BillingDunningInfo | null | undefined): boolean {
  return billing?.license_state === 'degraded' || billing?.license_state === 'trial_expired'
}

/**
 * Co se stalo a co bude. `null` = není co hlásit (zaplaceno a nic neběží
 * k výpovědi) — obrazovka pak ukáže normální řádek „zaplaceno do".
 *
 * ⚠️ Pořadí termínů uvnitř fáze není kosmetika: uživatele zajímá NEJBLIŽŠÍ
 * událost, která se ho dotkne. U `past_due` je to další pokus o stržení,
 * u pozastavené instance už jen to, dokdy držíme data.
 */
export function resolveBillingNarrative(
  billing: BillingDunningInfo | null | undefined,
): BillingNarrative | null {
  if (!billing) return null

  const phase = normalizePhase(billing.phase)
  const locked = areFeaturesLocked(billing)

  // Zaplaceno a nic neběží k výpovědi → obrazovka nemá co dramatizovat.
  if (!billing.unpaid && !locked && (phase === null || phase === 'active')) return null

  const order = ((): Array<'next_attempt_at' | 'suspend_at' | 'access_until' | 'data_until'> => {
    switch (phase) {
      case 'past_due': return ['next_attempt_at', 'suspend_at', 'access_until']
      case 'suspended': return ['data_until', 'access_until']
      case 'expired': return ['data_until', 'access_until']
      case 'cancelled': return ['access_until', 'data_until']
      // Fáze neznámá (starší server) — jde se od nejbližšího tvrdého dopadu.
      default: return ['next_attempt_at', 'suspend_at', 'access_until', 'data_until']
    }
  })()

  const next = firstKnown(billing, order)

  const happenedKey = ((): string => {
    switch (phase) {
      case 'past_due': return `${I18N_PREFIX}.happened_past_due`
      case 'suspended': return `${I18N_PREFIX}.happened_suspended`
      case 'expired': return `${I18N_PREFIX}.happened_expired`
      case 'cancelled': return `${I18N_PREFIX}.happened_cancelled`
      // Fázi neznáme; říct smíme jen to, co je jistě pravda.
      default: return locked
        ? `${I18N_PREFIX}.happened_locked`
        : `${I18N_PREFIX}.happened_unpaid`
    }
  })()

  const severity: BillingNarrative['severity'] =
    locked || phase === 'suspended' || phase === 'expired' ? 'critical' : 'warning'

  return {
    phase,
    severity,
    happenedKey,
    attempt: billing.attempt,
    maxAttempts: billing.max_attempts,
    // ⚠️ Bez termínu se použije věta, která žádný neslibuje.
    nextKey: next ? NEXT_KEY_BY_FIELD[next.field] : `${I18N_PREFIX}.next_nodate`,
    nextAt: next?.at ?? null,
    milestones: milestones(billing),
    featuresLocked: locked,
  }
}
