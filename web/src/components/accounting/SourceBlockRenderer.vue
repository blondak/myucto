<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDate, formatMoney } from '@/composables/useFormat'
import type { SourceBlock, SourceFieldFormat } from '@/api/accounting'

/**
 * JEDNA generická komponenta pro oba tvary bloku ('table' i 'keyvalue') —
 * záměrně NE komponenta na typ dokladu, jinak by jich přibývalo s každým
 * dalším zdrojem a rozcházely by se.
 *
 * Formátování se dělá AŽ TADY: backend posílá čísla + `format`, ne hotové
 * stringy, aby přepnutí jazyka/locale nerozbilo zobrazení částek a dat.
 */
const props = defineProps<{
  block: SourceBlock
  /** Měna zápisu — fallback když blok ani řádek vlastní nemá. */
  fallbackCurrency?: string | null
}>()

const { t } = useI18n()

const blockCurrency = computed(() => props.block.currency || props.fallbackCurrency || 'CZK')

/** Label z i18n klíče; když klíč chybí, ukaž raději poslední segment než syrový klíč. */
function label(key: string): string {
  const v = t(key)
  return v === key ? key.split('.').pop() || key : v
}

function fmt(value: unknown, format: SourceFieldFormat, currency: string): string {
  if (value === null || value === undefined || value === '') return '—'
  switch (format) {
    case 'currency':
      return formatMoney(Number(value), currency)
    case 'date':
      return formatDate(String(value))
    case 'percent':
      return `${Number(value)} %`
    case 'number':
      return new Intl.NumberFormat().format(Number(value))
    case 'bool':
      return value ? t('common.yes') : t('common.no')
    case 'doc_ref':
      return `#${value}`
    default:
      return String(value)
  }
}

/** Řádek si může nést vlastní měnu (např. úhrada v jiné měně než faktura). */
function rowCurrency(row: Record<string, unknown>): string {
  const c = row.currency
  return typeof c === 'string' && c ? c : blockCurrency.value
}

function alignClass(align?: string): string {
  return align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'
}
</script>

<template>
  <section class="mt-6">
    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-neutral-500">
      {{ label(block.title_key) }}
    </h3>

    <!-- keyvalue -->
    <dl v-if="block.type === 'keyvalue'" class="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
      <div v-for="item in block.items" :key="item.key"
           class="flex items-baseline justify-between gap-3 border-b border-neutral-200 py-2 text-sm">
        <dt class="shrink-0 text-neutral-500">{{ label(item.label_key) }}</dt>
        <dd class="min-w-0 truncate text-right font-medium"
            :class="item.format === 'currency' || item.format === 'number' ? 'font-mono' : ''">
          {{ fmt(item.value, item.format, blockCurrency) }}
        </dd>
      </div>
    </dl>

    <!-- table -->
    <div v-else class="overflow-x-auto rounded-lg border border-neutral-200">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-neutral-200 bg-neutral-50 text-xs text-neutral-500">
            <th v-for="c in block.columns" :key="c.key" class="px-3 py-2 font-medium" :class="alignClass(c.align)">
              {{ label(c.label_key) }}
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200">
          <tr v-for="(row, i) in block.rows" :key="i">
            <td v-for="c in block.columns" :key="c.key" class="px-3 py-2 align-top" :class="[
              alignClass(c.align),
              c.format === 'currency' || c.format === 'number' ? 'font-mono whitespace-nowrap' : '',
            ]">
              {{ fmt(row[c.key], c.format, rowCurrency(row)) }}
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Oříznutí nikdy tiše: řekni kolik z kolika je vidět. -->
      <p v-if="block.truncated" class="border-t border-neutral-200 bg-neutral-50 px-3 py-1.5 text-xs text-neutral-500">
        {{ t('accounting.journal.source_drawer.truncated', { shown: block.rows.length, total: block.total_rows }) }}
      </p>
    </div>
  </section>
</template>
