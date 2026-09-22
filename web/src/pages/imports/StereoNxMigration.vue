<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { stereoNxApi, type StereoCompany, type StereoPreview, type StereoProtocolRun, type StereoReport, type StereoUpload } from '@/api/stereoNx'
import type { MoneyS3Step } from '@/api/moneyS3'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import type { FileImportJob } from '@/api/imports'
import { useSupplierStore } from '@/stores/supplier'

const { t, te, tm, rt, locale } = useI18n()
const toast = useToast()
const supplier = useSupplierStore()
const currentStep = ref(1)
const file = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const token = ref<string | null>(null)
const uploads = ref<StereoUpload[]>([])
const loadingUploads = ref(false)
const companies = ref<StereoCompany[]>([])
const target = ref<StereoPreview['target'] | null>(null)
const selected = ref<number | null>(null)
const progress = ref<number | null>(null)
const busy = ref(false)
const dryReport = ref<StereoReport | null>(null)
const importReport = ref<StereoReport | null>(null)
const confirmed = ref(false)
const deleteAfterImport = ref(false)
const cleanupWarning = ref('')
const reportCompany = ref<{ name: string; ico: string } | null>(null)
const blankCountryIsCz = ref(false)
// Zkouška i převod běží na serveru jako job; stav jobu pro ukazatel průběhu.
const job = ref<FileImportJob | null>(null)
const error = ref('')

const selectedCompany = computed(() => companies.value.find(item => item.index === selected.value))
const canRun = computed(() => !!token.value && selectedCompany.value?.matches_target === true && selectedCompany.value.identity.vat_payer && target.value?.accounting_mode === 'tax_evidence' && target.value.vat_payer)
const canImport = computed(() => canRun.value && dryReport.value?.ok === true && confirmed.value)
const backupHelpItems = computed(() => {
  const items = tm('stereo_nx.upload_help_items') as unknown
  return Array.isArray(items) ? items.map(item => rt(item as Parameters<typeof rt>[0])) : []
})
const steps = computed(() => [1, 2, 3, 4].map(number => ({ number, label: t(`stereo_nx.step${number}`) })))

function canGoTo(step: number): boolean {
  if (busy.value || step === currentStep.value) return false
  if (step === 1) return true
  if (step === 2) return !!token.value && companies.value.length > 0
  if (step === 3) return canRun.value === true
  return dryReport.value?.ok === true || importReport.value !== null
}

function goTo(step: number): void {
  if (canGoTo(step)) currentStep.value = step
}

