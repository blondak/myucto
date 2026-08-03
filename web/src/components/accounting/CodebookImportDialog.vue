<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { codebookTransferApi, type CodebookKind, type ImportReport, type ImportRow } from '@/api/codebookTransfer'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import EmptyState from '@/components/ui/EmptyState.vue'

const props = defineProps<{ modelValue: boolean; kind: CodebookKind; title: string }>()
const emit = defineEmits<{ 'update:modelValue': [v: boolean]; imported: [] }>()

const { t } = useI18n()
const toast = useToast()

const MAX_SIZE = 2 * 1024 * 1024

type Step = 'upload' | 'preview' | 'result'
const step = ref<Step>('upload')
const file = ref<File | null>(null)
const busy = ref(false)
const error = ref('')
const report = ref<ImportReport | null>(null)
const onlyProblems = ref(false)

function close() {
  emit('update:modelValue', false)
  reset()
}
function reset() {
  step.value = 'upload'
  file.value = null
  report.value = null
  error.value = ''
  onlyProblems.value = false
  busy.value = false
}

function validate(f: File): boolean {
  const ext = f.name.toLowerCase().split('.').pop() ?? ''
  if (ext !== 'xlsx' && ext !== 'csv') { error.value = t('codebookTransfer.errors.bad_file'); return false }
  if (f.size > MAX_SIZE) { error.value = t('codebookTransfer.errors.bad_file'); return false }
  return true
}
function onPick(e: Event) {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0]
  if (!f) return
  error.value = ''
  if (validate(f)) file.value = f
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  const f = e.dataTransfer?.files?.[0]
  if (!f) return
  error.value = ''
  if (validate(f)) file.value = f
}

const rows = computed<ImportRow[]>(() => {
  const all = report.value?.rows ?? []
  return onlyProblems.value ? all.filter(r => r.status === 'error' || !!r.message) : all
})
const hasErrors = computed(() => (report.value?.failed ?? 0) > 0)

async function runPreview() {
  if (!file.value) return
  busy.value = true; error.value = ''
  try {
    report.value = await codebookTransferApi.import(props.kind, file.value, true)
    step.value = 'preview'
  } catch (e) {
    error.value = mapError(e)
  } finally { busy.value = false }
}
async function runImport() {
  if (!file.value || hasErrors.value) return
  busy.value = true; error.value = ''
  try {
    report.value = await codebookTransferApi.import(props.kind, file.value, false)
    step.value = 'result'
    toast.success(t('codebookTransfer.imported_ok'))
    emit('imported')
  } catch (e) {
    error.value = mapError(e)
  } finally { busy.value = false }
}

function mapError(e: unknown): string {
  const code = (e as { response?: { data?: { error?: { code?: string } } } })?.response?.data?.error?.code
  if (code) {
    const key = `codebookTransfer.errors.${code}`
    const translated = t(key)
    if (translated !== key) return translated
  }
  return apiErrorMessage(e, t('common.error'))
}

function statusLabel(s: ImportRow['status']): string {
  return t(`codebookTransfer.status_${s}`)
}
function statusClass(s: ImportRow['status']): string {
  switch (s) {
    case 'create': return 'bg-success-50 text-success-600 border-success-500/40'
    case 'update': return 'bg-primary-50 text-primary-700 border-primary-500/40'
    case 'error':  return 'bg-danger-50 text-danger-500 border-danger-500/40'
    default:       return 'bg-neutral-100 text-neutral-600 border-neutral-200'
  }
}
function changeEntries(r: ImportRow): Array<{ field: string; from: unknown; to: unknown }> {
  if (!r.changes) return []
  return Object.entries(r.changes).map(([field, v]) => ({ field, from: v.from, to: v.to }))
}
function fmt(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  return String(v)
}
</script>

