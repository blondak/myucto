<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AttendancePreview, AttendanceUnrecognizedColumn } from '@/api/payrollImports'
import { btnIconSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { recognitionStats, sheetLabel } from './importHelpers'

/**
 * Kompaktní pruh nad výsledkem náhledu: jaký profil se použil a kolik sloupců
 * zůstalo nerozpoznaných. Měsíční import tu nemá editor mapování — nerozpoznaný
 * sloupec s daty je jediné místo, kde by se hodnota tiše ztratila.
 */
const props = withDefaults(defineProps<{
  preview: AttendancePreview
  showProfile?: boolean
  canEdit?: boolean
  canAddRule?: boolean
}>(), {
  showProfile: true,
  canEdit: false,
  canAddRule: false,
})

const emit = defineEmits<{
  'edit-mapping': []
  'add-rule': [column: AttendanceUnrecognizedColumn]
}>()

const { t } = useI18n()

const open = ref(false)
const stats = computed(() => recognitionStats(props.preview))
const columns = computed(() => props.preview.unrecognized_columns ?? [])
</script>

<template>
  <section class="rounded-xl border px-4 py-3 text-sm" data-testid="attendance-recognition"
    :class="stats.unrecognized ? 'border-warning-500/30 bg-warning-50' : 'border-neutral-200 bg-surface shadow-sm'">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0 space-y-0.5">
        <p v-if="showProfile" class="text-neutral-800">
          <span class="text-neutral-500">{{ t('payroll_imports.strip.profile_label') }}</span>
          <template v-if="preview.profile?.name">
            <strong class="font-semibold">{{ preview.profile.name }}</strong>
            <span class="text-neutral-500"> ({{ t(preview.profile.auto ? 'payroll_imports.strip.auto' : 'payroll_imports.strip.manual') }})</span>
          </template>
          <span v-else class="font-medium">{{ t('payroll_imports.strip.no_profile') }}</span>
        </p>
        <p :class="stats.unrecognized ? 'text-warning-700' : 'text-neutral-600'">
          {{ t('payroll_imports.strip.recognized', { count: stats.recognized, sheets: stats.sheets, unrecognized: stats.unrecognized }) }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button v-if="stats.unrecognized" type="button" :class="btnOutlineSm('warning')" :aria-expanded="open" @click="open = !open">
          <svg class="h-3.5 w-3.5 transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
          {{ t(open ? 'payroll_imports.strip.hide_unrecognized' : 'payroll_imports.strip.show_unrecognized') }}
        </button>
        <button v-if="canEdit" type="button" data-testid="attendance-edit-mapping" :class="btnOutlineSm('primary')" @click="emit('edit-mapping')">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
          {{ t('payroll_imports.strip.edit_mapping') }}
        </button>
      </div>
    </div>

    <div v-if="open && columns.length" class="mt-3">
      <p class="mb-2 text-xs text-warning-700">{{ t('payroll_imports.strip.unrecognized_hint') }}</p>
      <div class="hidden max-h-80 overflow-auto rounded-lg border border-warning-500/20 bg-surface md:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.strip.columns.sheet') }}</th>
              <th class="sticky top-0 w-16 bg-surface px-3 py-2">{{ t('payroll_imports.strip.columns.letter') }}</th>
              <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.strip.columns.header') }}</th>
              <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.strip.columns.samples') }}</th>
              <th v-if="canAddRule" class="sticky top-0 w-10 bg-surface px-3 py-2"><span class="sr-only">{{ t('payroll_imports.strip.add_rule') }}</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="column in columns" :key="`${column.sheet_id}-${column.letter}`">
              <td class="max-w-56 truncate px-3 py-1.5 text-xs text-neutral-600" :title="sheetLabel(preview.sheets, column.sheet_id)">{{ sheetLabel(preview.sheets, column.sheet_id) }}</td>
              <td class="px-3 py-1.5 font-mono text-xs">{{ column.letter }}</td>
              <td class="max-w-56 px-3 py-1.5"><p class="truncate font-medium text-neutral-900" :title="column.header">{{ column.header || '—' }}</p></td>
              <td class="max-w-64 px-3 py-1.5"><p class="truncate font-mono text-xs text-neutral-500" :title="column.samples.join(' | ')">{{ column.samples.join(' · ') || '—' }}</p></td>
              <td v-if="canAddRule" class="px-3 py-1.5">
                <button type="button" :class="btnIconSm('primary')" :disabled="column.header.trim() === ''"
                  :title="t('payroll_imports.strip.add_rule_for', { header: column.header })" :aria-label="t('payroll_imports.strip.add_rule_for', { header: column.header })"
                  @click="emit('add-rule', column)">
                  <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <ul class="space-y-2 md:hidden">
        <li v-for="column in columns" :key="`${column.sheet_id}-${column.letter}`" class="rounded-lg bg-surface p-3">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900"><span class="font-mono text-xs text-neutral-500">{{ column.letter }}</span> {{ column.header || '—' }}</p>
              <p class="truncate text-xs text-neutral-500">{{ sheetLabel(preview.sheets, column.sheet_id) }}</p>
            </div>
            <button v-if="canAddRule" type="button" :class="btnIconSm('primary')" :disabled="column.header.trim() === ''"
              :title="t('payroll_imports.strip.add_rule_for', { header: column.header })" :aria-label="t('payroll_imports.strip.add_rule_for', { header: column.header })"
              @click="emit('add-rule', column)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            </button>
          </div>
          <p class="mt-1 truncate font-mono text-xs text-neutral-500">{{ column.samples.join(' · ') || '—' }}</p>
        </li>
      </ul>
    </div>
  </section>
</template>
