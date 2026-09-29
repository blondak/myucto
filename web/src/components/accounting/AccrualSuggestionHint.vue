<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatDate } from '@/composables/useFormat'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

/**
 * Návrh časového rozlišení u položky dokladu: období rozpoznané z textu položky
 * (`detectAccrualPeriod`). Komponenta nic nemění, jen emituje `apply`/`dismiss`;
 * zápis na řádek dělá rodič na klik uživatele.
 */
defineProps<{ from: string; to: string }>()
defineEmits<{ apply: []; dismiss: [] }>()

const { t } = useI18n()
</script>

<template>
  <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 rounded border border-primary-200 bg-primary-50/60 px-2 py-1.5 text-xs"
    data-test="accrual-suggestion">
    <span class="text-neutral-700">{{ t('accrual_suggest.detected', { from: formatDate(from), to: formatDate(to) }) }}</span>
    <span class="flex flex-wrap gap-1.5">
      <button type="button" :class="btnFilledSm('success')" data-test="accrual-suggestion-apply" @click="$emit('apply')">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.calendar" /></svg>
        {{ t('accrual_suggest.apply') }}
      </button>
      <button type="button" :class="btnOutlineSm('neutral')" data-test="accrual-suggestion-dismiss" @click="$emit('dismiss')">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        {{ t('accrual_suggest.dismiss') }}
      </button>
    </span>
  </div>
</template>
