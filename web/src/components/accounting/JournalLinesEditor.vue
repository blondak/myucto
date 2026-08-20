<script setup lang="ts">
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import type { ChartAccount } from '@/api/accounting'

/**
 * Editor řádků účetního zápisu — MD/D, účet z osnovy, částka, popis.
 *
 * Vznikl proto, že tutéž tabulku potřebují dvě různá místa: návrh doúčtování z nálezu
 * kontroly a zaúčtování dokladu. Dokud existovala jen v bankovním modalu jako „split
 * mode", každé další místo si ji opisovalo — a s ní i kontrolu vyváženosti, která se
 * v kopii dřív nebo později rozejde. Zápis, u kterého se MD ≠ D, není účetnictví.
 *
 * Sám nic neukládá ani neposílá: drží jen řádky a říká, jestli jsou vyvážené.
 */

export interface EditorLine {
  account_code: string
  side: 'debit' | 'credit'
  amount: number | null
  description?: string | null
}

const props = withDefaults(defineProps<{
  modelValue: EditorLine[]
  accounts: ChartAccount[]
  /** Kolik řádků musí zůstat — pod tuhle hranici mazání nepustí. */
  minLines?: number
  /** Popisky řádků (rozúčtování na střediska apod.); u krátkých zápisů jen šum. */
  withDescription?: boolean
  disabled?: boolean
  /** Vlastní id datalistu — dvě instance na stránce si jinak přepíšou nabídku. */
  listId?: string
}>(), {
  minLines: 2,
  withDescription: false,
  disabled: false,
  listId: 'jle-coa',
})

const emit = defineEmits<{ 'update:modelValue': [EditorLine[]] }>()

const { t } = useI18n()
const accountListId = `journal-lines-${useId()}-${props.listId}`

const activeAccounts = computed(() =>
  props.accounts.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)))

const accountByCode = computed<Record<string, ChartAccount>>(() => {
  const m: Record<string, ChartAccount> = {}
  for (const a of props.accounts) m[a.account_code] = a
  return m
})

function accountName(code: string): string {
  return accountByCode.value[code]?.name ?? ''
}

function update(lines: EditorLine[]) {
  emit('update:modelValue', lines)
}

function addLine() {
  update([...props.modelValue, { account_code: '', side: 'debit', amount: null }])
}

function removeLine(i: number) {
  if (props.modelValue.length <= props.minLines) return
  const next = [...props.modelValue]
  next.splice(i, 1)
  update(next)
}

const debitSum = computed(() =>
  props.modelValue.filter(l => l.side === 'debit').reduce((s, l) => s + (l.amount ?? 0), 0))
const creditSum = computed(() =>
  props.modelValue.filter(l => l.side === 'credit').reduce((s, l) => s + (l.amount ?? 0), 0))

/** Zaokrouhlení na haléře — bez něj hlásí 0.1 + 0.2 ≠ 0.3 nevyvážený zápis. */
const diff = computed(() => Math.round((debitSum.value - creditSum.value) * 100) / 100)

const balanced = computed(() => diff.value === 0 && props.modelValue.length > 0)
const complete = computed(() => props.modelValue.every(
  l => !!accountByCode.value[l.account_code] && (l.amount ?? 0) > 0))

defineExpose({ balanced, complete, valid: computed(() => balanced.value && complete.value) })
</script>

<template>
  <div class="space-y-1.5">
    <div v-for="(l, i) in modelValue" :key="i" class="flex items-start gap-1.5">
      <select v-model="l.side" :disabled="disabled"
        class="h-10 px-2 border border-neutral-300 rounded-md text-xs shrink-0">
        <option value="debit">{{ t('accounting.lines_editor.debit') }}</option>
        <option value="credit">{{ t('accounting.lines_editor.credit') }}</option>
      </select>

      <div class="flex-1 min-w-0">
        <input v-model="l.account_code" :list="accountListId" :disabled="disabled" type="text"
          :placeholder="t('accounting.lines_editor.account')"
          class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
        <div v-if="l.account_code && accountByCode[l.account_code]" class="text-xs text-neutral-500 mt-0.5 truncate">
          {{ accountName(l.account_code) }}
        </div>
        <div v-else-if="l.account_code" class="text-xs text-danger-500 mt-0.5">
          {{ t('accounting.lines_editor.account_not_found') }}
        </div>
      </div>

      <input v-if="withDescription" v-model="l.description" :disabled="disabled" type="text"
        :placeholder="t('accounting.lines_editor.line_description')"
        class="w-40 h-10 px-2 border border-neutral-300 rounded-md text-sm shrink-0" />

      <input v-model.number="l.amount" :disabled="disabled" type="number" step="0.01" min="0"
        class="w-28 h-10 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right shrink-0" />

      <button type="button" class="h-10 px-2 text-neutral-400 hover:text-danger-500 shrink-0"
        :disabled="disabled || modelValue.length <= minLines" @click="removeLine(i)">×</button>
    </div>

    <button v-if="!disabled" type="button" class="text-xs text-primary-600 hover:underline" @click="addLine">
      + {{ t('accounting.lines_editor.add_line') }}
    </button>

    <div class="text-xs space-y-0.5 pt-1 border-t border-neutral-100">
      <div class="flex justify-between font-mono">
        <span class="text-neutral-500">{{ t('accounting.lines_editor.sums') }}</span>
        <span>{{ formatMoney(debitSum, 'CZK') }} / {{ formatMoney(creditSum, 'CZK') }}</span>
      </div>
      <div v-if="!balanced" class="text-danger-500">
        {{ t('accounting.lines_editor.unbalanced', { diff: formatMoney(Math.abs(diff), 'CZK') }) }}
      </div>
      <div v-else-if="!complete" class="text-warning-600">
        {{ t('accounting.lines_editor.incomplete') }}
      </div>
      <div v-else class="text-success-600">{{ t('accounting.lines_editor.ok') }}</div>
    </div>

    <datalist :id="accountListId">
      <option v-for="a in activeAccounts" :key="a.id" :value="a.account_code">
        {{ a.account_code }} — {{ a.name }}
      </option>
    </datalist>
  </div>
</template>
