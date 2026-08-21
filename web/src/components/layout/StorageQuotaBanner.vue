<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { formatQuotaBytes, storageQuota } from '@/api/storageQuota'
import { instanceStatus } from '@/api/instanceStatus'
import { resolveStorageLevel, STORAGE_NOTICE_PERCENT } from '@/api/instanceHealth'

/**
 * Upozornění na docházející / vyčerpaný diskový prostor instalace (H-10).
 *
 * Sedí ve stejném pruhu jako demo a licenční banner, protože jde o totéž:
 * stav celé instalace, který se týká každé stránky. Admin se o blížícím se
 * zámku musí dozvědět DŘÍV, než mu přestane jít uložit doklad.
 *
 * ── Tři úrovně, každá z jiného zdroje ─────────────────────────────────────
 *
 *  - **od 80 %** (`notice`) — nebloková připomínka. Backend ji nemá odkud
 *    poslat: hlavičky vznikají až od prahu, který se vynucuje, a 80 % se
 *    nevynucuje. Bere se proto ze staženého stavu instalace, který má jen
 *    admin — a místo stejně dokoupí jenom on.
 *  - **od `warn_percent`** (90 %) — důraznější, ale pořád nebloková. Chodí
 *    v hlavičkách každé odpovědi, takže ji vidí každý.
 *  - **od 100 %** — zápisy jsou odmítané. Tady je to jen doplněk; hlavní slovo
 *    má červená linka `InstanceCriticalBar`, kterou nejde odkliknout.
 *
 * ⚠️ Když je spotřeba NEZMĚŘENÁ, backend hlavičky neposílá a poměr se nedá
 * spočítat — pak se neukazuje nic. Nezměřená instance se nesmí tvářit jako
 * v pořádku ani jako plná.
 */
const { t } = useI18n()

const headerState = storageQuota.state
const percent = storageQuota.percent

/**
 * Úroveň k vykreslení. Hlavička má přednost: je to poslední známý stav
 * z reálného provozu, zatímco stažený status je momentka stará až celou
 * session.
 */
const level = computed<'notice' | 'warning' | 'exhausted' | null>(() => {
  if (headerState.value === 'exhausted') return 'exhausted'
  if (headerState.value === 'warning') return 'warning'

  const fromStatus = resolveStorageLevel(instanceStatus.storage.value)

  return fromStatus === 'notice' ? 'notice' : null
})

/** Procenta z hlavičky; u `notice` je zdrojem stažený stav. */
const shownPercent = computed(() => {
  const value = level.value === 'notice' ? (instanceStatus.storage.value?.percent ?? null) : percent.value

  return value === null ? null : value.toLocaleString(undefined, { maximumFractionDigits: 1 })
})

const used = computed(() => formatQuotaBytes(
  level.value === 'notice' ? (instanceStatus.storage.value?.usage_bytes ?? null) : storageQuota.usedBytes.value,
))
const limit = computed(() => formatQuotaBytes(
  level.value === 'notice' ? (instanceStatus.storage.value?.quota_bytes ?? null) : storageQuota.limitBytes.value,
))

const text = computed(() => {
  if (level.value === 'exhausted') return t('common.storage_quota.exhausted')
  if (level.value === 'notice') {
    return shownPercent.value === null
      ? t('common.storage_quota.notice_no_percent', { threshold: STORAGE_NOTICE_PERCENT })
      : t('common.storage_quota.notice', { percent: shownPercent.value })
  }
  if (level.value === 'warning') {
    return shownPercent.value === null
      ? t('common.storage_quota.warning_no_percent')
      : t('common.storage_quota.warning', { percent: shownPercent.value })
  }
  return ''
})

const detail = computed(() =>
  used.value !== null && limit.value !== null
    ? t('common.storage_quota.usage_detail', { used: used.value, limit: limit.value })
    : null,
)

const tone = computed(() => {
  if (level.value === 'exhausted') return 'border-danger-300 bg-danger-50 text-danger-700'
  if (level.value === 'warning') return 'border-warning-300 bg-warning-50 text-warning-800'

  return 'border-primary-300 bg-primary-50/60 text-primary-800'
})
</script>

<template>
  <div
    v-if="level"
    class="mb-5 rounded-lg border px-4 py-3 text-sm flex flex-wrap items-center justify-between gap-2"
    :class="tone"
    :data-storage-quota-level="level"
    data-storage-quota-banner
  >
    <span class="min-w-0">
      <span class="font-medium">{{ text }}</span>
      <span v-if="detail" class="ml-1 opacity-80 whitespace-nowrap">{{ detail }}</span>
    </span>
    <span class="flex flex-wrap items-center gap-2">
      <span class="text-xs opacity-90">{{ t('common.storage_quota.hint') }}</span>
      <!-- Výzva musí vést tam, kde se to dá vyřešit; „objednejte si víc" bez
           odkazu je jen konstatování. -->
      <RouterLink
        to="/hosting#misto"
        class="whitespace-nowrap rounded-md border border-current/40 px-2.5 py-1 text-xs font-medium hover:bg-black/5"
      >
        {{ t('common.storage_quota.expand_cta') }}
      </RouterLink>
    </span>
  </div>
</template>
