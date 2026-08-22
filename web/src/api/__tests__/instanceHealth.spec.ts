import { describe, it, expect } from 'vitest'
import type { ManagedBillingInfo, ManagedStorageInfo } from '../license'
import {
  STORAGE_NOTICE_PERCENT,
  areFeaturesLocked,
  currentQuotaGb,
  resolveBillingNarrative,
  resolveStorageLevel,
  resolveStorageMode,
  storageNeedsAttention,
  storageUpgradeOptionsGb,
} from '../instanceHealth'

const GB = 1024 * 1024 * 1024

function storage(over: Partial<ManagedStorageInfo> = {}): ManagedStorageInfo {
  return {
    measured: true,
    measured_at: '2026-08-21T10:00:00+02:00',
    usage_bytes: GB,
    quota_bytes: 2 * GB,
    percent: 50,
    warn_percent: 90,
    read_only_percent: 100,
    blocks_writes: false,
    change_pending: false,
    quota_gb_ordered: null,
    quota_source: 'license',
    ...over,
  }
}

function billing(over: Partial<ManagedBillingInfo> = {}): ManagedBillingInfo {
  return {
    unpaid: false,
    license_state: 'active',
    subscription_state: 'active',
    valid_until: 1_800_000_000,
    last_check_at: '2026-08-21T10:00:00+02:00',
    last_check_ok: true,
    phase: 'active',
    attempt: null,
    max_attempts: null,
    next_attempt_at: null,
    suspend_at: null,
    access_until: null,
    data_until: null,
    amount_due: null,
    currency: null,
    pay_url: null,
    ...over,
  }
}

describe('resolveStorageMode', () => {
  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: implementace, která si spotřebu přetypuje
   * `Number(usage) || 0`, udělá z nezměřené instalace „0 %, vše v pořádku".
   * Prázdná a nezměřená instalace vypadají v datech skoro stejně, ale znamenají
   * opak — jedna je v pohodě, o druhé nevíme nic.
   */
  it('nezměřená instalace není nula', () => {
    expect(resolveStorageMode(storage({ measured: false, usage_bytes: null, percent: null })))
      .toBe('unmeasured')
    expect(resolveStorageMode(storage({ usage_bytes: null }))).toBe('unmeasured')
  })

  it('bez známé kvóty se poměr nepočítá', () => {
    expect(resolveStorageMode(storage({ quota_bytes: null, percent: null }))).toBe('unknown_quota')
    // Kvótu známe, ale procenta server nespočítal — pořád se nesmí dopočítávat.
    expect(resolveStorageMode(storage({ percent: null }))).toBe('unknown_quota')
  })

  it('se známým obsazením i kvótou má poměr smysl', () => {
    expect(resolveStorageMode(storage())).toBe('known')
  })
})

describe('resolveStorageLevel', () => {
  it('pod prahem upozornění nekřičí', () => {
    expect(resolveStorageLevel(storage({ percent: 79.9 }))).toBe('ok')
    expect(storageNeedsAttention('ok')).toBe(false)
  })

  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: dokud existoval jen práh 90 %, spadlo
   * osmdesátiprocentní obsazení do „ok" a zákazník se o docházejícím místě
   * dozvěděl až deset procent před zámkem. Práh 80 je tady záměrně jako jediná
   * konstanta, ne rozsypaný po komponentách.
   */
  it('od 80 % upozorňuje nebloková výzva', () => {
    expect(STORAGE_NOTICE_PERCENT).toBe(80)
    expect(resolveStorageLevel(storage({ percent: 80 }))).toBe('notice')
    expect(resolveStorageLevel(storage({ percent: 89.9 }))).toBe('notice')
    expect(storageNeedsAttention('notice')).toBe(true)
  })

  it('od warn_percent je to důraznější, ale pořád nebloková', () => {
    expect(resolveStorageLevel(storage({ percent: 90 }))).toBe('warning')
    expect(resolveStorageLevel(storage({ percent: 95 }))).toBe('warning')
  })

  it('od read_only_percent jsou zápisy odmítané', () => {
    expect(resolveStorageLevel(storage({ percent: 100 }))).toBe('exhausted')
    expect(resolveStorageLevel(storage({ percent: 140 }))).toBe('exhausted')
  })

  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: implementace, která věří jen poměru,
   * ukáže „zbývá 40 %" instalaci, které middleware právě odmítá každý zápis.
   * Provozní limit pro zámek je jiné číslo než zaplacený objem, takže se to
   * rozejít MŮŽE — a rozhoduje skutečné vynucení.
   */
  it('skutečné vynucení přebíjí poměr', () => {
    expect(resolveStorageLevel(storage({ percent: 60, blocks_writes: true }))).toBe('exhausted')
  })

  it('bez měření a bez kvóty se neukazuje nic', () => {
    expect(resolveStorageLevel(storage({ measured: false, usage_bytes: null, percent: null }))).toBe('none')
    expect(resolveStorageLevel(storage({ quota_bytes: null, percent: null }))).toBe('none')
    expect(resolveStorageLevel(null)).toBe('none')
    expect(storageNeedsAttention('none')).toBe(false)
  })
})

