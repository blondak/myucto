<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { storageQuota } from '@/api/storageQuota'
import { resolveInstanceAlerts, type InstanceAlertReason } from '@/api/instanceAlert'
import { ensureInstanceStatus, instanceStatus } from '@/api/instanceStatus'
import { instancePreview } from '@/api/instancePreview'
import { resolveBillingNarrative } from '@/api/instanceHealth'
import { ICONS } from '@/components/ui/buttonStyles'

/**
 * Červená linka nad aplikací — instalace něco NEDĚLÁ a uživatel to musí vědět
 * hned (H-31).
 *
 * Proč není vedle ostatních pruhů v `<main>`: ty se dají odrolovat a mizí spolu
 * s obsahem stránky. Tahle linka sedí UVNITŘ připnuté horní lišty, takže je
 * pořád na očích a přitom nic nepřekrývá. Svou výšku hlásí do CSS proměnné
 * `--instance-alert-h`, o kterou se odsadí připnutý sidebar (jinak by mu linka
 * schovala první položku menu).
 *
 * ⚠️ Čtyři věci, které se tu nesmí rozvolnit:
 *
 *  1. **Nemá zavírací prvek.** Žádný křížek, žádné „rozumím", žádné uložení do
 *     localStorage. Zmizí jedině tím, že závada přestane platit. Kdo si ji
 *     odklikne, dozví se o zastavených zápisech až u ztraceného dokladu.
 *  2. **Nezobrazí se na self-hosted instalaci.** Rozhoduje `app.managed`
 *     (viz {@link resolveInstanceAlerts}) — kvótu ani předplatné tam nikdo
 *     nespravuje.
 *  3. **Nezhasne kvůli neúspěšnému dotazu.** Vstupem je poslední ZNÁMÝ stav;
 *     „nevím" se nikdy nečte jako „v pořádku".
 *  4. **Text říká tři věci: co, proč, co s tím.** Ne „došlo k chybě".
 *
 * Varování na 90 % sem ZÁMĚRNĚ nepatří — to zůstává žlutým, neblokujícím
 * pruhem `StorageQuotaBanner`.
 *
 * ── Co se stalo a co bude ─────────────────────────────────────────────────
 * „Není zaplaceno" samo o sobě nikomu neřekne, jestli má den nebo dva měsíce.
 * Linka proto k důvodu přidává dvě věty z {@link resolveBillingNarrative}:
 * co se stalo a co bude, a kdy. Termíny počítá licenční server; co neposlal,
 * se NEDOPOČÍTÁVÁ — místo vymyšleného data se řekne, že se ozveme.
 */
const { t } = useI18n()
const auth = useAuthStore()

/**
 * Náhled stavů (jen superadmin, jen na vyžádání — viz `@/api/instancePreview`).
 * Přebarvuje linku, aby šlo ukázat, jak vypadá; na oprávnění nesahá.
 */
const previewing = instancePreview.isActive

/**
 * Podrobnosti o platbě zná jen `/api/license/status` (admin). Načte se líně
 * a jednou; běžný uživatel ani self-hosted instalace ho nezavolají vůbec
 * a linka se pak chová jako dřív — jen bez dvou vět navíc.
 */
watch(
  () => [auth.isManagedInstallation, auth.isSuperadmin] as const,
  ([managed, superadmin]) => { void ensureInstanceStatus({ managed, superadmin }) },
  { immediate: true },
)

const billing = instanceStatus.billing

const reasons = computed<InstanceAlertReason[]>(() => resolveInstanceAlerts({
  managed: previewing.value ? true : auth.isManagedInstallation,
  // ⚠️ Mimo náhled zůstává zdrojem LEPKAVÝ stav z hlaviček odpovědí, ne
  // načtený status: ten se dotahuje jednou a spadlý dotaz nesmí linku zhasnout.
  storageExhausted: previewing.value
    ? (instanceStatus.storage.value?.blocks_writes ?? false)
    : storageQuota.isCriticallyExhausted.value,
  licenseState: previewing.value
    ? (billing.value?.license_state ?? null)
    : (auth.license?.state ?? null),
  // ⚠️ Stav předplatného, ne jen licence. Licence může být pořád platná,
  // a přitom je zákazník po splatnosti — token doběhne až na konci
  // zaplaceného období. Bez tohohle by se výzva k úhradě objevila teprve
  // ve chvíli, kdy se komerční moduly zavřou, tedy až když je pozdě.
  subscriptionState: previewing.value
    ? (billing.value?.subscription_state ?? null)
    : (auth.license?.subscription_state ?? null),
}))

