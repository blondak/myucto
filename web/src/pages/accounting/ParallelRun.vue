<script setup lang="ts">
import { ref, computed, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import {
  parallelRunApi,
  type ParallelRunBackup, type ParallelRunCategory, type ParallelRunCheck, type ParallelRunCriterion,
  type ParallelRunDifference, type ParallelRunHistoryItem, type ParallelRunInputKind, type ParallelRunSource,
} from '@/api/parallelRun'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDateTime } from '@/composables/useFormat'
import { downloadApiFile } from '@/utils/downloadFile'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const canWrite = computed(() => auth.canWrite('accounting'))

const INPUT_KINDS: ParallelRunInputKind[] = [
  'trial_balance', 'document_counts', 'saldo', 'bank_balances', 'vat_return',
  'control_statement', 'assets', 'cost_centers', 'balance_sheet', 'income_statement',
]
const INPUT_CRITERIA: Record<ParallelRunInputKind, string> = {
  trial_balance: 'K1', document_counts: 'K5', saldo: 'K6, K7', bank_balances: 'K8, K10', vat_return: 'K9',
  control_statement: 'K9', assets: 'K11', cost_centers: 'K12', balance_sheet: 'K13', income_statement: 'K13',
}
const INPUT_ACCEPT: Record<ParallelRunInputKind, string> = {
  trial_balance: '.csv,.txt', document_counts: '.csv,.txt', saldo: '.csv,.txt', bank_balances: '.csv,.txt',
  vat_return: '.xml', control_statement: '.xml', assets: '.csv,.txt', cost_centers: '.csv,.txt',
  balance_sheet: '.csv,.txt', income_statement: '.csv,.txt',
}
const CRITERIA = ['K1', 'K5', 'K6', 'K7', 'K8', 'K9', 'K10', 'K11', 'K12', 'K13']
const CATEGORIES: ParallelRunCategory[] = ['source', 'migration', 'interpretation']
const SOURCES = ['money_s3', 'pohoda', 'premier', 'other']
const FIELDS = ['opening', 'turnover', 'closing', 'count', 'open_total', 'remaining', 'balance', 'ledger', 'statement',
  'balance_currency', 'balance_czk', 'amount', 'input_price', 'acc_amount', 'net_book_value', 'revenue', 'cost']
const NOTES = ['missing_in_source', 'missing_in_myucto', 'open_only_in_myucto', 'open_only_in_source', 'amount_differs',
  'account_not_found', 'statement_vs_ledger', 'account_total', 'partner_total', 'vat_return', 'control_statement_summary', 'row_unknown']
const BOOKS = ['issued_invoices', 'purchase_invoices', 'cash', 'bank', 'internal']
/** Kolik rozdílů kritéria se vykreslí najednou — protokol jich nese až 500. */
const ROW_CHUNK = 100