const actions = computed<ActionItem[]>(() => {
  if (currentStep.value === 1) return [
    { key: 'upload', label: t('stereo_nx.upload'), icon: 'upload', tier: 'primary', variant: 'primary', disabled: !file.value || busy.value, loading: busy.value, run: () => { void upload() } },
  ]
  if (currentStep.value === 2) return [
    { key: 'continue', label: t('stereo_nx.continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: !canRun.value || busy.value, run: () => { currentStep.value = 3 } },
    { key: 'read', label: t('stereo_nx.read_again'), icon: 'cycle', tier: 'secondary', variant: 'neutral', disabled: busy.value, run: () => { void preview() } },
  ]
  if (currentStep.value === 3) return dryReport.value?.ok === true ? [
    { key: 'continue', label: t('stereo_nx.continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: busy.value, run: () => { currentStep.value = 4 } },
    { key: 'rerun', label: t('stereo_nx.dry_run_again'), icon: 'cycle', tier: 'secondary', variant: 'neutral', disabled: busy.value, run: () => { void run('dry_run') } },
  ] : [
    { key: 'dry', label: t('stereo_nx.dry_run'), icon: 'play', tier: 'primary', variant: 'primary', disabled: busy.value || !canRun.value, loading: busy.value, run: () => { void run('dry_run') } },
  ]
  if (importReport.value?.ok) return [
    { key: 'new', label: t('stereo_nx.new_upload'), icon: 'upload', tier: 'primary', variant: 'primary', disabled: busy.value, run: resetSelection },
  ]
  return [
    { key: 'import', label: t('stereo_nx.import'), icon: 'play', tier: 'primary', variant: 'warning', disabled: busy.value || !canImport.value, loading: busy.value, run: () => { void run('import') } },
    { key: 'back', label: t('stereo_nx.dry_run_again'), icon: 'cycle', tier: 'secondary', variant: 'neutral', disabled: busy.value, run: () => { currentStep.value = 3 } },
  ]
})

function resetSelection(): void {
  currentStep.value = 1
  file.value = null
  if (fileInput.value) fileInput.value.value = ''
  token.value = null
  companies.value = []
  target.value = null
  selected.value = null
  progress.value = null
  dryReport.value = null
  importReport.value = null
  confirmed.value = false
  deleteAfterImport.value = false
  cleanupWarning.value = ''
  reportCompany.value = null
  blankCountryIsCz.value = false
  error.value = ''
}

let listRequest = 0
async function refreshUploads(): Promise<void> {
  const requestId = ++listRequest
  const supplierId = supplier.currentSupplierId
  if (!supplierId) { uploads.value = []; loadingUploads.value = false; return }
  loadingUploads.value = true
  try {
    const result = await stereoNxApi.uploads()
    if (requestId === listRequest && supplier.currentSupplierId === supplierId) uploads.value = result
  } catch (caught) {
    if (requestId === listRequest && supplier.currentSupplierId === supplierId && !error.value) error.value = message(caught)
  } finally {
    if (requestId === listRequest) loadingUploads.value = false
  }
}

watch(() => supplier.currentSupplierId, () => {
  resetSelection()
  uploads.value = []
  void refreshUploads()
}, { immediate: true })

function uploadDate(timestamp: number): string {
  return new Date(timestamp * 1000).toLocaleString(locale.value)
}

function uploadSize(bytes: number): string {
  return `${(bytes / 1024 / 1024).toLocaleString(locale.value, { maximumFractionDigits: 1 })} MB`
}

function onFile(event: Event): void {
  currentStep.value = 1
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
  token.value = null
  companies.value = []
  target.value = null
  selected.value = null
  dryReport.value = null
  importReport.value = null
  confirmed.value = false
  deleteAfterImport.value = false
  cleanupWarning.value = ''
  reportCompany.value = null
  blankCountryIsCz.value = false
}

function invalidateDryRun(): void {
  dryReport.value = null
  importReport.value = null
  confirmed.value = false
  deleteAfterImport.value = false
  cleanupWarning.value = ''
  reportCompany.value = null
}

function message(caught: unknown): string {
  const response = caught as { response?: { data?: { error?: { code?: string; message?: string } } } }
  if (response.response?.data?.error?.code === 'migration_required') return t('stereo_nx.migration_required')
  return response.response?.data?.error?.message || t('stereo_nx.failed')
}

async function upload(): Promise<void> {
  if (!file.value) return
  const supplierId = supplier.currentSupplierId
  busy.value = true
  error.value = ''
  try {
    const result = await stereoNxApi.upload(file.value,
      (sent, total) => { if (supplier.currentSupplierId === supplierId) progress.value = total ? Math.round(sent / total * 100) : 0 },
      started => { if (supplier.currentSupplierId === supplierId) token.value = started })
    if (supplier.currentSupplierId !== supplierId) return
    token.value = result.token
    progress.value = null
    await preview()
  } catch (caught) {
    if (supplier.currentSupplierId !== supplierId) return
    error.value = message(caught)
    toast.error(error.value)
  } finally {
    if (supplier.currentSupplierId === supplierId) {
      progress.value = null
      await refreshUploads()
    }
    busy.value = false
  }
}

async function openUpload(upload: StereoUpload): Promise<void> {
  if (!upload.complete || busy.value) return
  token.value = upload.token
  file.value = null
  if (fileInput.value) fileInput.value.value = ''
  companies.value = []
  target.value = null
  selected.value = null
  dryReport.value = null
  importReport.value = null
  confirmed.value = false
  deleteAfterImport.value = false
  cleanupWarning.value = ''
  reportCompany.value = null
  blankCountryIsCz.value = false
  await preview()
}

async function preview(): Promise<void> {
  if (!token.value) return
  const supplierId = supplier.currentSupplierId
  const uploadToken = token.value
  busy.value = true
  error.value = ''
  cleanupWarning.value = ''
  try {
    const result = await stereoNxApi.preview(uploadToken)
    if (supplier.currentSupplierId !== supplierId || token.value !== uploadToken) return
    companies.value = result.companies
    target.value = result.target
    selected.value = result.companies.find(item => item.matches_target)?.index ?? null
    dryReport.value = null
    importReport.value = null
    currentStep.value = 2
  } catch (caught) {
    if (supplier.currentSupplierId !== supplierId || token.value !== uploadToken) return
    error.value = message(caught)
    toast.error(error.value)
  } finally {
    busy.value = false
  }
}

async function run(mode: 'dry_run' | 'import'): Promise<void> {
  if (!token.value || selected.value === null) return
  if (mode === 'dry_run') {
    importReport.value = null
    confirmed.value = false
  }
  const supplierId = supplier.currentSupplierId
  const uploadToken = token.value
  const company = selected.value
  busy.value = true
  job.value = null
  error.value = ''
  cleanupWarning.value = ''
  try {
    const report = await stereoNxApi.run(uploadToken, company, mode, blankCountryIsCz.value, current => {
      if (supplier.currentSupplierId === supplierId && token.value === uploadToken) job.value = current
    })
    if (supplier.currentSupplierId !== supplierId || token.value !== uploadToken) return
    reportCompany.value = selectedCompany.value ? { name: selectedCompany.value.identity.name, ico: selectedCompany.value.identity.ico } : null
    if (mode === 'dry_run') {
      dryReport.value = report
      importReport.value = null
      confirmed.value = false
      currentStep.value = 3
    } else {
      importReport.value = report
      dryReport.value = null
      confirmed.value = false
      currentStep.value = 4
      if (report.ok && report.database_writes === true && deleteAfterImport.value) {
        try {
          await stereoNxApi.remove(uploadToken)
          if (supplier.currentSupplierId !== supplierId || token.value !== uploadToken) return
          token.value = null
          file.value = null
          if (fileInput.value) fileInput.value.value = ''
          companies.value = []
          target.value = null
          selected.value = null
          deleteAfterImport.value = false
          await refreshUploads()
        } catch {
          if (supplier.currentSupplierId !== supplierId || token.value !== uploadToken) return
          cleanupWarning.value = t('stereo_nx.cleanup_failed')
          await refreshUploads()
        }
      }
    }
  } catch (caught) {
    if (supplier.currentSupplierId !== supplierId || token.value !== uploadToken) return
    error.value = message(caught)
    toast.error(error.value)
  } finally {
    busy.value = false
  }
}

async function removeUpload(upload: StereoUpload): Promise<void> {
  if (busy.value) return
  const supplierId = supplier.currentSupplierId
  busy.value = true
  error.value = ''
  try {
    await stereoNxApi.remove(upload.token)
    if (supplier.currentSupplierId !== supplierId) return
    if (token.value === upload.token) {
      const successfulReport = importReport.value?.ok ? importReport.value : null
      const successfulCompany = reportCompany.value
      resetSelection()
      importReport.value = successfulReport
      reportCompany.value = successfulCompany
      if (successfulReport) currentStep.value = 4
    }
    cleanupWarning.value = ''
    await refreshUploads()
  } catch (caught) {
    if (supplier.currentSupplierId !== supplierId) return
    error.value = message(caught)
    toast.error(error.value)
  } finally {
    busy.value = false
  }
}

function reviewLabel(key: string): string {
  const path = `stereo_nx.review_reasons.${key}`
  return te(path) ? t(path) : t('stereo_nx.review_details')
}

function reportMessage(item: unknown): string {
  if (typeof item === 'string') return item
  if (item && typeof item === 'object' && 'message' in item && typeof item.message === 'string') return item.message
  return t('stereo_nx.review_details')
}

function asProtocol(report: StereoReport, mode: 'dry_run' | 'import'): StereoProtocolRun {
  const messages: MoneyS3Step['messages'] = []
  const seen = new Set<string>()
  const addMessage = (item: unknown, fallback: 'error' | 'warning' | 'info'): void => {
    const message = reportMessage(item)
    const level = typeof item === 'object' && item && 'level' in item && item.level === 'error' ? 'error'
      : typeof item === 'object' && item && 'level' in item && item.level === 'warning' ? 'warning' : fallback
    const key = `${level}:${message}`
    if (seen.has(key)) return
    seen.add(key)
    messages.push({ level, code: key, text: message, context: {} })
  }
  for (const item of report.preflight ?? []) addMessage(item, 'info')
  for (const item of report.errors ?? []) addMessage(item, 'error')
  for (const item of report.warnings ?? []) addMessage(item, 'warning')
  const reviewMessages: MoneyS3Step['messages'] = Object.entries(report.review_reasons ?? {}).map(([code, count]) => ({
    level: 'warning', code, text: t('stereo_nx.review_count', { reason: reviewLabel(code), count }), context: {},
  }))
  for (const document of report.review_documents ?? []) {
    reviewMessages.push({
      level: 'warning', code: document.source_key,
      text: t('stereo_nx.review_document', {
        kind: t(`stereo_nx.document_kind.${document.kind}`),
        number: document.document_no || t('stereo_nx.unnumbered_document'),
        reasons: document.review_codes.map(reviewLabel).join(', '),
      }), context: {},
    })
  }
  const steps: MoneyS3Step[] = [
    { key: 'source_summary', status: report.ok ? 'ok' : 'error', counts: report.counts ?? {}, messages },
    { key: 'review', status: reviewMessages.length ? 'warning' : 'ok', counts: { requires_draft: report.counts?.requires_draft ?? 0 }, messages: reviewMessages },
  ]
  if (report.written) steps.push({ key: mode === 'dry_run' ? 'would_write' : 'written', status: report.ok ? 'ok' : 'error', counts: report.written, messages: [] })
  const status = !report.ok ? 'failed' : messages.some(item => item.level === 'warning') || reviewMessages.length ? 'completed_with_warnings' : 'completed'
  return {
    id: null, mode, status,
    agenda_name: reportCompany.value?.name ?? null,
    agenda_ico: reportCompany.value?.ico ?? null,
    agenda_year: null, created_at: null,
    protocol: { mode, status, failure: null, steps },
  }
}

const dryProtocol = computed(() => dryReport.value ? asProtocol(dryReport.value, 'dry_run') : null)
const importProtocol = computed(() => importReport.value ? asProtocol(importReport.value, 'import') : null)
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('stereo_nx.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('stereo_nx.intro') }}</p>
    </div>

    <ol class="grid grid-cols-2 gap-2 sm:grid-cols-4">
      <li v-for="step in steps" :key="step.number">
        <button type="button" :data-testid="`stereo-step-${step.number}`" :disabled="!canGoTo(step.number)"
          class="flex w-full items-center rounded-lg border px-3 py-3 text-left text-sm transition-colors"
          :class="[step.number === currentStep ? 'border-primary-500 bg-primary-50 text-primary-700' : step.number < currentStep ? 'border-success-500/40 bg-success-50 text-success-600' : 'border-neutral-200 text-neutral-400', canGoTo(step.number) ? 'cursor-pointer hover:border-primary-400 hover:bg-primary-50' : 'cursor-default']"
          @click="goTo(step.number)">
          <span class="mr-2 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold">{{ step.number < currentStep ? '✓' : step.number }}</span>{{ step.label }}
        </button>
      </li>
    </ol>

    <section v-if="currentStep === 1" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <h2 class="mb-1 text-lg font-semibold">{{ t('stereo_nx.upload_title') }}</h2>
      <p class="mb-4 text-sm text-neutral-500">{{ t('stereo_nx.upload_hint') }}</p>
      <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm">
        <h3 class="mb-2 font-medium text-neutral-700">{{ t('stereo_nx.upload_help_title') }}</h3>
        <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
          <li v-for="(item, index) in backupHelpItems" :key="index">{{ item }}</li>
        </ol>
      </div>
      <label class="block max-w-xl text-sm font-medium" for="stereo-file">
        {{ t('stereo_nx.file') }}
        <input id="stereo-file" ref="fileInput" data-testid="stereo-upload-input" type="file" accept=".zip" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" :disabled="busy" @change="onFile" />
      </label>
      <div v-if="progress !== null || (busy && token)" class="mt-4 max-w-xl space-y-2 rounded-md border border-primary-200 bg-primary-50/50 px-3 py-3" role="status">
        <div class="text-sm font-medium text-primary-700">{{ progress !== null ? t('stereo_nx.progress', { percent: progress }) : t('stereo_nx.working') }}</div>
        <div class="h-2 overflow-hidden rounded-full bg-primary-100">
          <div class="h-full bg-primary-500 transition-all duration-300" :class="progress === null ? 'w-1/3 animate-pulse' : ''" :style="progress === null ? undefined : { width: progress + '%' }"></div>
        </div>
      </div>
      <div class="mt-5 border-t border-neutral-200 pt-4">
        <h3 class="font-medium">{{ t('stereo_nx.existing_uploads') }}</h3>
        <p v-if="loadingUploads" class="mt-2 text-sm text-neutral-600" role="status">{{ t('stereo_nx.loading_uploads') }}</p>
        <p v-else-if="!uploads.length" class="mt-2 text-sm text-neutral-600">{{ t('stereo_nx.no_uploads') }}</p>
        <ul v-else class="mt-3 space-y-3">
          <li v-for="item in uploads" :key="item.token" class="rounded-md border border-neutral-200 p-3">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
              <span class="min-w-0 break-all font-medium">{{ item.filename }}</span>
              <span class="text-xs text-neutral-600">{{ uploadSize(item.size) }} · {{ uploadDate(item.created_at) }}</span>
              <span class="rounded-full px-2 py-0.5 text-xs" :class="item.complete ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700'">{{ t(item.complete ? 'stereo_nx.upload_ready' : 'stereo_nx.upload_incomplete') }}</span>
            </div>
            <p v-if="!item.complete" class="mt-2 text-xs text-neutral-600">{{ t('stereo_nx.upload_received', { received: uploadSize(item.received), size: uploadSize(item.size) }) }} {{ t('stereo_nx.upload_incomplete_help') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
              <button v-if="item.complete" type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :disabled="busy" @click="openUpload(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t('stereo_nx.open_upload') }}</button>
              <button type="button" :class="btnOutline('danger')" class="whitespace-nowrap" :data-testid="`stereo-upload-remove-${item.token}`" :disabled="busy" @click="removeUpload(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('stereo_nx.remove_upload') }}</button>
            </div>
          </li>
        </ul>
      </div>
    </section>

    <section v-else-if="currentStep === 2" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <h2 class="text-lg font-semibold">{{ t('stereo_nx.company_title') }}</h2>
      <label class="mt-3 block text-sm font-medium" for="stereo-company">{{ t('stereo_nx.company') }}</label>
      <select id="stereo-company" v-model.number="selected" data-testid="stereo-company-select" class="mt-1 w-full max-w-xl rounded-md border border-neutral-300 p-2 text-sm" :disabled="busy" @change="invalidateDryRun">
        <option :value="null">{{ t('stereo_nx.choose_company') }}</option>
        <option v-for="item in companies" :key="item.index" :value="item.index">{{ item.identity.name }} ({{ item.identity.ico }})</option>
      </select>
      <p v-if="selectedCompany && !selectedCompany.matches_target" class="mt-3 text-sm text-danger-600">{{ t('stereo_nx.ico_mismatch') }}</p>
      <p v-if="selectedCompany && !selectedCompany.identity.vat_payer" class="mt-3 text-sm text-danger-600">{{ t('stereo_nx.vat_required') }}</p>
      <p v-if="target && target.accounting_mode !== 'tax_evidence'" class="mt-3 text-sm text-danger-600">{{ t('stereo_nx.mode_required') }}</p>
      <p v-if="target && !target.vat_payer" class="mt-3 text-sm text-danger-600">{{ t('stereo_nx.target_vat_required') }}</p>
      <label class="mt-4 flex items-start gap-2 text-sm"><input v-model="blankCountryIsCz" type="checkbox" class="mt-1" :disabled="busy" @change="invalidateDryRun" />{{ t('stereo_nx.blank_country_is_cz') }}</label>
    </section>

    <section v-else-if="currentStep === 3" data-testid="stereo-dry-report" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <h2 class="mb-1 text-lg font-semibold">{{ t('stereo_nx.dry_result') }}</h2>
      <p class="mb-4 text-sm text-neutral-500">{{ t('stereo_nx.dry_hint') }}</p>
      <ImportJobProgress v-if="busy && job" :job="job" :percent="null" :cancelling="false" :show-cancel="false"
        counts-key="stereo_nx.job_counts" background-hint-key="stereo_nx.background_hint" running-key="stereo_nx.dry_run_running" />
      <p v-else-if="busy" class="text-sm text-primary-700" role="status">{{ t('stereo_nx.working') }}</p>
      <template v-if="dryReport">
        <p v-if="dryReport.date_bounds" class="mb-4 text-sm text-neutral-600">{{ t('stereo_nx.date_bounds', dryReport.date_bounds) }}</p>
        <MoneyS3Protocol v-if="dryProtocol" :run="dryProtocol" prefix="stereo_nx" />
      </template>
    </section>

    <section v-else data-testid="stereo-import-report" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <h2 class="mb-1 text-lg font-semibold">{{ t('stereo_nx.import_result') }}</h2>
      <ImportJobProgress v-if="busy && job" class="mb-4" :job="job" :percent="null" :cancelling="false" :show-cancel="false"
        counts-key="stereo_nx.job_counts" background-hint-key="stereo_nx.background_hint" running-key="stereo_nx.import_running" />
      <template v-if="importReport">
        <p v-if="importReport.date_bounds" class="mb-4 text-sm text-neutral-600">{{ t('stereo_nx.date_bounds', importReport.date_bounds) }}</p>
        <MoneyS3Protocol v-if="importProtocol" :run="importProtocol" prefix="stereo_nx" />
        <p v-if="cleanupWarning" class="mt-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" role="alert">{{ cleanupWarning }}</p>
      </template>
      <template v-else>
        <p class="mb-4 text-sm text-neutral-500">{{ t('stereo_nx.import_hint') }}</p>
        <p v-if="dryReport?.date_bounds" class="mb-4 text-sm text-neutral-600">{{ t('stereo_nx.date_bounds', dryReport.date_bounds) }}</p>
        <MoneyS3Protocol v-if="dryProtocol" :run="dryProtocol" prefix="stereo_nx" />
        <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-700"><input v-model="confirmed" data-testid="stereo-import-confirm" type="checkbox" class="mt-1" :disabled="busy" />{{ t('stereo_nx.confirm') }}</label>
        <label class="mt-3 flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-4 text-sm"><input v-model="deleteAfterImport" data-testid="stereo-delete-after-import" type="checkbox" class="mt-1" :disabled="busy" />{{ t('stereo_nx.delete_after_import') }}</label>
      </template>
    </section>
    <div data-testid="stereo-actions" class="flex flex-wrap justify-end"><ActionBar :actions="actions" /></div>
    <p v-if="error" class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-600">{{ error }}</p>
  </div>
</template>