describe('currentQuotaGb', () => {
  it('objednaný objem má přednost před tím, proti čemu se dnes měří', () => {
    // Po dokoupení chvíli platí obojí — pravdivý je ten objednaný.
    expect(currentQuotaGb(storage({ quota_bytes: 2 * GB, quota_gb_ordered: 7 }))).toBe(7)
  })

  it('bez objednávky se odvodí z kvóty', () => {
    expect(currentQuotaGb(storage({ quota_bytes: 22 * GB }))).toBe(22)
  })

  it('neznámá kvóta zůstane neznámá', () => {
    expect(currentQuotaGb(storage({ quota_bytes: null }))).toBeNull()
    expect(currentQuotaGb(null)).toBeNull()
  })
})

describe('storageUpgradeOptionsGb', () => {
  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: nabídnout současnou nebo menší velikost
   * znamená pustit zákazníka do platby, kterou server odmítne (`not_an_upgrade`)
   * — a u „koupit" se nezkouší, jestli to projde.
   */
  it('nabízí jen větší velikosti', () => {
    expect(storageUpgradeOptionsGb(2)).toEqual([7, 22, 102])
    expect(storageUpgradeOptionsGb(22)).toEqual([102])
    expect(storageUpgradeOptionsGb(102)).toEqual([])
  })

  it('neznámou současnou velikost nepřekládá na „nic nenabízet"', () => {
    expect(storageUpgradeOptionsGb(null)).toEqual([2, 7, 22, 102])
  })
})

