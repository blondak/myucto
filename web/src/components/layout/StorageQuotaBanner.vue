<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatQuotaBytes, storageQuota } from '@/api/storageQuota'

/**
 * Upozornění na docházející / vyčerpaný diskový prostor instalace (H-10).
 *
 * Sedí ve stejném pruhu jako demo a licenční banner, protože jde o totéž:
 * stav celé instalace, který se týká každé stránky. Admin se o blížícím se
 * zámku musí dozvědět DŘÍV, než mu přestane jít uložit doklad — proto varování
 * na 90 %, ne až odmítnutý zápis.
 *
 * ⚠️ Stav se bere z hlaviček odpovědí (viz `@/api/storageQuota`). Když je
 * spotřeba NEZMĚŘENÁ, backend hlavičky neposílá a banner se nezobrazí —
 * nezměřená instance se nesmí tvářit jako v pořádku ani jako plná.
 */
const { t } = useI18n()

const state = storageQuota.state
const percent = storageQuota.percent

const shownPercent = computed(() =>
  percent.value === null ? null : percent.value.toLocaleString(undefined, { maximumFractionDigits: 1 }),
)
const used = computed(() => formatQuotaBytes(storageQuota.usedBytes.value))
const limit = computed(() => formatQuotaBytes(storageQuota.limitBytes.value))

const text = computed(() => {
  if (state.value === 'exhausted') return t('common.storage_quota.exhausted')
  if (state.value === 'warning') {
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
</script>

<template>
  <div
    v-if="state"
    class="mb-5 rounded-lg border px-4 py-3 text-sm flex flex-wrap items-center justify-between gap-2"
    :class="state === 'exhausted'
      ? 'border-danger-300 bg-danger-50 text-danger-700'
      : 'border-warning-300 bg-warning-50 text-warning-800'"
    data-storage-quota-banner
  >
    <span class="min-w-0">
      <span class="font-medium">{{ text }}</span>
      <span v-if="detail" class="ml-1 opacity-80 whitespace-nowrap">{{ detail }}</span>
    </span>
    <span class="text-xs opacity-90">{{ t('common.storage_quota.hint') }}</span>
  </div>
</template>
