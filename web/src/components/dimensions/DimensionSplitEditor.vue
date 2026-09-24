<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionPicker from './DimensionPicker.vue'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm, btnIconSm } from '@/components/ui/buttonStyles'
import { useDimensions } from '@/composables/useDimensions'
import { formatMoney } from '@/composables/useFormat'
import type { DimensionSplitShare, DimensionType } from '@/api/dimensions'

/**
 * Rozpad řádku (nebo dokladu) mezi víc hodnot jednoho typu dimenze — procentem,
 * nebo částkou, je-li známý základ. Ukládá se podíl 0–1; součet musí dát 100 %
 * (u částek základ řádku). `null` z události `save` = rozpad typu zrušit.
 */
const props = withDefaults(defineProps<{
  types: DimensionType[]
  /** Typ, který se má otevřít (jinak první nabízený). */
  typeId?: number | null
  /** Dosavadní rozpad podle typu. */
  current?: Record<number, DimensionSplitShare[]>
  /** Základ pro zadání částkou (částka řádku); bez něj jen procenta. */
  base?: number | null
  title?: string
}>(), { typeId: null, current: () => ({}), base: null, title: undefined })

const emit = defineEmits<{
  close: []
  save: [typeId: number, shares: DimensionSplitShare[] | null]
}>()

const { t } = useI18n()
const dims = useDimensions()

interface Row { value_id: number | null; percent: number | null; amount: number | null }

const selectedType = ref<number>(props.typeId ?? props.types[0]?.id ?? 0)
const mode = ref<'percent' | 'amount'>('percent')
const rows = ref<Row[]>([])
const canAmount = computed(() => (props.base ?? 0) > 0)

function round(n: number, digits: number): number {
  const f = 10 ** digits
  return Math.round(n * f) / f
}

function loadRows() {
  const existing = props.current?.[selectedType.value] ?? []
  const base = props.base ?? 0
  rows.value = existing.length > 0
    ? existing.map(s => ({ value_id: s.value_id, percent: round(s.share * 100, 4), amount: base > 0 ? round(s.share * base, 2) : null }))
    : [{ value_id: null, percent: null, amount: null }, { value_id: null, percent: null, amount: null }]
}
loadRows()
watch(selectedType, loadRows)

const total = computed(() => rows.value.reduce((sum, r) => sum + ((mode.value === 'percent' ? r.percent : r.amount) ?? 0), 0))
const target = computed(() => (mode.value === 'percent' ? 100 : (props.base ?? 0)))
const remaining = computed(() => round(target.value - total.value, mode.value === 'percent' ? 4 : 2))
const filled = computed(() => rows.value.filter(r => r.value_id && ((mode.value === 'percent' ? r.percent : r.amount) ?? 0) > 0))
const duplicate = computed(() => new Set(filled.value.map(r => r.value_id)).size !== filled.value.length)
const valid = computed(() => filled.value.length >= 2 && !duplicate.value
  && Math.abs(remaining.value) < (mode.value === 'percent' ? 0.0001 : 0.005))
const hasExisting = computed(() => (props.current?.[selectedType.value]?.length ?? 0) > 0)

function addRow() {
  rows.value.push({ value_id: null, percent: remaining.value > 0 && mode.value === 'percent' ? remaining.value : null, amount: remaining.value > 0 && mode.value === 'amount' ? remaining.value : null })
}

function removeRow(i: number) {
  rows.value.splice(i, 1)
}

function save() {
  if (!valid.value) return
  const base = props.base ?? 0
  const shares = filled.value.map(r => ({
    value_id: r.value_id as number,
    share: round(mode.value === 'percent' ? (r.percent as number) / 100 : (r.amount as number) / base, 10),
  }))
  emit('save', selectedType.value, shares)
}

function unit(): string {
  return mode.value === 'percent' ? '%' : ''
}

const typeName = computed(() => dims.typeById.value.get(selectedType.value)?.name ?? '')
</script>

<template>
  <Modal :title="title ?? t('dimensions.split.title')" width-class="max-w-xl" @close="emit('close')">
    <div class="space-y-4" data-test="dimension-split-editor">
      <p class="text-sm text-neutral-600">{{ t('dimensions.split.hint') }}</p>
      <div class="flex flex-wrap items-end gap-3">
        <label class="block min-w-[12rem] flex-1">
          <span class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.split.type') }}</span>
          <select v-model.number="selectedType" class="w-full rounded-md border border-neutral-300 px-2 py-1.5 text-sm" data-test="split-type">
            <option v-for="ty in types" :key="ty.id" :value="ty.id">{{ ty.name }}</option>
          </select>
        </label>
        <div v-if="canAmount" class="flex flex-wrap gap-1" role="group">
          <button type="button" :class="mode === 'percent' ? btnFilled('primary') : btnOutline('neutral')" class="whitespace-nowrap" @click="mode = 'percent'">
            {{ t('dimensions.split.by_percent') }}
          </button>
          <button type="button" :class="mode === 'amount' ? btnFilled('primary') : btnOutline('neutral')" class="whitespace-nowrap" data-test="split-mode-amount" @click="mode = 'amount'">
            {{ t('dimensions.split.by_amount') }}
          </button>
        </div>
      </div>
      <p v-if="canAmount" class="text-xs text-neutral-500">{{ t('dimensions.split.base', { amount: formatMoney(base ?? 0) }) }}</p>

      <div class="space-y-2">
        <div v-for="(row, i) in rows" :key="i" class="flex flex-wrap items-center gap-2" data-test="split-row">
          <div class="min-w-[12rem] flex-1">
            <DimensionPicker v-model="row.value_id" :type-id="selectedType" :placeholder="typeName" :aria-label="typeName" compact teleport />
          </div>
          <div class="flex items-center gap-1">
            <input v-if="mode === 'percent'" v-model.number="row.percent" type="number" min="0" max="100" step="0.01"
                   class="w-28 rounded-md border border-neutral-300 px-2 py-1.5 text-right text-sm" :aria-label="t('dimensions.split.percent')" data-test="split-percent" />
            <input v-else v-model.number="row.amount" type="number" min="0" step="0.01"
                   class="w-32 rounded-md border border-neutral-300 px-2 py-1.5 text-right text-sm" :aria-label="t('dimensions.split.amount')" data-test="split-amount" />
            <span class="w-4 text-sm text-neutral-500">{{ unit() }}</span>
          </div>
          <button type="button" :class="btnIconSm('danger')" :title="t('dimensions.split.remove_row')" :aria-label="t('dimensions.split.remove_row')" @click="removeRow(i)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          </button>
        </div>
        <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" data-test="split-add" @click="addRow">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('dimensions.split.add_row') }}
        </button>
      </div>

      <p class="text-sm" :class="valid ? 'text-success-700' : 'text-warning-700'" data-test="split-remaining">
        <template v-if="duplicate">{{ t('dimensions.split.duplicate') }}</template>
        <template v-else-if="filled.length < 2">{{ t('dimensions.split.need_two') }}</template>
        <template v-else-if="valid">{{ t('dimensions.split.balanced') }}</template>
        <template v-else>{{ t('dimensions.split.remaining', { amount: mode === 'percent' ? `${remaining} %` : formatMoney(remaining) }) }}</template>
      </p>
    </div>
    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button v-if="hasExisting" type="button" :class="btnOutline('danger')" class="whitespace-nowrap mr-auto" data-test="split-clear" @click="emit('save', selectedType, null)">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          {{ t('dimensions.split.clear') }}
        </button>
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" :disabled="!valid" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="split-save" @click="save">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('dimensions.split.apply') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
