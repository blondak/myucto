<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { accountingApi, type JournalNote } from '@/api/accounting'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'

const props = defineProps<{ entryId: number }>()
const { t } = useI18n()
const auth = useAuthStore()
const notes = ref<JournalNote[]>([])
const documentCount = ref(0)
const loaded = ref(false)

watch(() => props.entryId, async id => {
  notes.value = []
  documentCount.value = 0
  loaded.value = false
  if (auth.canRead('accounting')) {
    try { notes.value = await accountingApi.listJournalNotes(id) }
    catch { /* zápis lze otevřít samostatně v deníku */ }
  }
  loaded.value = true
}, { immediate: true })
</script>

<template>
  <section v-if="auth.canRead('accounting')" v-show="!loaded || notes.length || documentCount"
           class="mt-4 rounded-lg border border-neutral-200 bg-surface p-4">
    <h3 class="text-sm font-semibold">{{ t('other_items.journal_context') }}</h3>
    <div v-if="notes.length" class="mt-3">
      <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('accounting.journal.notes.title') }}</h4>
      <ul class="mt-2 space-y-2">
        <li v-for="note in notes" :key="note.id" class="whitespace-pre-wrap break-words text-sm text-neutral-800">
          {{ note.body }}
        </li>
      </ul>
    </div>
    <LinkedDocumentsPanel v-if="auth.canRead('documents')" :key="entryId"
      entity-type="journal_entry" :entity-id="entryId" readonly collapsible
      :title="t('other_items.journal_documents')" @count="n => documentCount = n" />
  </section>
</template>
