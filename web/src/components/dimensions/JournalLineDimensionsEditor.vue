<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionFields from './DimensionFields.vue'
import { dimensionsApi, type DimensionMap } from '@/api/dimensions'
import type { JournalLine } from '@/api/accounting'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'

/**
 * Ruční úprava dimenzí řádků zápisu. Mění jen analytické členění (účet, strana
 * ani částka se nemění), proto jde i u zaúčtovaného zápisu v uzavřeném období.
 * U řádků převzatých z dokladu platí doklad — další úprava dokladu je přerazítkuje.
 */
const props = defineProps<{
  entryId: number
  lines: JournalLine[]
}>()

const emit = defineEmits<{ saved: [dims: Record<number, Record<number, number>>] }>()

const { t } = useI18n()
const toast = useToast()
const dims = useDimensions()

const editing = ref(false)
const saving = ref(false)
const draft = ref<Record<number, DimensionMap>>({})

function start() {
  draft.value = Object.fromEntries(props.lines.map(l => [l.id, { ...(l.dimensions ?? {}) }]))
  editing.value = true
  void dims.load()
}

async function save() {
  saving.value = true
  try {
    const result = await dimensionsApi.saveJournal(props.entryId, draft.value)
    toast.success(t('dimensions.lines_saved'))
    editing.value = false
    emit('saved', result.lines)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-if="dims.enabled.value && dims.documentTypes.value.length > 0" data-test="journal-line-dimensions">
    <button v-if="!editing && dims.canEdit.value" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" @click="start">
      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.tag" /></svg>
      {{ t('dimensions.edit_lines') }}
    </button>
    <div v-if="editing" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 space-y-3">
      <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('dimensions.line_title') }}</h4>
      <div v-for="line in lines" :key="line.id" class="space-y-1">
        <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
          <span class="font-mono font-medium">{{ line.account_code }}</span>
          <span class="text-neutral-600 truncate">{{ line.account_name }}</span>
          <span class="text-xs text-neutral-500">{{ line.side === 'debit' ? t('accounting.journal.side.debit') : t('accounting.journal.side.credit') }}</span>
          <span class="ml-auto font-mono text-sm">{{ formatMoney(line.amount) }}</span>
        </div>
        <DimensionFields v-model="draft[line.id]" compact />
      </div>
      <div class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-3">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="editing = false">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" :disabled="saving" :class="btnFilled('primary')" class="whitespace-nowrap" @click="save">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('dimensions.save') }}
        </button>
      </div>
    </div>
  </div>
</template>
