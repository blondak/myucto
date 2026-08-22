<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { storageQuota } from '@/api/storageQuota'
import { resolveInstanceAlerts, type InstanceAlertReason } from '@/api/instanceAlert'
import { ensureInstanceDunning, ensureInstanceStatus, instanceStatus } from '@/api/instanceStatus'
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
 * Podrobnosti o platbě. Superadmin je má v `/api/license/status`, běžný admin
 * v užším `/api/license/billing` — obojí líně a jednou.
 *
 * ⚠️ Druhý dotaz existuje proto, že linka byla adminovi bez superadmin práv
 * k ničemu: viděl „není zaplaceno" bez částky, bez termínů a bez cesty
 * k úhradě. Self-hosted instalace nezavolá ani jedno.
 */
watch(
  () => [auth.isManagedInstallation, auth.isSuperadmin] as const,
  ([managed, superadmin]) => {
    void ensureInstanceStatus({ managed, superadmin })
    if (!superadmin) void ensureInstanceDunning({ managed })
  },
  { immediate: true },
)

const billing = instanceStatus.dunning

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

/**
 * Popis u neuhrazení.
 *
 * ⚠️ Nesmí tvrdit, že jsou moduly zavřené, když zavřené NEJSOU. Po první
 * neúspěšné platbě (`past_due`) licence pořád běží a účetnictví i mzdy fungují
 * dál — kdo si v tu chvíli přečte „účetnictví je zavřené", buď zpanikaří,
 * nebo (hůř) zjistí, že linka lže, a přestane jí věřit i ve chvíli, kdy má
 * pravdu. O tom, co je zavřené, rozhoduje stav licence, ne dluh.
 */
const unpaidDescKey = computed(() => {
  const n = narrative.value
  // Stav neznáme (klientský účet, spadlý dotaz) → původní, obecná formulace.
  if (!n) return 'instance_alert.unpaid_desc'

  return n.featuresLocked ? 'instance_alert.unpaid_desc' : 'instance_alert.unpaid_desc_open'
})

/**
 * ── DOPLATIT PŘÍMO ODSUD ─────────────────────────────────────────────────
 * Odkaz vydává licenční server (`pay_url`); backend za něj v nejhorším dosadí
 * správu předplatného, takže tlačítko má vždycky kam vést. `null` znamená, že
 * není nakonfigurovaná ani ta — pak zůstává jen proklik na vnitřní obrazovku.
 */
const payUrl = computed(() => billing.value?.pay_url ?? null)

/** Dlužná částka. Když ji server neposlal, tlačítko o ní MLČÍ. */
const amountDueText = computed(() => {
  const amount = billing.value?.amount_due
  if (amount === null || amount === undefined) return null
  const currency = billing.value?.currency
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: currency || 'CZK',
      maximumFractionDigits: 2,
    }).format(amount)
  } catch {
    return `${amount} ${currency ?? ''}`.trim()
  }
})

/** Popisek platebního tlačítka — s částkou, když ji známe. */
const payLabel = computed(() => (
  amountDueText.value === null
    ? t('instance_alert.pay_cta')
    : t('instance_alert.pay_cta_amount', { amount: amountDueText.value })
))

/**
 * Má tenhle důvod konkrétní vyprávění (co se stalo / co bude)?
 *
 * Když ano, obecné vysvětlení se na mobilu schová — konkrétní fakta jsou
 * užitečnější než věta, kterou uživatel zná z minula. Když ne, obecný text
 * je jediné, co má, a zůstává i na mobilu.
 */
function hasNarrative(reason: string): boolean {
  return reason === 'unpaid' && !!happenedText.value
}

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
      <!-- ⚠️ Na mobilu jen KONKRÉTNÍ fakta, obecné vysvětlení až na širším
           displeji. Linka sedí uvnitř připnuté lišty, takže celý odstavec
           ukousne třetinu obrazovky a odsune aplikaci pod okraj — a zrovna
           obecná věta je ta část, kterou uživatel při druhém zobrazení
           přeskakuje. Co se stalo a kdy to zhasne, zůstává vždycky.
           Celé znění je na obrazovce, kam vede tlačítko vedle. -->
      <span class="min-w-0 flex-1 text-white/90 line-clamp-3 sm:line-clamp-none">
        <span :class="hasNarrative(reason) ? 'hidden sm:inline' : ''">
          {{ reason === 'unpaid' ? t(unpaidDescKey) : t(`instance_alert.${reason}_desc`) }}
        </span>
        <!-- Co se stalo a co bude — jen u neuhrazení a jen když to server řekl.
             Bez dat se tu nesmí objevit žádný termín. -->
        <template v-if="hasNarrative(reason)">
          <span class="sm:ml-1">{{ happenedText }}</span>
          <span class="ml-1">{{ nextText }}</span>
        </template>
      </span>
      <!-- ⚠️ U dluhu vede HLAVNÍ tlačítko rovnou na platbu, ne na obrazovku,
           odkud se teprve odchází na web. Doplatit z aplikace byla dosud cesta
           přes tři prokliky bez jediné částky; kdo se v ní ztratil, nezaplatil.
           Proklik na vnitřní rekapitulaci zůstává vedle jako sekundární. -->
      <a
        v-if="reason === 'unpaid' && payUrl"
        :href="payUrl"
        target="_blank"
        rel="noopener"
        class="shrink-0 whitespace-nowrap rounded-md bg-white px-3 py-1 font-semibold text-danger-700 hover:bg-white/90"
        data-instance-critical-pay
      >
        {{ payLabel }}
      </a>
      <RouterLink
        :to="TARGET[reason]"
        class="shrink-0 whitespace-nowrap rounded-md bg-white/15 px-3 py-1 font-medium underline underline-offset-2 hover:bg-white/25"
      >
        {{ reason === 'unpaid' && payUrl ? t('instance_alert.unpaid_detail') : t(`instance_alert.${reason}_cta`) }}
      </RouterLink>
    </div>
  </div>
</template>
