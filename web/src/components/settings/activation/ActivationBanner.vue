<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useActivationStatus } from '@/composables/useActivationStatus'

const { t } = useI18n()
const activation = useActivationStatus()
onMounted(() => { void activation.refresh().catch(() => undefined) })
</script>

<template>
  <div v-if="activation.showBanner.value" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
    <span class="inline-flex items-center gap-2">
      <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" /></svg>
      {{ t('activation.banner') }}
    </span>
    <RouterLink :to="{ name: 'accounting-activation' }" class="font-semibold text-warning-700 underline underline-offset-2 whitespace-nowrap">
      {{ t('activation.banner_cta') }}
    </RouterLink>
  </div>
</template>
