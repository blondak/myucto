<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isPohodaUploadReady, pohodaApi, type PohodaKind, type PohodaRun, type PohodaToolFile, type PohodaUpload } from '@/api/pohoda'
import { cancelImportJob, fetchImportJob, type FileImportJob } from '@/api/imports'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import type { PermissionKey } from '@/security/permissions'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'
import { formatBytes } from '@/components/documents/docFormat'

/**
 * Průvodce „Přechod z POHODA": XML export agendy → náhled a volba roku → zkouška
 * nanečisto → ostrý převod. Nahraný export zůstává na serveru pod tokenem, takže obnovení
 * stránky průvodce nevrátí na začátek (token drží sessionStorage).
 */
const TOKEN_KEY = 'myucto.pohoda.token'

const { t, te, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()

function list(key: string): string[] {
  return (tm(key) as unknown[]).map(item => rt(item as Parameters<typeof rt>[0]))
}
const exportHelpItems = computed(() => list('pohoda.export_help_items'))
const fileHelpItems = computed(() => list('pohoda.file_help_items'))

const currentStep = ref(1)
const upload = ref<PohodaUpload | null>(null)
const file = ref<File | null>(null)
const year = ref<number | null>(null)
// Účetnictví roku, nebo mzdy (vlastní akce, i pro export jen se mzdami).
const kind = ref<PohodaKind>('accounting')
const job = ref<FileImportJob | null>(null)
const jobMode = ref<'dry_run' | 'import' | null>(null)
const run = ref<PohodaRun | null>(null)
const runs = ref<PohodaRun[]>([])
const busy = ref(false)
const cancelling = ref(false)
const confirmed = ref(false)
// Mzdy: potvrzení, že OIČ a ID PPV v PAMICA pocházejí z protokolů ČSSZ. Bez něj je převod nepřevezme.
const confirmIdentifiers = ref(false)
const dryRunPassed = ref(false)
const uploadPercent = ref<number | null>(null)
const processing = ref(false)
const toolOpen = ref(false)
const toolFiles = ref<PohodaToolFile[] | null>(null)
const toolLoading = ref(false)
const toolDownloading = ref<string | null>(null)
let pollTimer: ReturnType<typeof setTimeout> | null = null
let disposed = false

function readToken(): string | null {
  try { return sessionStorage.getItem(TOKEN_KEY) } catch { return null }
}
function writeToken(token: string | null): void {
  try {
    if (token) sessionStorage.setItem(TOKEN_KEY, token)
    else sessionStorage.removeItem(TOKEN_KEY)
  } catch { /* prohlížeč bez úložiště: průvodce jen nepřežije obnovení stránky */ }
}

function errorMessage(error: any, fallback: string): string {
  const message = String(error?.response?.data?.error?.message ?? '').trim()
  return message || fallback
}

const steps = computed(() => [1, 2, 3, 4].map(number => ({ number, label: t(`pohoda.step${number}`) })))
const agendas = computed(() => upload.value?.agendas ?? [])
// Převést jde jen rok agendy s IČO firmy; ostatní agendy exportu jsou jen informace.
const years = computed(() => [...new Set(agendas.value.filter(a => a.ico === upload.value?.supplier_ico).map(a => a.year))].sort((a, b) => b - a))
const selectedAgenda = computed(() => agendas.value.find(a => a.ico === upload.value?.supplier_ico && a.year === year.value) ?? null)
const noteFiles = computed(() => (selectedAgenda.value?.files ?? []).filter(f => f.state !== 'ok'))
// Přehled nahraný před podporou mezd příznaky nemá: to byla vždy agenda účetnictví.
const hasAccounting = computed(() => selectedAgenda.value?.has_accounting ?? true)
const hasPayroll = computed(() => selectedAgenda.value?.has_payroll ?? false)
const kinds = computed<PohodaKind[]>(() => [
  ...(hasAccounting.value ? ['accounting' as const] : []),
  ...(hasPayroll.value ? ['payroll' as const] : []),
])
const preflight = computed(() => {
  if (year.value === null) return []
  const source = kind.value === 'payroll' ? upload.value?.payroll_preflight : upload.value?.preflight
  return source?.[String(year.value)] ?? []
})
const preflightErrors = computed(() => preflight.value.filter(m => m.level === 'error'))
// Stejná práva jako na backendu: mzdy zakládají osoby a vstupy už ve zkoušce nanečisto,
// ostrý převod účetnictví zapisuje deník a nastavení firmy.
const missingRights = computed(() => {
  const required: PermissionKey[] = kind.value === 'payroll'
    ? ['payroll.inputs.write', 'payroll.person.write', 'payroll.settings']
    : ['accounting.journal.write', 'settings.company.write']
  return required.filter(key => !auth.canWrite(key))
})
const rightsMessage = computed(() => t(kind.value === 'payroll' ? 'pohoda.payroll_rights_missing' : 'pohoda.rights_missing', { rights: missingRights.value.join(', ') }))
const payrollRightsBlocked = computed(() => kind.value === 'payroll' && missingRights.value.length > 0)
const blocked = computed(() => year.value === null || preflightErrors.value.length > 0 || payrollRightsBlocked.value)
const jobRunning = computed(() => job.value?.status === 'queued' || job.value?.status === 'running')
// Tlačítko je zakázané i během běžící úlohy; důvodem pak není kontrola před převodem.
const blockedReason = computed(() => jobRunning.value && !blocked.value
  ? t(jobMode.value === 'import' ? 'pohoda.import_running' : 'pohoda.dry_run_running')
  : year.value === null ? t('pohoda.choose_year_first')
    : payrollRightsBlocked.value ? rightsMessage.value : t('pohoda.preflight_blocked'))
const percent = computed(() => {
  if (jobMode.value !== 'import' || !job.value?.total_items) return null
  return Math.min(100, Math.round(job.value.processed / job.value.total_items * 100))
})
const importDone = computed(() => jobMode.value === 'import' && !jobRunning.value && run.value?.mode === 'import'
  && (run.value.status === 'completed' || run.value.status === 'completed_with_warnings'))

watch(year, () => {
  dryRunPassed.value = false
  confirmed.value = false
})
watch(kind, () => {
  dryRunPassed.value = false
  confirmed.value = false
})
// Agenda jen s účetnictvím nebo jen se mzdami: druh převodu se zvolí sám.
watch(kinds, available => {
  if (available.length && !available.includes(kind.value)) kind.value = available[0]
}, { immediate: true })

function fileState(state: string): string {
  const key = `pohoda.file_state.${state}`
  return te(key) ? t(key) : state
}

function canGoTo(step: number): boolean {
  if (busy.value || jobRunning.value || step === currentStep.value) return false
  if (step === 1) return true
  if (step === 2 || step === 3) return upload.value !== null
  return dryRunPassed.value && upload.value !== null
}

function goTo(step: number): void {
  if (canGoTo(step)) currentStep.value = step
}

function onFile(event: Event): void {
  const input = event.target as HTMLInputElement
  file.value = input.files?.[0] ?? null
}

async function toggleTool(): Promise<void> {
  toolOpen.value = !toolOpen.value
  if (!toolOpen.value || toolFiles.value !== null) return
  toolLoading.value = true
  try {
    toolFiles.value = (await pohodaApi.toolFiles()).files
  } catch (error: any) {
    toolOpen.value = false
    toast.error(errorMessage(error, t('pohoda.tool_failed')))
  } finally {
    toolLoading.value = false
  }
}

async function downloadTool(name: string | null): Promise<void> {
  toolDownloading.value = name ?? '*'
  try {
    if (name === null) await pohodaApi.downloadTool()
    else await pohodaApi.downloadToolFile(name)
  } catch (error: any) {
    toast.error(errorMessage(error, t('pohoda.tool_failed')))
  } finally {
    toolDownloading.value = null
  }
}

async function doUpload(): Promise<void> {
  if (!file.value) return
  busy.value = true
  uploadPercent.value = 0
  try {
    const { token } = await pohodaApi.uploadChunked(
      file.value,
      (sent, total) => { uploadPercent.value = total > 0 ? Math.floor(sent / total * 100) : 100 },
      started => writeToken(started),
    )
    uploadPercent.value = null
    await waitForUpload(token)
  } catch (error: any) {
    writeToken(null)
    toast.error(errorMessage(error, t('pohoda.upload_failed')))
  } finally {
    uploadPercent.value = null
    busy.value = false
  }
}

/** Polluje stav nahraného exportu, dokud ho server nerozbalí a nenačte (nebo nenahlásí chybu). */
async function waitForUpload(token: string): Promise<void> {
  processing.value = true
  try {
    while (!disposed) {
      const result = await pohodaApi.show(token)
      if (isPohodaUploadReady(result)) {
        upload.value = result
        year.value = result.default_year !== null && years.value.includes(result.default_year) ? result.default_year : (years.value[0] ?? null)
        dryRunPassed.value = false
        confirmed.value = false
        run.value = null
        currentStep.value = 2
        return
      }
      if (result.status !== 'processing') {
        // 'uploading' po obnovení stránky: soubor v prohlížeči už není, nahrávání nejde dokončit.
        writeToken(null)
        toast.error(result.status === 'failed' ? (result.error || t('pohoda.upload_failed')) : t('pohoda.upload_interrupted'))
        return
      }
      await new Promise<void>(resolve => { pollTimer = setTimeout(resolve, 2000) })
    }
  } finally {
    processing.value = false
  }
}

function resetUpload(): void {
  upload.value = null
  file.value = null
  year.value = null
  run.value = null
  dryRunPassed.value = false
  confirmed.value = false
  writeToken(null)
  currentStep.value = 1
}

async function start(mode: 'dry_run' | 'import'): Promise<void> {
  if (!upload.value || year.value === null) return
  busy.value = true
  try {
    const started = await pohodaApi.start(upload.value.token, {
      mode,
      year: year.value,
      kind: kind.value,
      ...(kind.value === 'payroll' ? { confirm_identifiers: confirmIdentifiers.value } : {}),
    })
    jobMode.value = mode
    run.value = null
    currentStep.value = mode === 'dry_run' ? 3 : 4
    await pollJob(started.job_id)
  } catch (error: any) {
    toast.error(errorMessage(error, t('pohoda.start_failed')))
  } finally {
    busy.value = false
  }
}

async function pollJob(id: number): Promise<void> {
  job.value = await fetchImportJob(id)
  if (jobRunning.value) {
    schedulePoll(id)
    return
  }
  await loadRuns()
  const finished = runs.value.find(r => r.job_id === id)
  run.value = finished ? await pohodaApi.run(finished.id) : null
  // Po obnovení stránky se druh běžícího převodu pozná až z protokolu.
  if (run.value?.protocol?.kind) kind.value = run.value.protocol.kind
  const ok = run.value?.status === 'completed' || run.value?.status === 'completed_with_warnings'
  if (jobMode.value === 'dry_run') {
    dryRunPassed.value = ok
  } else if (jobMode.value === 'import' && ok) {
    // Server nahraný export po úspěšném převodu smazal.
    writeToken(null)
  }
}

function schedulePoll(id: number): void {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = setTimeout(() => { void pollJob(id) }, 2000)
}

async function cancel(): Promise<void> {
  if (!job.value) return
  cancelling.value = true
  try {
    await cancelImportJob(job.value.id)
  } catch (error: any) {
    toast.error(errorMessage(error, t('common.error')))
  } finally {
    cancelling.value = false
  }
}

async function loadRuns(): Promise<void> {
  runs.value = (await pohodaApi.runs()).items
}

async function showRun(item: PohodaRun): Promise<void> {
  try {
    run.value = await pohodaApi.run(item.id)
  } catch (error: any) {
    toast.error(errorMessage(error, t('common.error')))
  }
}

async function load(): Promise<void> {
  busy.value = true
  try {
    await loadRuns()
    const token = readToken()
    if (token) {
      try {
        await waitForUpload(token)
      } catch {
        writeToken(null)
      }
    }
    const active = runs.value.find(r => r.status === 'running' && r.job_id !== null)
    if (active?.job_id) {
      jobMode.value = active.mode
      currentStep.value = active.mode === 'dry_run' ? 3 : 4
      await pollJob(active.job_id)
    }
  } catch (error: any) {
    toast.error(errorMessage(error, t('common.error')))
  } finally {
    busy.value = false
  }
}

const actions = computed<ActionItem[]>(() => {
  if (currentStep.value === 1) return [
    { key: 'upload', label: busy.value ? (uploadPercent.value !== null ? t('pohoda.uploading_percent', { percent: uploadPercent.value }) : t('pohoda.uploading')) : t('pohoda.upload'), icon: 'upload', tier: 'primary', variant: 'primary', disabled: !file.value, disabledReason: t('pohoda.choose_file_first'), loading: busy.value, run: doUpload },
  ]
  if (currentStep.value === 2) return [
    { key: 'continue', label: t('pohoda.continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: blocked.value, disabledReason: blockedReason.value, run: () => { currentStep.value = 3 } },
    { key: 'new', label: t('pohoda.new_upload'), icon: 'x', tier: 'secondary', variant: 'neutral', run: resetUpload },
  ]
  if (currentStep.value === 3) return dryRunPassed.value && !jobRunning.value
    ? [
        { key: 'next', label: t('pohoda.continue'), icon: 'check', tier: 'primary', variant: 'primary', run: () => { currentStep.value = 4 } },
        { key: 'rerun', label: t('pohoda.dry_run_again'), icon: 'cycle', tier: 'secondary', variant: 'neutral', run: () => { void start('dry_run') } },
      ]
    : [
        { key: 'dry', label: t('pohoda.dry_run_start'), icon: 'play', tier: 'primary', variant: 'primary', disabled: blocked.value || jobRunning.value, disabledReason: blockedReason.value, loading: busy.value, run: () => { void start('dry_run') } },
      ]
  if (importDone.value) return kind.value === 'payroll'
    ? [{ key: 'payroll', label: t('pohoda.open_payroll'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'payroll-imports' } }]
    : [
        { key: 'journal', label: t('pohoda.open_journal'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'accounting-journal' } },
        { key: 'trial', label: t('pohoda.open_trial_balance'), icon: 'chart', tier: 'secondary', variant: 'neutral', to: { name: 'accounting-trial-balance' } },
      ]
  const importReason = missingRights.value.length
    ? rightsMessage.value
    : blocked.value ? blockedReason.value : t('pohoda.import_confirm_first')
  return [
    { key: 'import', label: t('pohoda.import_start'), icon: 'play', tier: 'primary', variant: 'warning', disabled: !confirmed.value || jobRunning.value || blocked.value || missingRights.value.length > 0, disabledReason: importReason, loading: busy.value, run: () => { void start('import') } },
  ]
})

onMounted(load)
onBeforeUnmount(() => {
  disposed = true
  if (pollTimer) clearTimeout(pollTimer)
})
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('pohoda.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('pohoda.subtitle') }}</p>
    </div>

    <div class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-testid="pohoda-support-notice">
      <p>{{ t('pohoda.support_notice') }}</p>
      <RouterLink to="/admin/support" class="mt-1 inline-block font-medium underline hover:no-underline">{{ t('pohoda.support_notice_link') }}</RouterLink>
    </div>

    <ol class="grid grid-cols-2 gap-2 sm:grid-cols-4">
      <li v-for="step in steps" :key="step.number">
        <button type="button" :disabled="!canGoTo(step.number)" class="flex w-full items-center rounded-lg border px-3 py-3 text-left text-sm transition-colors"
          :class="[step.number === currentStep ? 'border-primary-500 bg-primary-50 text-primary-700' : step.number < currentStep ? 'border-success-500/40 bg-success-50 text-success-600' : 'border-neutral-200 text-neutral-400', canGoTo(step.number) ? 'cursor-pointer hover:border-primary-400 hover:bg-primary-50' : 'cursor-default']"
          @click="goTo(step.number)">
          <span class="mr-2 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold">{{ step.number < currentStep ? '✓' : step.number }}</span>{{ step.label }}
        </button>
      </li>
    </ol>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <div v-if="busy && !upload && currentStep === 1 && !file && !processing" class="py-12 text-center text-neutral-400">{{ t('common.loading') }}</div>

      <template v-else-if="currentStep === 1">
        <h2 class="mb-1 text-lg font-semibold">{{ t('pohoda.upload_title') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('pohoda.upload_hint') }}</p>

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="pohoda-export-help">
          <h3 class="mb-2 font-medium text-neutral-700">{{ t('pohoda.export_help_title') }}</h3>
          <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
            <li v-for="(item, i) in exportHelpItems" :key="i">{{ item }}</li>
          </ol>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" :class="btnOutline('neutral')" :aria-expanded="toolOpen" data-testid="pohoda-tool-toggle" @click="toggleTool">
              <svg class="h-4 w-4 transition-transform" :class="toolOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
              {{ toolOpen ? t('pohoda.tool_hide') : t('pohoda.tool_show') }}
            </button>
          </div>
          <div v-if="toolOpen" class="mt-3 rounded-md border border-neutral-200 bg-surface px-3 py-3" data-testid="pohoda-tool">
            <p class="mb-2 text-neutral-600">{{ t('pohoda.tool_hint') }}</p>
            <p v-if="toolLoading" class="text-neutral-400">{{ t('pohoda.tool_loading') }}</p>
            <template v-else-if="toolFiles">
              <p v-if="!toolFiles.length" class="text-neutral-500">{{ t('pohoda.tool_empty') }}</p>
              <ul v-else class="mb-3 divide-y divide-neutral-100">
                <li v-for="f in toolFiles" :key="f.name" class="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span><span class="font-mono">{{ f.name }}</span><span class="ml-2 text-xs text-neutral-500">{{ formatBytes(f.size) }}</span></span>
                  <button type="button" :class="btnOutlineSm('neutral')" :disabled="toolDownloading !== null" @click="downloadTool(f.name)">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                    {{ t('pohoda.tool_download') }}
                  </button>
                </li>
              </ul>
              <div v-if="toolFiles.length" class="flex flex-wrap gap-2">
                <button type="button" :class="btnOutline('primary')" :disabled="toolDownloading !== null" data-testid="pohoda-tool-zip" @click="downloadTool(null)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                  {{ t('pohoda.tool_download_all') }}
                </button>
              </div>
            </template>
          </div>
        </div>

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="pohoda-file-help">
          <h3 class="mb-2 font-medium text-neutral-700">{{ t('pohoda.file_help_title') }}</h3>
          <ul class="list-disc space-y-1 pl-5 text-neutral-600">
            <li v-for="(item, i) in fileHelpItems" :key="i">{{ item }}</li>
          </ul>
        </div>

        <label class="block max-w-xl text-sm font-medium">
          {{ t('pohoda.choose_file') }}
          <input type="file" accept=".zip" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" data-testid="pohoda-input" :disabled="busy" @change="onFile" />
        </label>
        <div v-if="uploadPercent !== null || processing" class="mt-4 max-w-xl space-y-2 rounded-md border border-primary-200 bg-primary-50/50 px-3 py-3" data-testid="pohoda-progress">
          <div class="text-sm font-medium text-primary-700">
            {{ processing ? t('pohoda.processing') : t('pohoda.uploading_percent', { percent: uploadPercent ?? 0 }) }}
          </div>
          <div class="h-2 overflow-hidden rounded-full bg-primary-100">
            <div class="h-full bg-primary-500 transition-all duration-300"
              :class="processing ? 'w-1/3 animate-pulse' : ''"
              :style="processing ? undefined : { width: (uploadPercent ?? 0) + '%' }"></div>
          </div>
          <p class="text-xs text-neutral-500">{{ processing ? t('pohoda.processing_hint') : t('pohoda.uploading_hint') }}</p>
        </div>
      </template>

      <template v-else-if="currentStep === 2 && upload">
        <h2 class="mb-4 text-lg font-semibold">{{ t('pohoda.step2') }}</h2>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('pohoda.agendas_title') }}</h3>
        <div class="mb-2 overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
              <tr>
                <th class="px-3 py-2">{{ t('pohoda.col_ico') }}</th>
                <th class="px-3 py-2">{{ t('pohoda.col_company') }}</th>
                <th class="px-3 py-2">{{ t('pohoda.col_year') }}</th>
                <th class="px-3 py-2 text-right">{{ t('pohoda.col_journal') }}</th>
                <th class="px-3 py-2 text-right">{{ t('pohoda.col_opening') }}</th>
                <th class="px-3 py-2">{{ t('pohoda.col_range') }}</th>
                <th class="px-3 py-2 text-right">{{ t('pohoda.col_issued') }}</th>
                <th class="px-3 py-2 text-right">{{ t('pohoda.col_purchase') }}</th>
                <th class="px-3 py-2 text-right">{{ t('pohoda.col_internal') }}</th>
                <th class="px-3 py-2 text-right">{{ t('pohoda.col_cash') }}</th>
                <th class="px-3 py-2 text-right">{{ t('pohoda.col_bank') }}</th>
                <th class="px-3 py-2 text-right">{{ t('pohoda.col_partners') }}</th>
                <th class="px-3 py-2 whitespace-nowrap">{{ t('pohoda.col_payroll') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in agendas" :key="`${a.ico}-${a.year}`" :class="a.ico === upload.supplier_ico && a.year === year ? 'bg-primary-50/60' : ''">
                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ a.ico || '—' }}</td>
                <td class="px-3 py-2">
                  {{ a.company || '—' }}
                  <span v-if="a.ico !== upload.supplier_ico" class="ml-2 rounded-full bg-neutral-100 px-2 py-0.5 text-xs whitespace-nowrap text-neutral-600">{{ t('pohoda.other_company') }}</span>
                  <span v-if="a.has_accounting === false" class="ml-2 rounded-full bg-primary-50 px-2 py-0.5 text-xs whitespace-nowrap text-primary-700" data-testid="pohoda-payroll-only">{{ t('pohoda.payroll_only') }}</span>
                </td>
                <td class="px-3 py-2 font-medium">{{ a.year }}</td>
                <td class="px-3 py-2 text-right">{{ a.counts.journal }}</td>
                <td class="px-3 py-2 text-right">{{ a.counts.opening }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ a.counts.first_date ?? '—' }} – {{ a.counts.last_date ?? '—' }}</td>
                <td class="px-3 py-2 text-right">{{ a.counts.issued }}</td>
                <td class="px-3 py-2 text-right">{{ a.counts.purchase }}</td>
                <td class="px-3 py-2 text-right">{{ a.counts.internal }}</td>
                <td class="px-3 py-2 text-right">{{ a.counts.cash }}</td>
                <td class="px-3 py-2 text-right">{{ a.counts.bank }}</td>
                <td class="px-3 py-2 text-right">{{ a.counts.partners }}</td>
                <td class="px-3 py-2 whitespace-nowrap" data-testid="pohoda-payroll-cell">{{ a.payroll ? t('pohoda.payroll_cell', { employees: a.payroll.employees, months: a.payroll.months }) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mb-5 text-sm text-neutral-500">{{ t('pohoda.supplier_ico', { ico: upload.supplier_ico || '—' }) }}</p>

        <p v-if="!years.length" class="mb-5 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="pohoda-no-agenda">
          {{ upload.supplier_ico ? t('pohoda.no_matching_agenda', { ico: upload.supplier_ico }) : t('pohoda.supplier_ico_missing') }}
        </p>
        <template v-else>
          <label class="mb-1 block max-w-xs text-sm font-medium">{{ t('pohoda.year') }}
            <select v-model="year" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm" data-testid="pohoda-year">
              <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
          </label>
          <p class="mb-5 text-sm text-neutral-500">{{ t('pohoda.year_hint') }}</p>

          <fieldset v-if="kinds.length > 1" class="mb-5" data-testid="pohoda-kind">
            <legend class="mb-1 text-sm font-medium">{{ t('pohoda.kind_title') }}</legend>
            <div class="flex flex-wrap gap-4">
              <label v-for="k in kinds" :key="k" class="flex cursor-pointer items-center gap-2 text-sm">
                <input v-model="kind" type="radio" :value="k" class="text-primary-600" :data-testid="`pohoda-kind-${k}`" />
                {{ t(`pohoda.kind.${k}`) }}
              </label>
            </div>
          </fieldset>
          <p v-else-if="!hasAccounting" class="mb-5 rounded-lg border border-primary-500/30 bg-primary-50 px-3 py-2 text-sm text-primary-700" data-testid="pohoda-payroll-only-hint">{{ t('pohoda.payroll_only_hint') }}</p>

          <label v-if="kind === 'payroll'" class="mb-5 flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3">
            <input v-model="confirmIdentifiers" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-confirm-identifiers" />
            <span class="text-sm text-neutral-700">{{ t('pohoda.payroll_identifiers_confirm') }}</span>
          </label>

          <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('pohoda.preflight_title', { year: year ?? '' }) }}</h3>
          <ul v-if="preflight.length" class="mb-5 space-y-2">
            <li v-for="m in preflight" :key="m.code + m.message" class="rounded-lg border px-3 py-2 text-sm"
              :class="m.level === 'error' ? 'border-danger-500/30 bg-danger-50 text-danger-600' : m.level === 'warning' ? 'border-warning-500/30 bg-warning-50 text-warning-700' : 'border-primary-500/30 bg-primary-50 text-primary-700'">
              {{ m.message }}
            </li>
          </ul>
          <p v-else class="mb-5 rounded-lg border border-success-500/30 bg-success-50 px-3 py-2 text-sm text-success-600">{{ t('pohoda.preflight_ok') }}</p>

          <details v-if="noteFiles.length" class="rounded-lg border border-neutral-200 px-4 py-3 text-sm" data-testid="pohoda-file-notes">
            <summary class="cursor-pointer font-medium text-neutral-700">{{ t('pohoda.files_title', { n: noteFiles.length }) }}</summary>
            <p class="mt-2 text-neutral-500">{{ t('pohoda.files_hint') }}</p>
            <ul class="mt-2 divide-y divide-neutral-100">
              <li v-for="f in noteFiles" :key="f.file" class="flex flex-wrap items-center gap-2 py-1.5">
                <span class="font-mono">{{ f.file }}</span>
                <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs whitespace-nowrap text-neutral-600">{{ fileState(f.state) }}</span>
                <span v-if="f.note" class="text-neutral-500">{{ f.note }}</span>
              </li>
            </ul>
          </details>
        </template>
      </template>

      <template v-else-if="currentStep === 3">
        <h2 class="mb-1 text-lg font-semibold">{{ t('pohoda.dry_run_title') }}</h2>
        <p class="mb-2 text-sm text-neutral-500">{{ t(kind === 'payroll' ? 'pohoda.payroll_dry_run_hint' : 'pohoda.dry_run_hint', { year: year ?? '' }) }}</p>
        <p class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t('pohoda.dry_run_locks_hint') }}</p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="null" :cancelling="false" :show-cancel="false"
          counts-key="pohoda.job_counts" background-hint-key="pohoda.background_hint" running-key="pohoda.dry_run_running" />
        <MoneyS3Protocol v-if="run && run.mode === 'dry_run'" :run="run" prefix="pohoda" />
      </template>

      <template v-else>
        <h2 class="mb-1 text-lg font-semibold">{{ t('pohoda.import_title') }}</h2>
        <label v-if="!importDone && !jobRunning" class="my-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
          <input v-model="confirmed" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-confirm" />
          <span class="text-sm text-warning-700">{{ t(kind === 'payroll' ? 'pohoda.payroll_import_confirm' : 'pohoda.import_confirm', { company: selectedAgenda?.company ?? '', year: year ?? '' }) }}</span>
        </label>
        <p v-if="!importDone && !jobRunning && missingRights.length" class="my-4 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="pohoda-rights-missing">
          {{ rightsMessage }}
        </p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="percent" :cancelling="cancelling" :show-cancel="true"
          counts-key="pohoda.job_counts" background-hint-key="pohoda.background_hint" running-key="pohoda.import_running"
          cancel-key="pohoda.cancel" cancelling-key="pohoda.cancelling" @cancel="cancel" />
        <MoneyS3Protocol v-if="run && run.mode === 'import'" :run="run" prefix="pohoda" />
      </template>
    </section>

    <div class="flex flex-wrap justify-end"><ActionBar :actions="actions" /></div>

    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <h2 class="border-b border-neutral-200 px-4 py-3 text-lg font-semibold">{{ t('pohoda.history_title') }}</h2>
      <p v-if="!runs.length" class="px-4 py-3 text-sm text-neutral-500">{{ t('pohoda.history_empty') }}</p>
      <div class="divide-y divide-neutral-100">
        <button v-for="item in runs" :key="item.id" type="button" class="flex w-full cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 text-left hover:bg-neutral-50" @click="showRun(item)">
          <span><strong>#{{ item.id }} · {{ t(`pohoda.kind.${item.kind ?? 'accounting'}`) }} · {{ t(`pohoda.mode.${item.mode}`) }}</strong><span class="ml-2 text-xs text-neutral-500">{{ [item.agenda_ico, item.agenda_year].filter(Boolean).join(' · ') }} · {{ item.created_at }}</span></span>
          <span class="rounded-full px-2.5 py-1 text-xs font-medium"
            :class="item.status === 'completed' ? 'bg-success-50 text-success-600' : item.status === 'failed' ? 'bg-danger-50 text-danger-600' : item.status === 'completed_with_warnings' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'">{{ t(`pohoda.status.${item.status}`) }}</span>
        </button>
      </div>
    </section>

    <section v-if="run && currentStep !== 3 && currentStep !== 4" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <MoneyS3Protocol :run="run" prefix="pohoda" />
    </section>
  </div>
</template>