describe('resolveBillingNarrative', () => {
  it('zaplacená instalace nemá co dramatizovat', () => {
    expect(resolveBillingNarrative(billing())).toBeNull()
    expect(resolveBillingNarrative(null)).toBeNull()
  })

  it('neúspěšné stržení řekne, kolikátý pokus to byl a kdy je další', () => {
    const n = resolveBillingNarrative(billing({
      unpaid: true, phase: 'past_due', subscription_state: 'past_due',
      attempt: 2, max_attempts: 4, next_attempt_at: 1_800_000_100, suspend_at: 1_800_900_000,
    }))!
    expect(n.phase).toBe('past_due')
    expect(n.severity).toBe('warning')
    expect(n.happenedKey).toBe('hosting.phase.happened_past_due')
    expect(n.attempt).toBe(2)
    expect(n.maxAttempts).toBe(4)
    // Nejbližší událost, která se zákazníka dotkne, je další pokus o stržení.
    expect(n.nextKey).toBe('hosting.phase.next_attempt')
    expect(n.nextAt).toBe(1_800_000_100)
    expect(n.featuresLocked).toBe(false)
  })

  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: nejjednodušší implementace vezme první
   * pole z pevného pořadí a napíše „kartu zkusíme znovu" i pozastavené
   * instalaci, kde se už nic zkoušet nebude. Pořadí termínů je součást věty.
   */
  it('u pozastavené instalace mluví o datech, ne o dalším pokusu', () => {
    const n = resolveBillingNarrative(billing({
      unpaid: true, phase: 'suspended', license_state: 'degraded',
      next_attempt_at: 1_800_000_100, data_until: 1_802_000_000, access_until: 1_801_000_000,
    }))!
    expect(n.severity).toBe('critical')
    expect(n.happenedKey).toBe('hosting.phase.happened_suspended')
    expect(n.nextKey).toBe('hosting.phase.next_data_end')
    expect(n.nextAt).toBe(1_802_000_000)
    expect(n.featuresLocked).toBe(true)
  })

  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: implementace, která si chybějící termín
   * nahradí „za 7 dní" nebo `Date.now()`, slíbí zákazníkovi datum, o kterém
   * nikdo nerozhodl. Vymyšlený termín je horší než žádný.
   */
  it('chybějící termín NEDOPOČÍTÁVÁ — vybere větu bez data', () => {
    const n = resolveBillingNarrative(billing({
      unpaid: true, phase: 'past_due', subscription_state: 'past_due',
    }))!
    expect(n.nextKey).toBe('hosting.phase.next_nodate')
    expect(n.nextAt).toBeNull()
    expect(n.milestones).toEqual([])
  })

  it('milníky jdou chronologicky a chybějící se vynechají', () => {
    // Termíny musí být v budoucnosti — minulé se schválně nezobrazují.
    const soon = Math.floor(Date.now() / 1000)
    const n = resolveBillingNarrative(billing({
      unpaid: true, phase: 'past_due',
      next_attempt_at: soon + 300, suspend_at: soon + 100, access_until: null, data_until: soon + 200,
    }))!
    expect(n.milestones).toEqual([
      { kind: 'suspend', at: soon + 100 },
      { kind: 'data_end', at: soon + 200 },
      { kind: 'next_attempt', at: soon + 300 },
    ])
  })

  /**
   * ⚠️ PROČ BY TO BEZ OPRAVY PADALO: stav předplatného se v instalaci obnovuje
   * nejvýš jednou denně. Když vypadne cron plateb nebo se vymáhání zastaví,
   * implementace, která filtruje jen `> 0`, hlásí ještě týdny „kartu zkusíme
   * znovu 5. 9." o datu, které dávno minulo. Radši neřekneme nic než nepravdu.
   */
  it('termíny v minulosti se neukazují jako nadcházející', () => {
    const now = Math.floor(Date.now() / 1000)
    const n = resolveBillingNarrative(billing({
      unpaid: true, phase: 'past_due', subscription_state: 'past_due',
      next_attempt_at: now - 86400, suspend_at: now - 3600, access_until: null, data_until: now + 86400,
    }))!
    expect(n.milestones).toEqual([{ kind: 'data_end', at: now + 86400 }])
    // Ve fázi past_due je nejbližší událostí další pokus o stržení. Když je
    // jeho termín prošlý, aplikace radši neřekne datum žádné — vzít místo něj
    // konec retence by znamenalo hlásit úplně jinou událost.
    expect(n.nextAt).toBeNull()
    expect(n.nextKey).toBe('hosting.phase.next_nodate')
  })

  it('zavřené placené funkce hlásí i bez známé fáze', () => {
    const n = resolveBillingNarrative(billing({
      unpaid: true, phase: null, license_state: 'trial_expired', subscription_state: null,
    }))!
    expect(n.phase).toBeNull()
    expect(n.severity).toBe('critical')
    expect(n.happenedKey).toBe('hosting.phase.happened_locked')
    expect(n.featuresLocked).toBe(true)
  })

  it('zrušené předplatné se hlásí, i když ještě není po splatnosti', () => {
    const n = resolveBillingNarrative(billing({
      unpaid: false, phase: 'cancelled', subscription_state: 'cancelled', access_until: 1_801_000_000,
    }))!
    expect(n.severity).toBe('warning')
    expect(n.happenedKey).toBe('hosting.phase.happened_cancelled')
    expect(n.nextKey).toBe('hosting.phase.next_access_end')
  })
})

describe('areFeaturesLocked', () => {
  it('zavřeno je jen v degraded a trial_expired', () => {
    expect(areFeaturesLocked(billing({ license_state: 'degraded' }))).toBe(true)
    expect(areFeaturesLocked(billing({ license_state: 'trial_expired' }))).toBe(true)
    // Overage je překročený rozsah, ne zavřené moduly — nesmí se slít.
    expect(areFeaturesLocked(billing({ license_state: 'overage' }))).toBe(false)
    expect(areFeaturesLocked(billing({ license_state: 'trial' }))).toBe(false)
    expect(areFeaturesLocked(null)).toBe(false)
  })
})
