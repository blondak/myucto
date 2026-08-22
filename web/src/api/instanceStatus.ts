/**
 * Sdílený stav spravované instalace — jedno načtení pro celou aplikaci.
 *
 * Proč vlastní modul a ne dotaz v každé komponentě: stav potřebují najednou tři
 * místa (červená linka nad aplikací, pruh s docházejícím místem a obrazovka
 * Předplatné a provoz) a musí říkat totéž. Tři nezávislé dotazy by se navíc
 * střílely při každé změně stránky.
 *
 * ── Proč se to vůbec tahá zvlášť ──────────────────────────────────────────
 * Hlavičky `X-Storage-Quota-*` veze každá odpověď, ale backend je posílá až od
 * 90 % — dřív není co vynucovat. Upozornění na 80 % je otázka pro
 * `/api/license/status`, a ten je jen pro admina. To dává smysl i věcně: místo
 * dokoupí stejně jen ten, kdo na to má právo.
 *
 * ⚠️ Načítá se LÍNĚ a jen jednou: na self-hosted instalaci a běžnému uživateli
 * se nezavolá vůbec. Selhání je tichá věc — chybějící stav znamená „nevíme",
 * takže se prostě neukáže nic. Nikdy se nesmí přeložit jako „vše v pořádku"
 * způsobem, který by schoval blokující linku; ta stojí na vlastním, lepkavém
 * zdroji (viz `storageQuota.isCriticallyExhausted`).
 */

import { computed, ref } from 'vue'
import { licenseApi, type BillingDunningInfo, type LicenseStatus } from './license'
import { buildPreviewStatus, instancePreview } from './instancePreview'

const loaded = ref<LicenseStatus | null>(null)
const loading = ref(false)
let inflight: Promise<LicenseStatus | null> | null = null

/**
 * Dunning stav pro BĚŽNÉHO admina (`/api/license/billing`).
 *
 * Proč zvlášť od `loaded`: `/api/license/status` je superadmin-only a to se
 * nemění — je za ním licenční klíč i fakturační údaje. Jenže „nezdařila se
 * platba, do 14 dnů se instalace pozastaví" musí vidět i ten, kdo instalaci
 * reálně spravuje; jinak se to dozví až tím, že aplikace přestane fungovat
 * a doplatit už z ní nepůjde.
 */
const dunningLoaded = ref<BillingDunningInfo | null>(null)
let dunningInflight: Promise<BillingDunningInfo | null> | null = null

/**
 * Stav, podle kterého se KRESLÍ. Když běží náhled, je to on — a to je jediné,
 * co náhled dělá: přebarví obrazovku, nikoli oprávnění.
 */
const effective = computed<LicenseStatus | null>(() => {
  const scenario = instancePreview.scenario.value
  if (scenario !== null) return buildPreviewStatus(scenario)

  return loaded.value
})

/**
 * Načte stav, pokud dává smysl ho mít. Opakovaná volání jsou zdarma.
 *
 * @param opts.managed    spravovaná instalace (`app.managed`)
 * @param opts.superadmin `/api/license/status` je jen pro admina
 * @param opts.force      vynutí nové načtení (po dokoupení)
 */
export async function ensureInstanceStatus(
  opts: { managed: boolean; superadmin: boolean; force?: boolean },
): Promise<LicenseStatus | null> {
  if (!opts.managed || !opts.superadmin) return null
  if (!opts.force && (loaded.value !== null || inflight !== null)) {
    return inflight ?? loaded.value
  }

  loading.value = true
  inflight = licenseApi.status()
    .then((status) => {
      loaded.value = status
      return status
    })
    // Nevíme = neukazujeme. Vyhozená výjimka odsud by shodila render banneru,
    // který má v takové chvíli jen mlčet.
    .catch(() => null)
    .finally(() => {
      loading.value = false
      inflight = null
    })

  return inflight
}

/**
 * Načte dunning stav. Smí ho mít KAŽDÝ přihlášený na spravované instalaci —
 * jediná výjimka z licenční superadmin brány (viz `licenseApi.dunning`).
 *
 * Superadmin ho nepotřebuje: jeho `/license/status` nese totéž a víc, takže se
 * druhý dotaz nedělá. Selhání je tichá věc — chybějící stav znamená „nevíme"
 * a nikdy se nesmí přeložit jako „zaplaceno".
 *
 * @param opts.managed spravovaná instalace (`app.managed`)
 * @param opts.force   vynutí nové načtení
 */
export async function ensureInstanceDunning(
  opts: { managed: boolean; force?: boolean },
): Promise<BillingDunningInfo | null> {
  if (!opts.managed) return null
  if (!opts.force && (dunningLoaded.value !== null || dunningInflight !== null)) {
    return dunningInflight ?? dunningLoaded.value
  }

  dunningInflight = licenseApi.dunning()
    .then((billing) => {
      dunningLoaded.value = billing
      return billing
    })
    .catch(() => null)
    .finally(() => { dunningInflight = null })

  return dunningInflight
}

/** Zahodí načtený stav (odhlášení, přepnutí instalace). Náhledu se netýká. */
export function resetInstanceStatus(): void {
  loaded.value = null
  inflight = null
  dunningLoaded.value = null
  dunningInflight = null
}

/** Ručně vložený stav — obrazovka, která si ho načetla sama, ho sdílí dál. */
export function publishInstanceStatus(status: LicenseStatus | null): void {
  loaded.value = status
}

export const instanceStatus = {
  loading: computed(() => loading.value),
  status: effective,
  instance: computed(() => effective.value?.instance ?? null),
  storage: computed(() => effective.value?.instance?.storage ?? null),
  billing: computed(() => effective.value?.instance?.billing ?? null),
  /**
   * Dunning stav bez ohledu na práva. Plný stav vyhrává, když ho máme — je to
   * ta samá podmnožina počítaná na jednom místě (`BillingSnapshot`), takže se
   * obě cesty nemůžou rozejít.
   */
  dunning: computed<BillingDunningInfo | null>(
    () => effective.value?.instance?.billing ?? dunningLoaded.value,
  ),
}
