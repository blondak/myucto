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
import { licenseApi, type LicenseStatus } from './license'
import { buildPreviewStatus, instancePreview } from './instancePreview'

const loaded = ref<LicenseStatus | null>(null)
const loading = ref(false)
let inflight: Promise<LicenseStatus | null> | null = null

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

/** Zahodí načtený stav (odhlášení, přepnutí instalace). Náhledu se netýká. */
export function resetInstanceStatus(): void {
  loaded.value = null
  inflight = null
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
}
