<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type ProductImportReport, type ProductImportRow } from '@/api/eshop'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()

const MAX_SIZE = 2 * 1024 * 1024

const file = ref<File | null>(null)
const dryRun = ref(true)
const busy = ref(false)
const error = ref('')
const report = ref<ProductImportReport | null>(null)
const onlyProblems = ref(false)

function validate(f: File): boolean {
  const ext = f.name.toLowerCase().split('.').pop() ?? ''
  if (ext !== 'xlsx' && ext !== 'csv') { error.value = t('eshop.import.errors.bad_file'); return false }
  if (f.size > MAX_SIZE) { error.value = t('eshop.import.errors.bad_file'); return false }
  return true
}
function pickFile(f: File | null | undefined) {
  if (!f) return
  error.value = ''
  report.value = null
  if (validate(f)) file.value = f
}
function onPick(e: Event) {
  const input = e.target as HTMLInputElement
  pickFile(input.files?.[0])
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  pickFile(e.dataTransfer?.files?.[0])
}

const rows = computed<ProductImportRow[]>(() => {
  const all = report.value?.rows ?? []
  return onlyProblems.value ? all.filter(r => r.status === 'error' || !!r.message) : all
})
const hasErrors = computed(() => (report.value?.failed ?? 0) > 0)
const realFailed = computed(() => !!report.value && !report.value.dry_run && report.value.failed > 0)
const canCommitReal = computed(() => !!report.value && report.value.dry_run && !hasErrors.value)

async function run(forceReal = false) {
  if (!file.value) return
  const dry = forceReal ? false : dryRun.value
  busy.value = true
  error.value = ''
  try {
    report.value = await eshopApi.importProducts(file.value, dry)
    onlyProblems.value = false
    if (!dry) {
      if (report.value.failed > 0) toast.warning(t('eshop.import.imported_with_errors'))
      else toast.success(t('eshop.import.imported_ok'))
    }
  } catch (e) {
    error.value = mapError(e)
  } finally {
    busy.value = false
  }
}

function reset() {
  file.value = null
  report.value = null
  error.value = ''
  onlyProblems.value = false
}

function mapError(e: unknown): string {
  const code = (e as { response?: { data?: { error?: { code?: string } } } })?.response?.data?.error?.code
  if (code) {
    const key = `eshop.import.errors.${code}`
    const translated = t(key)
    if (translated !== key) return translated
  }
  return apiErrorMessage(e, t('common.error'))
}

function statusLabel(s: ProductImportRow['status']): string {
  return t(`eshop.import.status_${s}`)
}
function statusClass(s: ProductImportRow['status']): string {
  switch (s) {
    case 'create': return 'bg-success-50 text-success-600 border-success-500/40'
    case 'update': return 'bg-primary-50 text-primary-700 border-primary-500/40'
    case 'error':  return 'bg-danger-50 text-danger-500 border-danger-500/40'
    default:       return 'bg-neutral-100 text-neutral-600 border-neutral-200'
  }
}
function changeEntries(r: ProductImportRow): Array<{ field: string; from: unknown; to: unknown }> {
  if (!r.changes) return []
  return Object.entries(r.changes).map(([field, v]) => ({ field, from: v.from, to: v.to }))
}
function fmt(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  return String(v)
}

