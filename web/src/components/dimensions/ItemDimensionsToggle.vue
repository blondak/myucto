<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { ICONS } from '@/components/ui/buttonStyles'

/**
 * Malé tlačítko za popisem položky — rozbalí pod řádkem dimenze položky.
 * `filled` = položka má vlastní dimenze (jinak platí hlavička dokladu).
 */
defineProps<{ open: boolean; filled: boolean; disabled?: boolean }>()
defineEmits<{ toggle: [] }>()

const { t } = useI18n()
</script>

<template>
  <button type="button" :disabled="disabled"
    :title="open ? t('dimensions.item_toggle_close') : t('dimensions.item_toggle_open')"
    :aria-expanded="open"
    class="shrink-0 w-7 h-7 inline-flex items-center justify-center rounded border disabled:opacity-40"
    :class="filled
      ? 'border-primary-300 bg-primary-50 text-primary-700'
      : open ? 'border-neutral-300 bg-neutral-100 text-neutral-700' : 'border-transparent text-neutral-400 hover:text-neutral-700 hover:border-neutral-300'"
    data-test="item-dimensions-toggle"
    @click="$emit('toggle')">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.tag" /></svg>
  </button>
</template>
