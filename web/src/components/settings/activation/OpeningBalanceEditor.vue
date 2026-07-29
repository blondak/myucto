<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { OpeningBalanceRow } from '@/api/activation'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'
import PaginationBar from '@/components/ui/PaginationBar.vue'

const props = defineProps<{ modelValue: OpeningBalanceRow[] }>()
const emit = defineEmits<{ 'update:modelValue': [OpeningBalanceRow[]] }>()
const { t } = useI18n()

const rows = computed({
  get: () => props.modelValue,
  set: value => emit('update:modelValue', value),
})
const debit = computed(() => rows.value.filter(r => r.side === 'debit').reduce((sum, r) => sum + Number(r.amount || 0), 0))
const credit = computed(() => rows.value.filter(r => r.side === 'credit').reduce((sum, r) => sum + Number(r.amount || 0), 0))
const balanced = computed(() => Math.abs(debit.value - credit.value) < 0.005)
const page = ref(1)
const perPage = 25
const pageOffset = computed(() => (page.value - 1) * perPage)
const pagedRows = computed(() => rows.value.slice(pageOffset.value, pageOffset.value + perPage))
watch(() => rows.value.length, length => {
  page.value = Math.min(page.value, Math.max(1, Math.ceil(length / perPage)))
})

function addRow() {
  const next = [...rows.value, { account_code: '', side: 'debit' as const, amount: 0, note: '' }]
  rows.value = next
  page.value = Math.ceil(next.length / perPage)
}
function removeRow(index: number) {
  rows.value = rows.value.filter((_, i) => i !== index)
}
const money = (value: number) => new Intl.NumberFormat('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
</script>

<template>
  <div class="space-y-3">
    <div class="overflow-x-auto rounded-lg border border-neutral-200">
      <table class="w-full min-w-[760px] text-sm">
        <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
          <tr><th class="px-3 py-2">{{ t('activation.account') }}</th><th class="px-3 py-2">{{ t('activation.side') }}</th><th class="px-3 py-2">{{ t('activation.amount') }}</th><th class="px-3 py-2">{{ t('activation.note') }}</th><th class="w-12"></th></tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="(row, index) in pagedRows" :key="pageOffset + index">
            <td class="px-3 py-2"><input v-model.trim="row.account_code" class="h-9 w-28 rounded-md border border-neutral-300 px-2 font-mono" maxlength="10" /><span v-if="row.account_name" class="ml-2 text-xs text-neutral-500">{{ row.account_name }}</span></td>
            <td class="px-3 py-2"><select v-model="row.side" class="h-9 rounded-md border border-neutral-300 px-2"><option value="debit">MD</option><option value="credit">D</option></select></td>
            <td class="px-3 py-2"><input v-model.number="row.amount" type="number" min="0.01" step="0.01" class="h-9 w-36 rounded-md border border-neutral-300 px-2 text-right font-mono" /></td>
            <td class="px-3 py-2"><input v-model.trim="row.note" class="h-9 w-full rounded-md border border-neutral-300 px-2" /></td>
            <td class="px-2 py-2"><button type="button" :class="btnOutlineSm('danger')" :title="t('common.delete')" @click="removeRow(pageOffset + index)"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg></button></td>
          </tr>
          <tr v-if="rows.length === 0"><td colspan="5" class="px-4 py-8 text-center text-neutral-400">{{ t('activation.opening_empty') }}</td></tr>
        </tbody>
      </table>
      <PaginationBar embedded :page="page" :per-page="perPage" :total="rows.length" @update:page="page = $event" />
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <button type="button" :class="btnOutlineSm('primary')" @click="addRow"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>{{ t('activation.add_row') }}</button>
      <div class="flex flex-wrap items-center gap-3 font-mono text-sm"><span>Σ MD {{ money(debit) }}</span><span>Σ D {{ money(credit) }}</span><span :class="balanced ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-600'" class="rounded-full px-2.5 py-1 font-sans text-xs font-semibold">{{ balanced ? t('activation.opening_balanced') : t('activation.opening_unbalanced') }}</span></div>
    </div>
  </div>
</template>