const COLUMNS = ['sku', 'nazev', 'jednotka', 'ean', 'cena', 'vyrobce', 'skladem', 'export_eshop', 'hmotnost_g', 'zaruka_mesice', 'dodaci_lhuta_dny']
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('eshop.import.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eshop.import.subtitle') }}</p>
      </div>
      <button v-if="report" type="button" @click="reset" :class="btnOutline('neutral')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
        {{ t('eshop.import.reset') }}
      </button>
    </div>

    <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mb-4">{{ error }}</div>

    <!-- Upload panel -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4 mb-4">
      <label
        @dragover.prevent
        @drop="onDrop"
        class="block border-2 border-dashed border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30 rounded-lg p-8 text-center cursor-pointer transition"
      >
        <input type="file" accept=".xlsx,.csv" @change="onPick" class="hidden" />
        <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
        </svg>
        <div class="text-sm font-medium text-neutral-700">{{ t('eshop.import.drop_hint') }}</div>
      </label>

      <div v-if="file" class="border border-neutral-200 rounded-md p-3 bg-neutral-50 flex justify-between text-sm text-neutral-700">
        <span class="truncate font-mono">{{ file.name }}</span>
        <span class="text-neutral-400 ml-2 shrink-0">{{ Math.round(file.size / 1024) }} kB</span>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3">
        <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
          <input v-model="dryRun" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          <span>{{ t('eshop.import.dry_run') }}</span>
        </label>
        <button type="button" @click="run(false)" :disabled="!file || busy" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ busy ? t('common.loading') : (dryRun ? t('eshop.import.run_preview') : t('eshop.import.run_import')) }}
        </button>
      </div>

      <!-- Sloupce souboru -->
      <div class="text-xs text-neutral-500 border-t border-neutral-100 pt-3">
        <span class="font-medium text-neutral-600">{{ t('eshop.import.columns_hint') }}:</span>
        <span class="ml-1">
          <code v-for="(c, i) in COLUMNS" :key="c" class="font-mono">{{ c }}<span v-if="i < COLUMNS.length - 1" class="text-neutral-400">, </span></code>
        </span>
        <div class="mt-1">{{ t('eshop.import.columns_note') }}</div>
      </div>
    </div>

    <!-- Report -->
    <template v-if="report">
      <div
        v-if="realFailed"
        class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-600 mb-4"
      >{{ t('eshop.import.real_failed_warning', { failed: report.failed }) }}</div>

      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="flex flex-wrap items-center gap-4 text-sm px-4 py-3 border-b border-neutral-100">
          <span class="inline-flex items-center gap-1.5">
            <span class="text-xs px-2 py-0.5 rounded font-medium" :class="report.dry_run ? 'bg-neutral-100 text-neutral-600' : 'bg-success-50 text-success-600'">
              {{ report.dry_run ? t('eshop.import.badge_preview') : t('eshop.import.badge_done') }}
            </span>
          </span>
          <span>{{ t('eshop.import.summary', {
            created: report.created,
            updated: report.updated,
            skipped: report.skipped,
            failed:  report.failed,
          }) }}</span>
          <label class="inline-flex items-center gap-2 text-sm ml-auto">
            <input v-model="onlyProblems" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('eshop.import.only_problems') }}</span>
          </label>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-200 bg-neutral-50">
                <th class="py-2 px-3">{{ t('eshop.import.col_line') }}</th>
                <th class="py-2 px-3">{{ t('eshop.import.col_key') }}</th>
                <th class="py-2 px-3">{{ t('eshop.import.col_status') }}</th>
                <th class="py-2 px-3">{{ t('eshop.import.col_detail') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(r, i) in rows" :key="i" class="align-top hover:bg-neutral-50">
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
              <EmptyState v-if="rows.length === 0" dense :colspan="4" accent="neutral" icon="upload"
                :title="t('eshop.import.empty_title')"
                :message="t('eshop.import.empty_hint')" />
            </tbody>
          </table>
        </div>

        <!-- Ostrý import po náhledu bez chyb -->
        <div v-if="canCommitReal" class="flex flex-wrap items-center justify-end gap-3 px-4 py-3 border-t border-neutral-100">
          <span class="text-xs text-neutral-500 mr-auto">{{ t('eshop.import.commit_hint') }}</span>
          <button type="button" @click="run(true)" :disabled="busy" :class="btnFilled('success')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ busy ? t('common.loading') : t('eshop.import.commit') }}
          </button>
        </div>
        <div v-else-if="report.dry_run && hasErrors" class="px-4 py-3 border-t border-neutral-100 text-sm text-danger-500">
          {{ t('eshop.import.errors.import_has_errors') }}
        </div>
      </div>
    </template>
  </div>
</template>
