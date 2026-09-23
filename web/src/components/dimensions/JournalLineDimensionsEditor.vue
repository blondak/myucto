<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionFields from './DimensionFields.vue'
import DimensionChips from './DimensionChips.vue'
import DimensionSplitEditor from './DimensionSplitEditor.vue'
import { dimensionsApi, type DimensionMap, type DimensionSplitShare, type DimensionSplits } from '@/api/dimensions'
import type { JournalLine } from '@/api/accounting'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'

/**
 * Ruční úprava dimenzí řádků zápisu. Mění jen analytické členění (účet, strana
 * ani částka se nemění), proto jde i u zaúčtovaného zápisu v uzavřeném období.
 * U řádků převzatých z dokladu platí doklad — další úprava dokladu je přerazítkuje.
 *
 * Řádek jde rozdělit mezi víc hodnot jednoho typu (procentem nebo částkou); typ
 * s rozpadem pak nemá jedinou hodnotu. Řádek deníku se tím nedělí.
 */
const props = defineProps<{
  entryId: number
  lines: JournalLine[]
}>()

const emit = defineEmits<{
  saved: [dims: Record<number, Record<number, number>>, splits: Record<number, DimensionSplits>]
}>()

const { t } = useI18n()
const toast = useToast()
const dims = useDimensions()

const editing = ref(false)
const saving = ref(false)
const draft = ref<Record<number, DimensionMap>>({})
const splitDraft = ref<Record<number, DimensionSplits>>({})
const splitLine = ref<JournalLine | null>(null)

const splitTypes = computed(() => dims.documentTypes.value)

function start() {
  draft.value = Object.fromEntries(props.lines.map(l => [l.id, { ...(l.dimensions ?? {}) }]))
  splitDraft.value = Object.fromEntries(props.lines.map(l => [l.id, { ...(l.dimension_splits ?? {}) }]))
  editing.value = true
  void dims.load()
}

/** Typy s rozpadem se ve výběru jediné hodnoty nenabízí. */
function singleTypes(lineId: number) {
  const split = splitDraft.value[lineId] ?? {}
  return dims.documentTypes.value.filter(ty => !(split[ty.id]?.length))
}

function applySplit(typeId: number, shares: DimensionSplitShare[] | null) {
  const line = splitLine.value
  if (!line) return
  const current = { ...(splitDraft.value[line.id] ?? {}) }
  if (shares === null) {
    delete current[typeId]
  } else {
    current[typeId] = shares
    draft.value[line.id] = { ...(draft.value[line.id] ?? {}), [typeId]: null }
  }
  splitDraft.value = { ...splitDraft.value, [line.id]: current }
  splitLine.value = null
}

async function save() {
  saving.value = true
  try {
    const result = await dimensionsApi.saveJournal(props.entryId, draft.value, splitDraft.value)
    toast.success(t('dimensions.lines_saved'))
    editing.value = false
    emit('saved', result.lines, result.splits ?? {})
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
        <div class="flex flex-wrap items-start gap-2">
          <DimensionFields v-model="draft[line.id]" :types="singleTypes(line.id)" compact class="flex-1" />
          <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" data-test="line-split" @click="splitLine = line">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
            {{ t('dimensions.split.open') }}
          </button>
        </div>
        <DimensionChips :dimensions="{}" :splits="splitDraft[line.id]" class="flex" />
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
    <DimensionSplitEditor
      v-if="splitLine"
      :types="splitTypes"
      :current="splitDraft[splitLine.id]"
      :base="splitLine.amount"
      :title="t('dimensions.split.title_line', { account: splitLine.account_code ?? '' })"
      @close="splitLine = null"
      @save="applySplit"
    />
  </div>
</template>
