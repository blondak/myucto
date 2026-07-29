<script setup lang="ts">
/**
 * Auditní historie účetního zápisu (audit 2026-07, nález „Historie účetního zápisu
 * v UI — SYSTEM VERSIONING timeline"). Lazy-load na první rozbalení — panel je
 * schovaný pod přepínačem, ať se GET .../history nevolá při každém expandu řádku
 * deníku, kde o historii uživatel nestojí.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingApi, type JournalHistoryResponse, type JournalHistoryLineChange,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ entryId: number }>()

const { t } = useI18n()
const toast = useToast()

const open = ref(false)
const loading = ref(false)
const loaded = ref(false)
const history = ref<JournalHistoryResponse | null>(null)

const HEADER_FIELD_LABELS: Record<string, string> = {
  entry_date: 'accounting.journal.entry_date',
  document_date: 'accounting.journal.col_document_date',
  document_no: 'accounting.journal.document_no',
  description: 'accounting.journal.description',
  posted_at: 'accounting.journal.col_posted_at',
  posted_by_name: 'accounting.journal.col_posted_by',
  // reversed_by/source_id/period_id doplněny po adversariálním review (M-1) — storno-
  // přechod (reversed_by NULL → id protizápisu) se teď v diffu skutečně zobrazí.
  reversed_by: 'accounting.journal.reversal_entry',
}
function fieldLabel(field: string): string {
  const key = HEADER_FIELD_LABELS[field]
  return key ? t(key) : field
}
function fieldValue(field: string, v: unknown): string {
  if (field === 'reversed_by') return v ? `#${v}` : '—'
  if (v === null || v === undefined || v === '') return '—'
  if (field === 'entry_date' || field === 'document_date' || field === 'posted_at') return formatDate(String(v))
  return String(v)
}

async function toggle() {
  open.value = !open.value
  if (open.value && !loaded.value) {
    loading.value = true
    try {
      history.value = await accountingApi.getJournalHistory(props.entryId)
      loaded.value = true
    } catch (e: any) {
      toast.error(e?.response?.data?.error?.message || t('common.error'))
    } finally {
      loading.value = false
    }
  }
}

function lineKey(c: JournalHistoryLineChange): string {
  return `${c.type}-${c.line_no}`
}
function lineSideLabel(side: string): string {
  return t(`accounting.journal.side.${side}`)
}
</script>

<template>
  <div class="border-t border-neutral-200 pt-3">
    <button type="button" class="cursor-pointer text-xs font-medium text-neutral-500 inline-flex items-center gap-1.5 hover:text-neutral-700" @click="toggle">
      <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
      {{ t('accounting.journal.history.title') }}
      <span class="inline-block transition-transform text-neutral-400" :class="{ 'rotate-90': open }">▸</span>
    </button>

    <div v-if="open" class="mt-2">
      <div v-if="loading" class="text-xs text-neutral-400 py-2">{{ t('common.loading') }}</div>
      <template v-else-if="history">
        <p v-if="history.versions.length <= 1" class="text-xs text-neutral-400">{{ t('accounting.journal.history.no_changes') }}</p>
        <ol v-else class="space-y-3">
          <li v-for="v in history.versions" :key="v.version" class="border border-neutral-200 rounded-md px-3 py-2 bg-neutral-50">
            <div class="flex items-center flex-wrap gap-2 text-xs">
              <span class="font-semibold text-neutral-700">{{ t('accounting.journal.history.version', { n: v.version }) }}</span>
              <span v-if="v.is_current" class="px-1.5 py-0.5 rounded bg-success-50 text-success-600 font-medium">{{ t('accounting.journal.history.current') }}</span>
              <span class="text-neutral-400">{{ formatDate(v.valid_from) }}</span>
              <span v-if="v.changed_by" class="text-neutral-500">
                — {{ v.changed_by.user_name || t('accounting.journal.history.unknown_user') }}
              </span>
            </div>

            <!-- první (nejstarší) verze = vznik zápisu, žádný diff -->
            <p v-if="!v.header_changes && !v.line_changes" class="text-xs text-neutral-400 mt-1">
              {{ t('accounting.journal.history.created') }}
            </p>

            <template v-else>
              <ul v-if="v.header_changes && Object.keys(v.header_changes).length" class="mt-1.5 space-y-0.5">
                <li v-for="(diff, field) in v.header_changes" :key="field" class="text-xs text-neutral-600">
                  <span class="font-medium">{{ fieldLabel(String(field)) }}:</span>
                  <span class="text-neutral-400 line-through ml-1">{{ fieldValue(String(field), diff.before) }}</span>
                  <span class="mx-1">→</span>
                  <span class="text-neutral-800">{{ fieldValue(String(field), diff.after) }}</span>
                </li>
              </ul>

              <ul v-if="v.line_changes && v.line_changes.length" class="mt-1.5 space-y-0.5">
                <li v-for="c in v.line_changes" :key="lineKey(c)" class="text-xs">
                  <template v-if="c.type === 'added' && c.line">
                    <span class="text-success-600 font-medium">+ </span>
                    <span class="font-mono">{{ c.line.account_code }}</span> {{ c.line.account_name }}
                    — {{ lineSideLabel(c.line.side) }} {{ formatMoney(c.line.amount) }}
                  </template>
                  <template v-else-if="c.type === 'removed' && c.line">
                    <span class="text-danger-500 font-medium">− </span>
                    <span class="font-mono line-through">{{ c.line.account_code }}</span> {{ c.line.account_name }}
                    — {{ lineSideLabel(c.line.side) }} {{ formatMoney(c.line.amount) }}
                  </template>
                  <template v-else-if="c.type === 'changed' && c.before && c.after">
                    <span class="text-warning-600 font-medium">~ </span>
                    <span class="font-mono">{{ c.after.account_code }}</span> {{ c.after.account_name }}:
                    <span class="text-neutral-400 line-through">{{ lineSideLabel(c.before.side) }} {{ formatMoney(c.before.amount) }}</span>
                    <span class="mx-1">→</span>
                    <span>{{ lineSideLabel(c.after.side) }} {{ formatMoney(c.after.amount) }}</span>
                  </template>
                </li>
              </ul>
            </template>
          </li>
        </ol>
      </template>
    </div>
  </div>
</template>
