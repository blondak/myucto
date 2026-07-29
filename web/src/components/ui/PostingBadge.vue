<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatDate } from '@/composables/useFormat'

/** Kompaktní odkaz na konkrétní účetní zápis dokladu. */
const props = defineProps<{
  bookedAt: string | null | undefined
  journalEntryId: number
  /** Vykreslit vedle ikony i text „Zaúčtováno" (default false = ikona-only). */
  withLabel?: boolean
}>()

const { t } = useI18n()
</script>

<template>
  <RouterLink
    :to="{ name: 'accounting-journal', query: { entry_id: String(props.journalEntryId) } }"
    class="inline-flex items-center justify-center rounded text-success-600 hover:bg-success-50 hover:text-success-700 transition-colors"
    :class="props.withLabel ? 'gap-1 h-5 px-1.5 text-xs font-medium' : 'w-5 h-5'"
    :title="props.bookedAt ? `${t('common.booked_badge')} · ${formatDate(props.bookedAt)}` : t('common.booked_badge')"
    :aria-label="t('common.booked_badge')"
    @click.stop
  >
    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" />
    </svg>
    <span v-if="props.withLabel">{{ t('common.booked_badge') }}</span>
  </RouterLink>
</template>
