<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AttendanceColumn, AttendanceSheet } from '@/api/payrollImports'
import { ICONS } from '@/components/ui/buttonStyles'

/** Výsledek mapování jen ke čtení: po listech, sloupec → význam, zdroj pravidla, ukázky. */
const props = defineProps<{
  sheets: AttendanceSheet[]
}>()

const { t, te } = useI18n()

const collapsed = ref<Record<string, boolean>>({})
const noUsedSheet = computed(() => props.sheets.length > 0 && !props.sheets.some(sheet => sheet.used))

function mappedCount(sheet: AttendanceSheet): number {
  return sheet.columns.filter(column => column.meaning !== 'ignore').length
}

function meaningLabel(meaning: string): string {
  const key = `payroll_imports.meanings.${meaning}`
  return te(key) ? t(key) : meaning
}

function unitLabel(unit: string | null): string {
  if (unit === null) return '—'
  const key = `payroll_imports.units.${unit}`
  return te(key) ? t(key) : unit
}

function ruleSourceLabel(source: string): string {
  const key = `payroll_imports.rule_source.${source}`
  return te(key) ? t(key) : source
}

function ruleSourceClass(column: AttendanceColumn): string {
  if (column.rule_source === 'profile') return 'bg-payroll-50 text-payroll-700'
  if (column.rule_source === 'suggested') return 'bg-primary-50 text-primary-700'
  return 'bg-neutral-100 text-neutral-500'
}
</script>

<template>
  <div class="space-y-3">
    <p v-if="sheets.length === 0" class="rounded-lg bg-neutral-50 px-4 py-6 text-sm text-neutral-500">{{ t('payroll_imports.mapping_result.no_sheets') }}</p>
    <p v-else-if="noUsedSheet" role="status" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">{{ t('payroll_imports.mapping_result.no_used_sheet') }}</p>

    <article v-for="sheet in sheets" :key="sheet.id" class="rounded-xl border bg-surface shadow-sm"
      :class="sheet.used ? 'border-neutral-200' : 'border-dashed border-neutral-300'">
      <header class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" :class="collapsed[sheet.id] ? '' : 'border-b border-neutral-100'">
        <button type="button" class="flex min-w-0 cursor-pointer items-center gap-2 text-left" :aria-expanded="!collapsed[sheet.id]"
          @click="collapsed[sheet.id] = !collapsed[sheet.id]">
          <svg class="h-4 w-4 shrink-0 text-neutral-400 transition-transform" :class="collapsed[sheet.id] ? '-rotate-90' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
          <span class="min-w-0">
            <span class="block truncate font-semibold text-neutral-900">{{ sheet.sheet }}</span>
            <span class="block truncate text-xs text-neutral-500">{{ sheet.file }}</span>
          </span>
        </button>
        <div class="flex flex-wrap items-center gap-2 text-xs text-neutral-500">
          <span>{{ t('payroll_imports.mapping_result.header_row', { row: sheet.header_row ?? '—' }) }}</span>
          <span>{{ t('payroll_imports.mapping_result.data_rows', { count: sheet.data_rows }) }}</span>
          <span>{{ t('payroll_imports.mapping_result.mapped', { count: mappedCount(sheet), total: sheet.columns.length }) }}</span>
          <span class="whitespace-nowrap rounded-full px-2 py-0.5 font-medium"
            :class="sheet.used ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-600'">
            {{ t(sheet.used ? 'payroll_imports.mapping_result.sheet_used' : 'payroll_imports.mapping_result.sheet_unused') }}
          </span>
        </div>
      </header>

      <template v-if="!collapsed[sheet.id]">
        <p v-if="!sheet.used" class="px-4 pt-3 text-xs text-neutral-500">{{ t('payroll_imports.mapping_result.sheet_unused_hint') }}</p>

        <div class="hidden max-h-[60vh] overflow-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="sticky top-0 z-10 w-12 bg-surface px-3 py-2">{{ t('payroll_imports.mapping_result.columns.letter') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.mapping_result.columns.header') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.mapping_result.columns.meaning') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.mapping_result.columns.unit') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.mapping_result.columns.source') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.mapping_result.columns.samples') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="column in sheet.columns" :key="column.letter" :class="column.meaning === 'ignore' ? 'text-neutral-500' : 'text-neutral-800'">
                <td class="px-3 py-1.5 font-mono text-xs">{{ column.letter }}</td>
                <td class="max-w-56 px-3 py-1.5"><p class="truncate font-medium" :title="column.header">{{ column.header || '—' }}</p></td>
                <td class="px-3 py-1.5">
                  {{ meaningLabel(column.meaning) }}
                  <span v-if="column.meaning === 'component' && column.component_code" class="ml-1 font-mono text-xs text-neutral-500">{{ column.component_code }}</span>
                </td>
                <td class="whitespace-nowrap px-3 py-1.5 text-xs">{{ column.meaning === 'ignore' ? '—' : unitLabel(column.unit) }}</td>
                <td class="px-3 py-1.5">
                  <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium" :class="ruleSourceClass(column)">{{ ruleSourceLabel(column.rule_source) }}</span>
                </td>
                <td class="max-w-56 px-3 py-1.5">
                  <p class="truncate font-mono text-xs text-neutral-500" :title="column.samples.join(' | ')">{{ column.samples.join(' · ') || '—' }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <ul class="space-y-2 p-3 md:hidden">
          <li v-for="column in sheet.columns" :key="column.letter" class="rounded-lg bg-neutral-50 p-3 text-sm">
            <div class="flex items-baseline justify-between gap-2">
              <p class="min-w-0 truncate font-medium text-neutral-900"><span class="font-mono text-xs text-neutral-500">{{ column.letter }}</span> {{ column.header || '—' }}</p>
              <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="ruleSourceClass(column)">{{ ruleSourceLabel(column.rule_source) }}</span>
            </div>
            <p class="mt-1" :class="column.meaning === 'ignore' ? 'text-neutral-500' : 'text-neutral-800'">
              {{ meaningLabel(column.meaning) }}
              <span v-if="column.meaning === 'component' && column.component_code" class="font-mono text-xs text-neutral-500">{{ column.component_code }}</span>
              <span v-if="column.meaning !== 'ignore'" class="text-xs text-neutral-500"> · {{ unitLabel(column.unit) }}</span>
            </p>
            <p class="mt-1 truncate font-mono text-xs text-neutral-500">{{ column.samples.join(' · ') || '—' }}</p>
          </li>
        </ul>
      </template>
    </article>
  </div>
</template>
