<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import JournalEntryNotes from '@/components/accounting/JournalEntryNotes.vue'
import type { JournalNote } from '@/api/accounting'

/**
 * Poznámka k bankovnímu pohybu = poznámka jeho zápisu v deníku. Vlastní úložiště
 * pohyb nemá: dialog vkládá tutéž komponentu jako rozbalený zápis v deníku, takže
 * co se napíše tady, je vidět tam a naopak.
 */
defineProps<{ entryId: number; documentNo?: string | null }>()
const emit = defineEmits<{ close: []; changed: [notes: JournalNote[]] }>()
const { t } = useI18n()
</script>

<template>
  <Modal :title="t('bank.note.title')" width-class="max-w-xl" @close="emit('close')">
    <p class="mb-3 text-xs text-neutral-500">
      {{ t('bank.note.hint', { entry: documentNo || `#${entryId}` }) }}
    </p>
    <JournalEntryNotes :entry-id="entryId" default-open @changed="n => emit('changed', n)" />
  </Modal>
</template>