<template>
  <div
    v-if="modelValue"
    class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto"
    @click.self="close"
  >
    <div class="bg-surface rounded-xl shadow-lg max-w-3xl w-full my-8">
      <!-- Header -->
      <header class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h3 class="text-lg font-semibold">{{ title }}</h3>
        <button @click="close" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
      </header>

      <!-- Stepper -->
      <div class="px-5 pt-3 flex items-center gap-2 text-xs text-neutral-500">
        <span :class="step === 'upload' ? 'text-primary-700 font-medium' : ''">1. {{ t('codebookTransfer.step_upload') }}</span>
        <span aria-hidden="true">›</span>
        <span :class="step === 'preview' ? 'text-primary-700 font-medium' : ''">2. {{ t('codebookTransfer.step_preview') }}</span>
        <span aria-hidden="true">›</span>
        <span :class="step === 'result' ? 'text-primary-700 font-medium' : ''">3. {{ t('codebookTransfer.step_result') }}</span>
      </div>

      <div class="p-5 space-y-4">
        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>

        <!-- ═══ Step 1: Upload ═══ -->
        <template v-if="step === 'upload'">
          <label
            @dragover.prevent
            @drop="onDrop"
            class="block border-2 border-dashed border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30 rounded-lg p-8 text-center cursor-pointer transition"
          >
            <input type="file" accept=".xlsx,.csv" @change="onPick" class="hidden" />
            <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
            </svg>
            <div class="text-sm font-medium text-neutral-700">{{ t('codebookTransfer.drop_hint') }}</div>
          </label>

          <div v-if="file" class="border border-neutral-200 rounded-md p-3 bg-neutral-50 flex justify-between text-sm text-neutral-700">
            <span class="truncate font-mono">{{ file.name }}</span>
            <span class="text-neutral-400 ml-2 shrink-0">{{ Math.round(file.size / 1024) }} kB</span>
          </div>

          <div class="flex justify-end gap-2">
            <button @click="close" class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm text-neutral-700 hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button
              @click="runPreview"
              :disabled="!file || busy"
              class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm font-medium hover:bg-primary-700 disabled:bg-neutral-300"
            >{{ busy ? t('common.loading') : t('codebookTransfer.step_preview') }}</button>
          </div>
        </template>

        <!-- ═══ Step 2: Preview / Step 3: Result (sdílená tabulka) ═══ -->
        <template v-else>
          <div class="flex flex-wrap items-center gap-4 text-sm">
            <span>{{ t('codebookTransfer.summary', {
              created: report?.created ?? 0,
              updated: report?.updated ?? 0,
              skipped: report?.skipped ?? 0,
              failed:  report?.failed ?? 0,
            }) }}</span>
            <label v-if="step === 'preview'" class="inline-flex items-center gap-2 text-sm ml-auto">
              <input v-model="onlyProblems" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span>{{ t('codebookTransfer.only_problems') }}</span>
            </label>
          </div>

          <div class="overflow-x-auto border border-neutral-200 rounded-lg">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-200 bg-neutral-50">
                  <th class="py-2 px-3">{{ t('codebookTransfer.col_line') }}</th>
                  <th class="py-2 px-3">{{ t('codebookTransfer.col_key') }}</th>
                  <th class="py-2 px-3">{{ t('codebookTransfer.col_status') }}</th>
                  <th class="py-2 px-3">{{ t('codebookTransfer.col_detail') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(r, i) in rows" :key="i" class="border-b border-neutral-100 align-top">
                  <td class="py-2 px-3 text-neutral-500 font-mono">{{ r.line }}</td>
                  <td class="py-2 px-3 font-mono text-neutral-800">{{ r.key }}</td>
                  <td class="py-2 px-3">
                    <span class="inline-block px-2 py-0.5 text-xs rounded border" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</span>
                  </td>
                  <td class="py-2 px-3 text-neutral-600">
                    <ul v-if="changeEntries(r).length" class="space-y-0.5">
                      <li v-for="c in changeEntries(r)" :key="c.field" class="text-xs">
                        <span class="text-neutral-500">{{ c.field }}:</span>
                        <span class="line-through text-neutral-400 ml-1">{{ fmt(c.from) }}</span>
                        <span class="mx-1">→</span>
                        <span class="text-neutral-800">{{ fmt(c.to) }}</span>
                      </li>
                    </ul>
                    <div v-if="r.message" class="text-xs" :class="r.status === 'error' ? 'text-danger-500' : 'text-warning-600'">{{ r.message }}</div>
                    <span v-if="!changeEntries(r).length && !r.message" class="text-neutral-400">—</span>
                  </td>
                </tr>
                <EmptyState v-if="rows.length === 0" :colspan="4" dense accent="neutral" icon="doc" :title="t('common.no_data')" />
              </tbody>
            </table>
          </div>

          <div class="flex justify-end gap-2">
            <button
              v-if="step === 'preview'"
              @click="reset"
              class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm text-neutral-700 hover:bg-neutral-50"
            >{{ t('common.back') }}</button>
            <button
              v-else
              @click="close"
              class="cursor-pointer h-9 px-4 border border-neutral-300 rounded-md text-sm text-neutral-700 hover:bg-neutral-50"
            >{{ t('common.close') }}</button>
            <button
              v-if="step === 'preview'"
              @click="runImport"
              :disabled="busy || hasErrors"
              class="cursor-pointer h-9 px-4 rounded-md bg-primary-600 text-white text-sm font-medium hover:bg-primary-700 disabled:bg-neutral-300"
            >{{ busy ? t('common.loading') : t('codebookTransfer.confirm_import') }}</button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