function previousMonth(): string {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() - 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

const sources = ref<ParallelRunSource[]>([])
const backups = ref<ParallelRunBackup[]>([])
const history = ref<ParallelRunHistoryItem[]>([])
const check = ref<ParallelRunCheck | null>(null)
const loading = ref(false)
const running = ref(false)
const busy = ref(false)
const fileInputs = ref(0)
const form = reactive({
  month: previousMonth(),
  source: 'money_s3',
  backupToken: '' as string,
  statementUnit: 1,
  files: {} as Partial<Record<ParallelRunInputKind, File>>,
})
const shown = reactive<Record<string, number>>({})
const expanded = reactive<Record<string, boolean>>({})
const notes = reactive<Record<string, string>>({})

const readsBackup = computed(() => sources.value.find(s => s.key === form.source)?.reads_backup ?? false)
const hasInput = computed(() => Object.values(form.files).some(Boolean) || (readsBackup.value && form.backupToken !== ''))
const criteria = computed<ParallelRunCriterion[]>(() => check.value?.result?.criteria ?? [])
const summary = computed(() => check.value?.classification ?? null)
const isClosed = computed(() => check.value?.cycle_status === 'closed')

function sourceLabel(key: string): string {
  return SOURCES.includes(key) ? t(`parallelRun.sources.${key}`) : key
}
function criterionLabel(key: string): string {
  return CRITERIA.includes(key) ? t(`parallelRun.criteria.${key}`) : key
}
function fieldLabel(field: string): string {
  return FIELDS.includes(field) ? t(`parallelRun.fields.${field}`) : field
}
function noteLabel(note: string | null): string {
  return note && NOTES.includes(note) ? t(`parallelRun.notes.${note}`) : ''
}
function subjectLabel(c: ParallelRunCriterion, d: ParallelRunDifference): string {
  if (c.key === 'K5' && BOOKS.includes(d.subject)) return t(`parallelRun.books.${d.subject}`)
  if (c.key === 'K12' && d.subject === '') return t('parallelRun.without_center')
  return d.subject
}
function formatValue(c: ParallelRunCriterion, v: number | null): string {
  if (v === null) return '–'
  return c.key === 'K5' ? String(v) : formatMoney(v, 'CZK', 2)
}
function difference(mine: number | null, theirs: number | null): number | null {
  return mine === null || theirs === null ? null : Math.round((mine - theirs) * 100) / 100
}
function linkTo(d: ParallelRunDifference): RouteLocationRaw | null {
  if (!d.link) return null
  if (d.link.type === 'invoice') return { name: 'invoice-detail', params: { id: d.link.id } }
  if (d.link.type === 'purchase_invoice') return { name: 'purchase-invoice-detail', params: { id: d.link.id } }
  return null
}
function statusClass(status: string): string {
  return status === 'ok' ? 'bg-success-50 text-success-700'
    : status === 'error' || status === 'incomplete' ? 'bg-warning-50 text-warning-700'
    : 'bg-danger-50 text-danger-700'
}
function categoryOf(d: ParallelRunDifference): ParallelRunCategory | '' {
  return check.value?.classifications?.[d.id]?.category ?? ''
}
function visibleDifferences(c: ParallelRunCriterion): ParallelRunDifference[] {
  return c.differences.slice(0, shown[c.key] ?? ROW_CHUNK)
}
function errorMessage(e: any): string {
  return e?.response?.data?.error?.message || t('common.error')
}

async function loadHistory() {
  history.value = await parallelRunApi.history()
}

async function load() {
  loading.value = true
  try {
    const [src, list] = await Promise.all([parallelRunApi.sources(), parallelRunApi.history()])
    sources.value = src.sources
    history.value = list
    backups.value = await parallelRunApi.backups().catch(() => [])
    if (!check.value && list.length > 0) await openCheck(list[0].id)
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    loading.value = false
  }
}

async function openCheck(id: number) {
  try {
    check.value = await parallelRunApi.get(id)
    for (const c of check.value.result?.criteria ?? []) {
      expanded[c.key] = c.status !== 'ok'
      shown[c.key] = ROW_CHUNK
    }
    for (const [id, cl] of Object.entries(check.value.classifications ?? {})) notes[id] = cl.note ?? ''
  } catch (e) {
    toast.error(errorMessage(e))
  }
}

function pickFile(kind: ParallelRunInputKind, event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (file) form.files[kind] = file
  else delete form.files[kind]
}

function clearFiles() {
  form.files = {}
  fileInputs.value++
}

async function run() {
  if (!hasInput.value) return
  running.value = true
  try {
    check.value = null
    const result = await parallelRunApi.run({
      month: form.month,
      source: form.source,
      files: form.files,
      statementUnit: form.statementUnit,
      backupToken: readsBackup.value && form.backupToken !== '' ? form.backupToken : null,
    })
    await openCheck(result.id)
    await loadHistory()
    clearFiles()
    toast.success(t(`parallelRun.run_done_${result.status}`))
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    running.value = false
  }
}

async function classify(d: ParallelRunDifference, category: string) {
  if (!check.value) return
  try {
    check.value = await parallelRunApi.classify(check.value.id, d.id, category === '' ? null : category as ParallelRunCategory, notes[d.id] || null)
  } catch (e) {
    toast.error(errorMessage(e))
  }
}

async function saveNote(d: ParallelRunDifference) {
  const category = categoryOf(d)
  if (!check.value || category === '') return
  if ((check.value.classifications?.[d.id]?.note ?? '') === (notes[d.id] ?? '')) return
  await classify(d, category)
}

async function closeCycle() {
  if (!check.value || !window.confirm(t('parallelRun.close_confirm', { month: check.value.month }))) return
  busy.value = true
  try {
    check.value = await parallelRunApi.close(check.value.id, null)
    await loadHistory()
    toast.success(t('parallelRun.closed'))
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function reopenCycle() {
  if (!check.value) return
  busy.value = true
  try {
    check.value = await parallelRunApi.reopen(check.value.id)
    await loadHistory()
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function removeCheck() {
  if (!check.value || !window.confirm(t('parallelRun.delete_confirm', { month: check.value.month }))) return
  busy.value = true
  try {
    await parallelRunApi.remove(check.value.id)
    check.value = null
    await loadHistory()
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function exportCsv() {
  if (!check.value) return
  try {
    await downloadApiFile(`/accounting/parallel-run/checks/${check.value.id}/export`, `soubeh-${check.value.month}.csv`)
  } catch (e) {
    toast.error(errorMessage(e))
  }
}

const closeReason = computed(() => {
  const s = summary.value
  if (!s) return ''
  if (s.errors > 0) return t('parallelRun.close_blocked_errors')
  if (s.unclassified > 0) return t('parallelRun.close_blocked_unclassified', { count: s.unclassified })
  if (s.migration > 0) return t('parallelRun.close_blocked_migration', { count: s.migration })
  return ''
})

const actions = computed<ActionItem[]>(() => [
  {
    key: 'run', label: t('parallelRun.actions.run'), icon: 'play', tier: 'primary', variant: 'primary',
    show: canWrite.value, disabled: !hasInput.value || running.value, loading: running.value,
    disabledReason: t('parallelRun.run_disabled'), run: () => { void run() },
  },
  {
    key: 'close', label: t('parallelRun.actions.close'), icon: 'check', tier: 'secondary', variant: 'success',
    show: canWrite.value && !!check.value && !isClosed.value,
    disabled: busy.value || !summary.value?.can_close, disabledReason: closeReason.value || undefined,
    run: () => { void closeCycle() },
  },
  {
    key: 'export', label: t('parallelRun.actions.export'), icon: 'download', tier: 'secondary', variant: 'neutral',
    show: !!check.value, run: () => { void exportCsv() },
  },
  {
    key: 'reopen', label: t('parallelRun.actions.reopen'), icon: 'uturn', tier: 'overflow', variant: 'warning',
    show: canWrite.value && isClosed.value, disabled: busy.value, run: () => { void reopenCycle() },
  },
  {
    key: 'reload', label: t('parallelRun.actions.reload'), icon: 'cycle', tier: 'overflow', variant: 'neutral',
    show: true, disabled: loading.value, run: () => { void load() },
  },
  {
    key: 'delete', label: t('parallelRun.actions.delete'), icon: 'trash', tier: 'advanced', variant: 'danger',
    show: canWrite.value && !!check.value && !isClosed.value, disabled: busy.value, run: () => { void removeCheck() },
  },
])

onMounted(load)
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-start justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('parallelRun.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('parallelRun.subtitle') }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <!-- Nová kontrola -->
    <section v-if="canWrite" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4" data-test="new-check">
      <h2 class="text-lg font-medium mb-3">{{ t('parallelRun.new_check') }}</h2>
      <div class="flex flex-wrap items-end gap-3 mb-4">
        <label class="text-xs font-medium text-neutral-500">
          {{ t('parallelRun.month') }}
          <input v-model="form.month" type="month" data-test="month"
            class="mt-1 block h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface text-neutral-800" />
        </label>
        <label class="text-xs font-medium text-neutral-500">
          {{ t('parallelRun.source') }}
          <select v-model="form.source" data-test="source" class="mt-1 block h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface text-neutral-800">
            <option v-for="s in sources" :key="s.key" :value="s.key">{{ sourceLabel(s.key) }}</option>
          </select>
        </label>
        <label v-if="readsBackup" class="text-xs font-medium text-neutral-500">
          {{ t('parallelRun.backup') }}
          <select v-model="form.backupToken" data-test="backup" class="mt-1 block h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface text-neutral-800 max-w-xs">
            <option value="">{{ t('parallelRun.backup_none') }}</option>
            <option v-for="b in backups" :key="b.token" :value="b.token">
              {{ b.agenda_name || b.file_name }} · {{ formatDateTime(b.uploaded_at) }}
            </option>
          </select>
        </label>
        <label class="text-xs font-medium text-neutral-500">
          {{ t('parallelRun.statement_unit') }}
          <select v-model.number="form.statementUnit" class="mt-1 block h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface text-neutral-800">
            <option :value="1">{{ t('parallelRun.unit_czk') }}</option>
            <option :value="1000">{{ t('parallelRun.unit_thousands') }}</option>
          </select>
        </label>
      </div>
      <p v-if="readsBackup" class="text-xs text-neutral-500 mb-3">
        {{ t('parallelRun.backup_hint') }}
        <RouterLink to="/imports/money-s3" class="text-primary-700 hover:underline">{{ t('parallelRun.backup_link') }}</RouterLink>
      </p>
      <div :key="fileInputs" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label v-for="kind in INPUT_KINDS" :key="kind"
          class="block border border-neutral-200 rounded-md p-3 text-sm"
          :class="form.files[kind] ? 'bg-primary-50 border-primary-200' : ''">
          <span class="flex items-center justify-between gap-2">
            <span class="font-medium text-neutral-800">{{ t(`parallelRun.inputs.${kind}`) }}</span>
            <span class="text-xs text-neutral-500 whitespace-nowrap">{{ INPUT_CRITERIA[kind] }}</span>
          </span>
          <input type="file" :accept="INPUT_ACCEPT[kind]" :data-test="`file-${kind}`"
            class="mt-2 block w-full text-xs text-neutral-600" @change="pickFile(kind, $event)" />
        </label>
      </div>
    </section>

    <!-- Výsledek kontroly -->
    <section v-if="check" class="mb-6" data-test="result">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-3">
        <div class="flex flex-wrap items-center gap-2 mb-2">
          <h2 class="text-lg font-medium mr-2">{{ t('parallelRun.result_title', { month: check.month }) }}</h2>
          <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusClass(check.status)">{{ t(`parallelRun.status.${check.status}`) }}</span>
          <span class="text-xs px-2 py-0.5 rounded font-medium" :class="isClosed ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-700'">{{ t(`parallelRun.cycle.${check.cycle_status}`) }}</span>
          <span class="text-xs text-neutral-500">{{ sourceLabel(check.source) }} · {{ formatDateTime(check.created_at) }}</span>
        </div>
        <div v-if="summary" class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-700">
          <span>{{ t('parallelRun.summary_differences', { count: summary.differences }) }}</span>
          <span :class="summary.unclassified > 0 ? 'text-warning-700 font-medium' : ''">{{ t('parallelRun.summary_unclassified', { count: summary.unclassified }) }}</span>
          <span v-for="cat in CATEGORIES" :key="cat">{{ t(`parallelRun.categories.${cat}`) }}: {{ summary[cat] }}</span>
        </div>
        <ul v-if="check.result?.warnings?.length" class="mt-2 text-sm text-warning-700 list-disc pl-5">
          <li v-for="(w, i) in check.result.warnings" :key="i">{{ w }}</li>
        </ul>
        <p v-if="check.inputs.length" class="mt-2 text-xs text-neutral-500">
          {{ t('parallelRun.inputs_used') }}:
          <span v-for="(inp, i) in check.inputs" :key="i" class="mr-2" :title="inp.sha256">{{ inp.name }}<template v-if="inp.note"> ({{ inp.note }})</template></span>
        </p>
      </div>

      <div v-for="c in criteria" :key="c.key" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-3 overflow-hidden" :data-test="`criterion-${c.key}`">
        <button type="button" class="w-full flex flex-wrap items-center gap-2 px-4 py-3 text-left cursor-pointer hover:bg-neutral-50"
          @click="expanded[c.key] = !expanded[c.key]">
          <span class="font-mono text-xs text-neutral-500 w-8">{{ c.key }}</span>
          <span class="font-medium text-neutral-800 flex-1 min-w-[12rem]">{{ criterionLabel(c.key) }}</span>
          <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusClass(c.status)">{{ t(`parallelRun.status.${c.status}`) }}</span>
          <span v-if="c.difference_count > 0" class="text-xs text-neutral-600 whitespace-nowrap">{{ t('parallelRun.difference_count', { count: c.difference_count }) }}</span>
        </button>
        <p v-if="c.status === 'error'" class="px-4 pb-3 text-sm text-warning-700">{{ c.message }}</p>
        <div v-if="expanded[c.key] && c.differences.length" class="overflow-x-auto border-t border-neutral-100">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.col_subject') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.col_values') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.col_reason') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.col_classification') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="d in visibleDifferences(c)" :key="d.id" class="align-top">
                <td class="px-3 py-2">
                  <RouterLink v-if="linkTo(d)" :to="linkTo(d)!" class="text-primary-700 hover:underline font-medium">{{ subjectLabel(c, d) }}</RouterLink>
                  <span v-else class="font-medium">{{ subjectLabel(c, d) }}</span>
                  <div v-if="d.label" class="text-xs text-neutral-500">{{ d.label }}</div>
                </td>
                <td class="px-3 py-2">
                  <table class="text-xs">
                    <tr v-for="v in d.values" :key="v.field">
                      <td class="pr-3 text-neutral-500 whitespace-nowrap">{{ fieldLabel(v.field) }}</td>
                      <td class="pr-3 text-right tabular-nums whitespace-nowrap" :title="t('parallelRun.mine')">{{ formatValue(c, v.mine) }}</td>
                      <td class="pr-3 text-right tabular-nums whitespace-nowrap text-neutral-600" :title="t('parallelRun.theirs')">{{ formatValue(c, v.theirs) }}</td>
                      <td class="text-right tabular-nums whitespace-nowrap font-medium"
                        :class="difference(v.mine, v.theirs) ? 'text-danger-600' : 'text-neutral-400'">{{ difference(v.mine, v.theirs) === null ? '' : formatValue(c, difference(v.mine, v.theirs)) }}</td>
                    </tr>
                  </table>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-600">{{ noteLabel(d.note) }}</td>
                <td class="px-3 py-2">
                  <div class="flex flex-wrap gap-2 items-center">
                    <select :value="categoryOf(d)" :disabled="!canWrite || isClosed" :data-test="`classify-${d.id}`"
                      class="h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface"
                      @change="classify(d, ($event.target as HTMLSelectElement).value)">
                      <option value="">{{ t('parallelRun.categories.unclassified') }}</option>
                      <option v-for="cat in CATEGORIES" :key="cat" :value="cat">{{ t(`parallelRun.categories.${cat}`) }}</option>
                    </select>
                    <input v-model="notes[d.id]" type="text" :disabled="!canWrite || isClosed || categoryOf(d) === ''"
                      :placeholder="t('parallelRun.note_placeholder')" maxlength="1000"
                      class="h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface w-48"
                      @blur="saveNote(d)" @keyup.enter="saveNote(d)" />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="c.differences.length > (shown[c.key] ?? ROW_CHUNK) || c.difference_count > c.differences.length" class="px-4 py-2 text-xs text-neutral-500 flex flex-wrap gap-3 items-center">
            <button v-if="c.differences.length > (shown[c.key] ?? ROW_CHUNK)" type="button" class="text-primary-700 hover:underline cursor-pointer"
              @click="shown[c.key] = (shown[c.key] ?? ROW_CHUNK) + ROW_CHUNK">
              {{ t('parallelRun.show_more', { count: c.differences.length - (shown[c.key] ?? ROW_CHUNK) }) }}
            </button>
            <span v-if="c.difference_count > c.differences.length">{{ t('parallelRun.truncated', { count: c.difference_count - c.differences.length }) }}</span>
          </div>
        </div>
        <p v-else-if="expanded[c.key] && c.status === 'ok'" class="px-4 pb-3 text-sm text-success-700">{{ t('parallelRun.no_differences') }}</p>
      </div>
    </section>

    <!-- Historie cyklů -->
    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden" data-test="history">
      <h2 class="text-lg font-medium px-4 pt-3 pb-2">{{ t('parallelRun.history') }}</h2>
      <EmptyState v-if="!loading && !history.length" :title="t('parallelRun.history_empty')" />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.month') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.source') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.col_status') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.col_cycle') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.col_classified') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('parallelRun.col_created') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="h in history" :key="h.id" class="cursor-pointer hover:bg-neutral-50"
              :class="check?.id === h.id ? 'bg-primary-50' : ''" @click="openCheck(h.id)">
              <td class="px-3 py-2 font-medium">{{ h.month }}</td>
              <td class="px-3 py-2">{{ sourceLabel(h.source) }}</td>
              <td class="px-3 py-2"><span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusClass(h.status)">{{ t(`parallelRun.status.${h.status}`) }}</span></td>
              <td class="px-3 py-2">{{ t(`parallelRun.cycle.${h.cycle_status}`) }}</td>
              <td class="px-3 py-2 text-xs text-neutral-600">
                <span v-for="cat in CATEGORIES" :key="cat" class="mr-2">{{ t(`parallelRun.categories.${cat}`) }} {{ h.classification[cat] }}</span>
              </td>
              <td class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap">{{ formatDateTime(h.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
