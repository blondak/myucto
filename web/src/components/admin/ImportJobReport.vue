<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ImportJobReport, ImportReportAgenda, ImportReportProblem } from '@/api/integrations'

const props = defineProps<{ report: ImportJobReport }>()
const { t } = useI18n()

const AGENDAS: ImportReportAgenda[] = ['subjects', 'issued', 'received']

const rows = computed(() =>
  AGENDAS.filter(a => props.report.agendas[a]).map(a => ({ agenda: a, counts: props.report.agendas[a]! })),
)
const errors = computed(() => props.report.problems.filter(p => p.severity === 'error'))
const reviews = computed(() => props.report.problems.filter(p => p.severity === 'review'))

function detailRoute(p: ImportReportProblem) {
  if (p.local_id === null) return null
  if (p.agenda === 'issued') return { name: 'invoice-detail', params: { id: p.local_id } }
  if (p.agenda === 'received') return { name: 'purchase-invoice-detail', params: { id: p.local_id } }
  return null
}
</script>

<template>
  <div class="space-y-3" data-test="import-job-report">
    <p v-if="report.dry_run" class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-xs text-primary-700">
      {{ t('integrations.import_report.dry_run_note') }}
    </p>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-xs text-neutral-500 text-left">
            <th class="py-1 pr-2 font-medium">{{ t('integrations.import_report.agenda') }}</th>
            <th class="py-1 px-2 font-medium text-right">{{ t('integrations.import_report.created') }}</th>
            <th class="py-1 px-2 font-medium text-right">{{ t('integrations.import_report.skipped') }}</th>
            <th class="py-1 px-2 font-medium text-right">{{ t('integrations.import_report.failed') }}</th>
            <th class="py-1 pl-2 font-medium text-right">{{ t('integrations.import_report.review') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.agenda" class="border-t border-neutral-100" :data-test="'agenda-' + row.agenda">
            <td class="py-1 pr-2">{{ t('integrations.import_report.agendas.' + row.agenda) }}</td>
            <td class="py-1 px-2 text-right font-mono">{{ row.counts.created }}</td>
            <td class="py-1 px-2 text-right font-mono">{{ row.counts.skipped }}</td>
            <td class="py-1 px-2 text-right font-mono" :class="row.counts.failed > 0 ? 'text-danger-500 font-semibold' : ''">{{ row.counts.failed }}</td>
            <td class="py-1 pl-2 text-right font-mono" :class="row.counts.review > 0 ? 'text-warning-600 font-semibold' : ''">{{ row.counts.review }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-for="group in [{ key: 'error', items: errors }, { key: 'review', items: reviews }]" :key="group.key">
      <div v-if="group.items.length" class="space-y-2">
        <h3 class="text-xs font-medium" :class="group.key === 'error' ? 'text-danger-500' : 'text-warning-600'">
          {{ t('integrations.import_report.' + (group.key === 'error' ? 'errors_title' : 'review_title'), { count: group.items.length }) }}
        </h3>
        <ul class="space-y-2">
          <li v-for="p in group.items" :key="p.agenda + p.fakturoid_id"
              class="rounded-md border px-3 py-2 text-xs"
              :class="group.key === 'error' ? 'bg-danger-50 border-danger-500/40' : 'bg-warning-50 border-warning-500/40'"
              :data-test="'problem-' + p.fakturoid_id">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span class="font-medium text-neutral-800">{{ t('integrations.import_report.agendas.' + p.agenda) }}</span>
              <RouterLink v-if="detailRoute(p)" :to="detailRoute(p)!" class="font-mono text-primary-700 hover:underline">{{ p.number ?? p.fakturoid_id }}</RouterLink>
              <span v-else class="font-mono">{{ p.number ?? p.fakturoid_id }}</span>
              <span class="text-neutral-500">{{ t('integrations.import_report.fakturoid_id', { id: p.fakturoid_id }) }}</span>
            </div>
            <div class="mt-1 text-neutral-700">{{ p.reason }}</div>
            <div class="mt-1 text-neutral-500">{{ t('integrations.import_report.hints.' + p.hint) }}</div>
          </li>
        </ul>
      </div>
    </div>
    <p v-if="report.problems_omitted > 0" class="text-xs text-neutral-500">
      {{ t('integrations.import_report.omitted', { count: report.problems_omitted }) }}
    </p>
  </div>
</template>
