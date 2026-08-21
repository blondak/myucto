<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { storageQuota } from '@/api/storageQuota'
import { resolveInstanceAlerts, type InstanceAlertReason } from '@/api/instanceAlert'
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
 */
const { t } = useI18n()
const auth = useAuthStore()

const reasons = computed<InstanceAlertReason[]>(() => resolveInstanceAlerts({
  managed: auth.isManagedInstallation,
  storageExhausted: storageQuota.isCriticallyExhausted.value,
  licenseState: auth.license?.state ?? null,
}))

const visible = computed(() => reasons.value.length > 0)

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
      <span class="font-semibold whitespace-nowrap">{{ t(`instance_alert.${reason}_title`) }}</span>
      <span class="min-w-0 flex-1 text-white/90">{{ t(`instance_alert.${reason}_desc`) }}</span>
      <RouterLink
        :to="TARGET[reason]"
        class="shrink-0 whitespace-nowrap rounded-md bg-white/15 px-3 py-1 font-medium underline underline-offset-2 hover:bg-white/25"
      >
        {{ t(`instance_alert.${reason}_cta`) }}
      </RouterLink>
    </div>
  </div>
</template>
