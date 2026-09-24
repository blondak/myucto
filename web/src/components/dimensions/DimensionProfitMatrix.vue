<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DimensionProfitMatrix } from '@/api/dimensions'
import { formatMoney } from '@/composables/useFormat'

/**
 * Výsledovka po dimenzi v rozpadu po účtech: řádky = syntetické účty (výnosy, pak
 * náklady), sloupce = kořeny sestavy (hodnoty nejvyšší úrovně, větev nebo hodnoty
 * odpovědné osoby) a případně „bez hodnoty". Poslední řádek je výsledek sloupce.
 */
const props = defineProps<{
  matrix: DimensionProfitMatrix
}>()

const { t } = useI18n()

const sections = computed(() => {
  const revenue = props.matrix.rows.filter(r => r.account_type === 'revenue')
  const expense = props.matrix.rows.filter(r => r.account_type !== 'revenue')
  return [
    { key: 'revenue', label: t('dimensions.profit_revenue'), rows: revenue },
    { key: 'expense', label: t('dimensions.profit_cost'), rows: expense },
  ].filter(s => s.rows.length > 0)
})

function columnLabel(col: DimensionProfitMatrix['columns'][number]): string {
  return col.value_id === null ? t('dimensions.profit_unassigned') : `${col.code} ${col.name ?? ''}`.trim()
}

function money(v: number) {
  return formatMoney(v, 'CZK')
}
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto" data-test="profit-matrix">
    <table class="w-full text-sm">
      <thead class="bg-neutral-50 text-neutral-600">
        <tr>
          <th class="px-3 py-3 text-left font-medium sticky left-0 bg-neutral-50">{{ t('dimensions.matrix_account') }}</th>
          <th v-for="col in matrix.columns" :key="col.key" class="px-3 py-3 text-right font-medium whitespace-nowrap"
              :class="{ 'italic text-neutral-500': col.value_id === null }">{{ columnLabel(col) }}</th>
          <th class="px-3 py-3 text-right font-medium whitespace-nowrap">{{ t('dimensions.profit_total') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-neutral-100">
        <template v-for="section in sections" :key="section.key">
          <tr class="bg-neutral-50/60">
            <td :colspan="matrix.columns.length + 2" class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ section.label }}</td>
          </tr>
          <tr v-for="row in section.rows" :key="row.code" data-test="matrix-row">
            <td class="px-3 py-1.5 sticky left-0 bg-surface">
              <span class="font-mono text-xs text-neutral-500 mr-1">{{ row.code }}</span>
              <span>{{ row.name }}</span>
            </td>
            <td v-for="(cell, i) in row.cells" :key="i" class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap"
                :class="{ 'text-neutral-400': cell === 0 }">{{ money(cell) }}</td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap font-medium">{{ money(row.total) }}</td>
          </tr>
        </template>
      </tbody>
      <tfoot class="bg-neutral-50 font-semibold">
        <tr data-test="matrix-results">
          <td class="px-3 py-3 sticky left-0 bg-neutral-50">{{ t('dimensions.profit_result') }}</td>
          <td v-for="(result, i) in matrix.results" :key="i" class="px-3 py-3 text-right tabular-nums whitespace-nowrap"
              :class="{ 'text-danger-600': result < 0 }">{{ money(result) }}</td>
          <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap" :class="{ 'text-danger-600': matrix.total_result < 0 }">{{ money(matrix.total_result) }}</td>
        </tr>
      </tfoot>
    </table>
  </div>
</template>
