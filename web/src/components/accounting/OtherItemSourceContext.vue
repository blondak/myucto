<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { accountingApi, type JournalSourceSummary } from '@/api/accounting'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'

const props = defineProps<{ entryId: number }>()
const { t } = useI18n()
const auth = useAuthStore()
const summary = ref<JournalSourceSummary | null>(null)
const documentCount = ref(0)
const loaded = ref(false)

watch(() => props.entryId, async id => {
  summary.value = null
  documentCount.value = 0
  loaded.value = false
  try {
    summary.value = await accountingApi.getJournalSource(id)
  } catch { /* zdrojový kontext je doplněk k účetnímu zápisu */ }
  finally { loaded.value = true }
}, { immediate: true })

const note = computed(() => {
  if (!summary.value?.available || summary.value.source_type !== 'other_item') return ''
  const value = summary.value.fields.find(field => field.key === 'note')?.value
  return typeof value === 'string' ? value : ''
})
</script>

<template>
  <section v-if="summary?.available && summary.source_type === 'other_item'"
           v-show="!loaded || note || documentCount"
           class="mt-4 rounded-lg border border-neutral-200 bg-surface p-4">
    <h3 class="text-sm font-semibold">{{ t('accounting.journal.source_drawer.source_context') }}</h3>
    <div v-if="note" class="mt-3 text-sm">
      <div class="text-neutral-500">{{ t('accounting.journal.source_drawer.field.other_item_note') }}</div>
      <p class="mt-1 whitespace-pre-wrap break-words text-neutral-800">{{ note }}</p>
    </div>
    <LinkedDocumentsPanel v-if="summary.source_id && auth.canRead('other_items') && auth.canRead('documents')"
      :key="summary.source_id" entity-type="other_item" :entity-id="summary.source_id"
      readonly collapsible :title="t('accounting.journal.source_drawer.source_documents')"
      @count="n => documentCount = n" />
  </section>
</template>
