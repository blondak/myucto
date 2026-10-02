<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Odznak stavu schvalování dokladu. Pro `none` (nebo chybějící hodnotu) se nevykreslí
 * nic, takže u firem bez schvalování seznam ani detail nevypadají jinak než dřív.
 */
const props = defineProps<{
  status?: string | null
  /** Menší varianta pro husté řádky tabulky. */
  small?: boolean
}>()

const { t } = useI18n()

const TONES: Record<string, string> = {
  pending: 'bg-warning-50 text-warning-600 border border-warning-500/40',
  approved: 'bg-success-50 text-success-600 border border-success-500/40',
  rejected: 'bg-danger-50 text-danger-500 border border-danger-500/40',
  cancelled: 'bg-neutral-100 text-neutral-600 border border-neutral-200',
}

const ICON_PATH: Record<string, string> = {
  pending: 'M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  approved: 'M5 13l4 4L19 7',
  rejected: 'M6 18L18 6M6 6l12 12',
  cancelled: 'M18.364 5.636l-12.728 12.728M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
}

const visible = computed(() => !!props.status && props.status !== 'none' && props.status in TONES)
</script>

<template>
  <span v-if="visible"
        class="inline-flex items-center gap-1 rounded whitespace-nowrap font-normal"
        :class="[TONES[status ?? ''], small ? 'text-[10px] px-1.5 py-0.5' : 'text-xs px-2 py-0.5']"
        :data-approval-status="status">
    <svg class="shrink-0" :class="small ? 'w-2.5 h-2.5' : 'w-3 h-3'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="ICON_PATH[status ?? '']" />
    </svg>
    {{ t(`purchase_approval.status.${status}`) }}
  </span>
</template>