const visible = computed(() => reasons.value.length > 0)

/** Dvě věty k neuhrazení. `null` = stav neznáme a nic si nedomýšlíme. */
const narrative = computed(() => resolveBillingNarrative(billing.value))

function fmtDate(ts: number | null): string {
  return ts === null ? '' : new Date(ts * 1000).toLocaleDateString()
}

/** Věta „co se stalo" (+ kolikátý pokus, když to server poslal). */
const happenedText = computed(() => {
  const n = narrative.value
  if (!n) return null
  const base = t(n.happenedKey)
  if (n.attempt === null || n.maxAttempts === null) return base

  return `${base} ${t('hosting.phase.attempt_of', { attempt: n.attempt, max: n.maxAttempts })}`
})

/** Věta „co bude a kdy". Bez termínu se použije varianta, která žádný neslibuje. */
const nextText = computed(() => {
  const n = narrative.value
  if (!n) return null

  return t(n.nextKey, { date: fmtDate(n.nextAt) })
})

/** Kam vede „co s tím". Obojí je vnitřní stránka s vysvětlením i cestou k nápravě. */
const TARGET: Record<InstanceAlertReason, string> = {
  storage_exhausted: '/hosting',
  unpaid: '/activation/purchase',
}

const bar = ref<HTMLElement | null>(null)
let observer: ResizeObserver | null = null

// Proměnná musí existovat UŽ PŘED prvním vykreslením: připnutý sidebar si svůj
// offset počítá `calc()`em a calc s NEDEFINOVANOU proměnnou je neplatná hodnota,
// kterou prohlížeč tiše zahodí — sidebar by pak neměl top vůbec.
document.documentElement.style.setProperty('--instance-alert-h', '0px')

/**
 * Výška linky do CSS proměnné, aby se o ni layout odsadil. Píše se i nula —
 * jinak by po zhasnutí linky zůstalo prázdné místo nahoře.
 */
function publishHeight(): void {
  const height = visible.value ? (bar.value?.offsetHeight ?? 0) : 0
  document.documentElement.style.setProperty('--instance-alert-h', `${height}px`)
}

onMounted(() => {
  publishHeight()
  // ResizeObserver v jsdom není — bez něj se výška prostě nepřepočítá při
  // zalomení textu, ale linka funguje dál.
  if (typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(publishHeight)
    if (bar.value) observer.observe(bar.value)
  }
  window.addEventListener('resize', publishHeight)
})

watch(visible, async () => {
  await nextTick()
  if (observer) {
    observer.disconnect()
    if (bar.value) observer.observe(bar.value)
  }
  publishHeight()
})

onBeforeUnmount(() => {
  observer?.disconnect()
  window.removeEventListener('resize', publishHeight)
  document.documentElement.style.setProperty('--instance-alert-h', '0px')
})
</script>

<template>
  <div
    v-if="visible"
    ref="bar"
    class="w-full bg-danger-600 text-white"
    role="alert"
    aria-live="assertive"
    data-instance-critical-bar
  >
    <div
      v-for="reason in reasons"
      :key="reason"
      class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm border-b border-white/20 last:border-b-0"
      :data-instance-critical-reason="reason"
    >
      <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" />
      </svg>
      <span
        v-if="previewing"
        class="shrink-0 rounded bg-white/25 px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wider"
      >{{ t('hosting.preview_badge') }}</span>
      <span class="font-semibold whitespace-nowrap">{{ t(`instance_alert.${reason}_title`) }}</span>
      <span class="min-w-0 flex-1 text-white/90">
        {{ t(`instance_alert.${reason}_desc`) }}
        <!-- Co se stalo a co bude — jen u neuhrazení a jen když to server řekl.
             Bez dat se tu nesmí objevit žádný termín. -->
        <template v-if="reason === 'unpaid' && happenedText">
          <span class="ml-1">{{ happenedText }}</span>
          <span class="ml-1">{{ nextText }}</span>
        </template>
      </span>
      <RouterLink
        :to="TARGET[reason]"
        class="shrink-0 whitespace-nowrap rounded-md bg-white/15 px-3 py-1 font-medium underline underline-offset-2 hover:bg-white/25"
      >
        {{ t(`instance_alert.${reason}_cta`) }}
      </RouterLink>
    </div>
  </div>
</template>
