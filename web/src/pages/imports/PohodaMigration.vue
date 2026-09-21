<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isPohodaUploadReady, pohodaApi, type PohodaKind, type PohodaRun, type PohodaSystem, type PohodaToolFile, type PohodaUpload } from '@/api/pohoda'
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
 * Průvodce převodem ze STORMWARE: export agendy → náhled a volba roku → zkouška nanečisto
 * → ostrý převod. Nahraný export zůstává na serveru pod tokenem, takže obnovení stránky
 * průvodce nevrátí na začátek (token drží sessionStorage).
 *
 * Jedna komponenta obsluhuje dva průvodce, protože server i protokol jsou stejné; liší se
 * jen to, co se převádí a jakým nástrojem se to z programu dostane ven:
 *   - `pohoda`: účetní agenda z XML exportu POHODY,
 *   - `pamica`: personalistika a mzdy z datového souboru mzdového programu PAMICA.
 * Texty se hledají nejdřív v jmenném prostoru systému a teprve pak ve sdíleném `pohoda`.
 */
const props = withDefaults(defineProps<{ system?: PohodaSystem }>(), { system: 'pohoda' })

// Vlastní token na systém: rozpracovaný převod mezd nesmí přebít rozpracovaný převod účetnictví.
const TOKEN_KEY = computed(() => `myucto.${props.system}.token`)
// PAMICA vede jen mzdy, POHODA v tomto průvodci jen účetnictví. Mzdy z datového souboru
// POHODY patří taky do průvodce PAMICA, je to tentýž mzdový modul STORMWARE.
const FORCED_KIND: Record<PohodaSystem, PohodaKind> = { pohoda: 'accounting', pamica: 'payroll' }

const { t, te, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()

/**
 * Text průvodce: nejdřív jmenný prostor systému, jinak sdílený `pohoda`.
 *
 * Prostor se skládá do literálu se šablonou schválně, ne přes proměnnou:
 * mapu `namespaces.generated.json` staví statická analýza literálů
 * (`web/scripts/i18n-usage.mjs`) a z `${props.system}.${key}` by prostor
 * `pamica` nepoznala. Průvodce PAMICA by pak ukazoval texty POHODY.
 */
function ownKey(key: string): string {
  return props.system === 'pamica' ? `pamica.${key}` : `pohoda.${key}`
}
function tt(key: string, params?: Record<string, unknown>): string {
  const own = ownKey(key)
  return te(own) ? t(own, params ?? {}) : t(`pohoda.${key}`, params ?? {})
}
/** Seznamy (`tm`) se neptají přes `te`: u pole vrací `te` nepravdu i tam, kde překlad je. */
function list(key: string): string[] {
  const own = tm(ownKey(key)) as unknown[]
  const items = Array.isArray(own) && own.length > 0 ? own : (tm(`pohoda.${key}`) as unknown[])
  return items.map(item => rt(item as Parameters<typeof rt>[0]))
}
const exportHelpItems = computed(() => list('export_help_items'))
const mdbHelpItems = computed(() => list('mdb_help_items'))
const fileHelpItems = computed(() => list('file_help_items'))

const currentStep = ref(1)
const upload = ref<PohodaUpload | null>(null)
const file = ref<File | null>(null)
const year = ref<number | null>(null)
// Druh převodu plyne ze systému průvodce; volba v UI už není.
const kind = ref<PohodaKind>(FORCED_KIND[props.system])
const job = ref<FileImportJob | null>(null)
const jobMode = ref<'dry_run' | 'import' | null>(null)
const run = ref<PohodaRun | null>(null)
const runs = ref<PohodaRun[]>([])
const busy = ref(false)
const cancelling = ref(false)
const confirmed = ref(false)
// Mzdy: potvrzení, že OIČ a ID PPV v PAMICA pocházejí z protokolů ČSSZ. Bez něj je převod nepřevezme.
const confirmIdentifiers = ref(false)
// Mzdy: převzatá docházka a vstupy se rovnou schválí. Zapnuté proto, že měsíce z PAMICA
// už reálně proběhly a byly podané; jako koncepty by mzdový běh nad nimi vůbec nešel spustit.
const approveTakenOver = ref(true)
const dryRunPassed = ref(false)
const uploadPercent = ref<number | null>(null)
const processing = ref(false)
const toolOpen = ref(false)
const toolFiles = ref<PohodaToolFile[] | null>(null)
const toolGroups = computed(() => [
  { key: 'xml', names: ['Export-Pohoda.cmd', 'Export-Pohoda.ps1', 'Export-PohodaMdb.cmd', 'Export-PohodaMdb.ps1'] },
  { key: 'mdb', names: ['Export-PohodaMdbAccounting.cmd', 'Export-PohodaMdbAccounting.ps1'] },
].map(group => ({ ...group, files: (toolFiles.value ?? []).filter(file => group.names.includes(file.name)) })))
const toolLoading = ref(false)
const toolDownloading = ref<string | null>(null)
let pollTimer: ReturnType<typeof setTimeout> | null = null
let disposed = false

function readToken(): string | null {
  try { return sessionStorage.getItem(TOKEN_KEY.value) } catch { return null }
}
function writeToken(token: string | null): void {
  try {
    if (token) sessionStorage.setItem(TOKEN_KEY.value, token)
    else sessionStorage.removeItem(TOKEN_KEY.value)
  } catch { /* prohlížeč bez úložiště: průvodce jen nepřežije obnovení stránky */ }
}

function errorMessage(error: any, fallback: string): string {
  const message = String(error?.response?.data?.error?.message ?? '').trim()
  return message || fallback
}

const steps = computed(() => [1, 2, 3, 4].map(number => ({ number, label: tt(`step${number}`) })))
const payrollWizard = computed(() => kind.value === 'payroll')
// Přehled nahraný před podporou mezd příznaky nemá: to byla vždy agenda účetnictví.
function agendaHas(agenda: { has_accounting?: boolean; has_payroll?: boolean }): boolean {
  return payrollWizard.value ? (agenda.has_payroll ?? false) : (agenda.has_accounting ?? true)
}
// V přehledu se ukazují jen agendy, které tenhle průvodce umí převést. Export z POHODY
// nese obojí; mzdy z něj patří do průvodce PAMICA a naopak.
const agendas = computed(() => (upload.value?.agendas ?? []).filter(agendaHas))
const skipped = computed(() => (upload.value?.agendas ?? []).length - agendas.value.length)
// Převést jde jen rok agendy s IČO firmy; ostatní agendy exportu jsou jen informace.
const years = computed(() => [...new Set(agendas.value.filter(a => a.ico === upload.value?.supplier_ico).map(a => a.year))].sort((a, b) => b - a))
const selectedAgenda = computed(() => agendas.value.find(a => a.ico === upload.value?.supplier_ico && a.year === year.value) ?? null)
const noteFiles = computed(() => (selectedAgenda.value?.files ?? []).filter(f => f.state !== 'ok'))
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
const rightsMessage = computed(() => tt(kind.value === 'payroll' ? 'payroll_rights_missing' : 'rights_missing', { rights: missingRights.value.join(', ') }))
const payrollRightsBlocked = computed(() => kind.value === 'payroll' && missingRights.value.length > 0)
const blocked = computed(() => year.value === null || preflightErrors.value.length > 0 || payrollRightsBlocked.value)
const jobRunning = computed(() => job.value?.status === 'queued' || job.value?.status === 'running')
// Tlačítko je zakázané i během běžící úlohy; důvodem pak není kontrola před převodem.
const blockedReason = computed(() => jobRunning.value && !blocked.value
  ? tt(jobMode.value === 'import' ? 'import_running' : 'dry_run_running')
  : year.value === null ? tt('choose_year_first')
    : payrollRightsBlocked.value ? rightsMessage.value : tt('preflight_blocked'))
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

function fileState(state: string): string {
  const key = `file_state.${state}`
  return te(`pohoda.${key}`) ? tt(key) : state
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
    toolFiles.value = (await pohodaApi.toolFiles(props.system)).files
  } catch (error: any) {
    toolOpen.value = false
    toast.error(errorMessage(error, tt('tool_failed')))
  } finally {
    toolLoading.value = false
  }
}

async function downloadTool(name: string | null): Promise<void> {
  toolDownloading.value = name ?? '*'
  try {
    if (name === null) await pohodaApi.downloadTool(props.system)
    else await pohodaApi.downloadToolFile(name, props.system)
  } catch (error: any) {
    toast.error(errorMessage(error, tt('tool_failed')))
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
    toast.error(errorMessage(error, tt('upload_failed')))
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
        toast.error(result.status === 'failed' ? (result.error || tt('upload_failed')) : tt('upload_interrupted'))
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
      ...(kind.value === 'payroll' ? { confirm_identifiers: confirmIdentifiers.value, approve_taken_over: approveTakenOver.value } : {}),
    })
    jobMode.value = mode
    run.value = null
    currentStep.value = mode === 'dry_run' ? 3 : 4
    await pollJob(started.job_id)
  } catch (error: any) {
    toast.error(errorMessage(error, tt('start_failed')))
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

// Historie je společná pro oba průvodce, ale ukazuje se jen ta jeho. Běh bez druhu
// je z doby před podporou mezd, tedy vždycky účetnictví.
async function loadRuns(): Promise<void> {
  const items = (await pohodaApi.runs()).items
  runs.value = items.filter(r => (r.kind ?? 'accounting') === kind.value)
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
    { key: 'upload', label: busy.value ? (uploadPercent.value !== null ? tt('uploading_percent', { percent: uploadPercent.value }) : tt('uploading')) : tt('upload'), icon: 'upload', tier: 'primary', variant: 'primary', disabled: !file.value, disabledReason: tt('choose_file_first'), loading: busy.value, run: doUpload },
  ]
  if (currentStep.value === 2) return [
    { key: 'continue', label: tt('continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: blocked.value, disabledReason: blockedReason.value, run: () => { currentStep.value = 3 } },
    { key: 'new', label: tt('new_upload'), icon: 'x', tier: 'secondary', variant: 'neutral', run: resetUpload },
  ]
  if (currentStep.value === 3) return dryRunPassed.value && !jobRunning.value
    ? [
        { key: 'next', label: tt('continue'), icon: 'check', tier: 'primary', variant: 'primary', run: () => { currentStep.value = 4 } },
        { key: 'rerun', label: tt('dry_run_again'), icon: 'cycle', tier: 'secondary', variant: 'neutral', run: () => { void start('dry_run') } },
      ]
    : [
        { key: 'dry', label: tt('dry_run_start'), icon: 'play', tier: 'primary', variant: 'primary', disabled: blocked.value || jobRunning.value, disabledReason: blockedReason.value, loading: busy.value, run: () => { void start('dry_run') } },
      ]
  if (importDone.value) return kind.value === 'payroll'
    ? [{ key: 'payroll', label: tt('open_payroll'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'payroll-imports' } }]
    : [
        { key: 'journal', label: tt('open_journal'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'accounting-journal' } },
        { key: 'trial', label: tt('open_trial_balance'), icon: 'chart', tier: 'secondary', variant: 'neutral', to: { name: 'accounting-trial-balance' } },
      ]
  const importReason = missingRights.value.length
    ? rightsMessage.value
    : blocked.value ? blockedReason.value : tt('import_confirm_first')
  return [
    { key: 'import', label: tt('import_start'), icon: 'play', tier: 'primary', variant: 'warning', disabled: !confirmed.value || jobRunning.value || blocked.value || missingRights.value.length > 0, disabledReason: importReason, loading: busy.value, run: () => { void start('import') } },
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
      <h1 class="text-2xl font-semibold">{{ tt('title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ tt('subtitle') }}</p>
    </div>

    <div class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-testid="pohoda-support-notice">
      <p>{{ tt('support_notice') }}</p>
      <RouterLink to="/admin/support" class="mt-1 inline-block font-medium underline hover:no-underline">{{ tt('support_notice_link') }}</RouterLink>
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
        <h2 class="mb-1 text-lg font-semibold">{{ tt('upload_title') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ tt('upload_hint') }}</p>

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="pohoda-export-help">
          <div :class="props.system === 'pohoda' ? 'grid gap-5 lg:grid-cols-2' : ''">
            <div>
              <h3 class="mb-2 font-medium text-neutral-700">{{ tt('export_help_title') }}</h3>
              <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
                <li v-for="(item, i) in exportHelpItems" :key="i">{{ item }}</li>
              </ol>
            </div>
            <div v-if="props.system === 'pohoda'" data-testid="pohoda-mdb-help">
              <h3 class="mb-2 font-medium text-neutral-700">{{ tt('mdb_help_title') }}</h3>
              <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
                <li v-for="(item, i) in mdbHelpItems" :key="i">{{ item }}</li>
              </ol>
              <p class="mt-2 text-neutral-600">{{ tt('mdb_help_requirements') }}</p>
              <p class="mt-2 text-neutral-600">
                {{ tt('mdb_driver_hint') }}
                <a href="https://support.microsoft.com/en-us/access/download-and-install-microsoft-365-access-runtime" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-700 underline hover:no-underline">{{ tt('mdb_driver_link') }}</a>
                {{ tt('mdb_driver_architecture') }}
              </p>
            </div>
          </div>
          <p v-if="props.system === 'pohoda'" class="mt-3 font-medium text-neutral-700">{{ tt('export_auto_detect') }}</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button v-if="props.system === 'pohoda'" type="button" :class="btnOutline('primary')" :disabled="toolDownloading !== null" data-testid="pohoda-tools-download" @click="downloadTool(null)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              {{ tt('tools_download') }}
            </button>
            <button type="button" :class="btnOutline('neutral')" :aria-expanded="toolOpen" data-testid="pohoda-tool-toggle" @click="toggleTool">
              <svg class="h-4 w-4 transition-transform" :class="toolOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
              {{ toolOpen ? tt('tool_hide') : tt('tool_show') }}
            </button>
          </div>
          <div v-if="toolOpen" class="mt-3 rounded-md border border-neutral-200 bg-surface px-3 py-3" data-testid="pohoda-tool">
            <p class="mb-2 text-neutral-600">{{ tt('tool_hint') }}</p>
            <p v-if="toolLoading" class="text-neutral-400">{{ tt('tool_loading') }}</p>
            <template v-else-if="toolFiles">
              <p v-if="!toolFiles.length" class="text-neutral-500">{{ tt('tool_empty') }}</p>
              <div v-else-if="props.system === 'pohoda'" class="mb-3 grid gap-3 lg:grid-cols-2">
                <section v-for="group in toolGroups" :key="group.key" class="min-w-0 rounded-md border border-neutral-200 p-3" :data-testid="`pohoda-tool-group-${group.key}`">
                  <h4 class="font-medium text-neutral-800">{{ tt(`tool_group_${group.key}_title`) }}</h4>
                  <p class="mt-1 text-sm text-neutral-600">{{ tt(`tool_group_${group.key}_hint`) }}</p>
                  <ul class="mt-2 divide-y divide-neutral-100">
                    <li v-for="f in group.files" :key="f.name" class="flex flex-wrap items-center justify-between gap-2 py-2">
                      <div v-if="f.name === 'Export-PohodaMdb.cmd'" class="basis-full py-2">
                        <h5 class="font-medium text-neutral-800">{{ tt('tool_group_support_title') }}</h5>
                        <p class="mt-1 text-sm text-neutral-600">{{ tt('tool_group_support_hint') }}</p>
                      </div>
                      <div class="min-w-0">
                        <span class="break-all font-mono text-sm">{{ f.name }}</span>
                        <span class="ml-2 text-xs text-neutral-500">{{ formatBytes(f.size) }}</span>
                        <p class="mt-1 text-xs text-neutral-500">{{ tt(f.name === 'Export-PohodaMdb.cmd' ? 'tool_role_optional' : f.name === 'Export-PohodaMdb.ps1' ? 'tool_role_shared' : f.name.endsWith('.cmd') ? 'tool_role_launcher' : 'tool_role_script') }}</p>
                      </div>
                      <button type="button" :class="btnOutlineSm('neutral')" :disabled="toolDownloading !== null" @click="downloadTool(f.name)">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                        {{ tt('tool_download') }}
                      </button>
                    </li>
                  </ul>
                </section>
              </div>
              <ul v-else class="mb-3 divide-y divide-neutral-100">
                <li v-for="f in toolFiles" :key="f.name" class="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span><span class="font-mono">{{ f.name }}</span><span class="ml-2 text-xs text-neutral-500">{{ formatBytes(f.size) }}</span></span>
                  <button type="button" :class="btnOutlineSm('neutral')" :disabled="toolDownloading !== null" @click="downloadTool(f.name)">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                    {{ tt('tool_download') }}
                  </button>
                </li>
              </ul>
              <div v-if="toolFiles.length" class="flex flex-wrap gap-2">
                <button type="button" :class="btnOutline('primary')" :disabled="toolDownloading !== null" data-testid="pohoda-tool-zip" @click="downloadTool(null)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                  {{ tt('tool_download_all') }}
                </button>
              </div>
            </template>
          </div>
        </div>

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="pohoda-file-help">
          <h3 class="mb-2 font-medium text-neutral-700">{{ tt('file_help_title') }}</h3>
          <ul class="list-disc space-y-1 pl-5 text-neutral-600">
            <li v-for="(item, i) in fileHelpItems" :key="i">{{ item }}</li>
          </ul>
        </div>

        <label class="block max-w-xl text-sm font-medium">
          {{ tt('choose_file') }}
          <input type="file" accept=".zip" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" data-testid="pohoda-input" :disabled="busy" @change="onFile" />
        </label>
        <div v-if="uploadPercent !== null || processing" class="mt-4 max-w-xl space-y-2 rounded-md border border-primary-200 bg-primary-50/50 px-3 py-3" data-testid="pohoda-progress">
          <div class="text-sm font-medium text-primary-700">
            {{ processing ? tt('processing') : tt('uploading_percent', { percent: uploadPercent ?? 0 }) }}
          </div>
          <div class="h-2 overflow-hidden rounded-full bg-primary-100">
            <div class="h-full bg-primary-500 transition-all duration-300"
              :class="processing ? 'w-1/3 animate-pulse' : ''"
              :style="processing ? undefined : { width: (uploadPercent ?? 0) + '%' }"></div>
          </div>
          <p class="text-xs text-neutral-500">{{ processing ? tt('processing_hint') : tt('uploading_hint') }}</p>
        </div>
      </template>

      <template v-else-if="currentStep === 2 && upload">
        <h2 class="mb-4 text-lg font-semibold">{{ tt('step2') }}</h2>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ tt('agendas_title') }}</h3>
        <div class="mb-2 overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
              <tr>
                <th class="px-3 py-2">{{ tt('col_ico') }}</th>
                <th class="px-3 py-2">{{ tt('col_company') }}</th>
                <th class="px-3 py-2">{{ tt('col_year') }}</th>
                <template v-if="!payrollWizard">
                  <th class="px-3 py-2 text-right">{{ tt('col_journal') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_opening') }}</th>
                  <th class="px-3 py-2">{{ tt('col_range') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_issued') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_purchase') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_internal') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_cash') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_bank') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_partners') }}</th>
                </template>
                <th class="px-3 py-2 whitespace-nowrap">{{ tt('col_payroll') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in agendas" :key="`${a.ico}-${a.year}`" :class="a.ico === upload.supplier_ico && a.year === year ? 'bg-primary-50/60' : ''">
                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ a.ico || '—' }}</td>
                <td class="px-3 py-2">
                  {{ a.company || '—' }}
                  <span v-if="a.ico !== upload.supplier_ico" class="ml-2 rounded-full bg-neutral-100 px-2 py-0.5 text-xs whitespace-nowrap text-neutral-600">{{ tt('other_company') }}</span>
                  <span v-if="!payrollWizard && a.has_accounting === false" class="ml-2 rounded-full bg-primary-50 px-2 py-0.5 text-xs whitespace-nowrap text-primary-700" data-testid="pohoda-payroll-only">{{ tt('payroll_only') }}</span>
                </td>
                <td class="px-3 py-2 font-medium">{{ a.year }}</td>
                <template v-if="!payrollWizard">
                  <td class="px-3 py-2 text-right">{{ a.counts.journal }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.opening }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ a.counts.first_date ?? '—' }} – {{ a.counts.last_date ?? '—' }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.issued }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.purchase }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.internal }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.cash }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.bank }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.partners }}</td>
                </template>
                <td class="px-3 py-2 whitespace-nowrap" data-testid="pohoda-payroll-cell">{{ a.payroll ? tt('payroll_cell', { employees: a.payroll.employees, months: a.payroll.months }) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mb-5 text-sm text-neutral-500">{{ tt('supplier_ico', { ico: upload.supplier_ico || '—' }) }}</p>

        <p v-if="!years.length" class="mb-5 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="pohoda-no-agenda">
          {{ upload.supplier_ico ? tt('no_matching_agenda', { ico: upload.supplier_ico }) : tt('supplier_ico_missing') }}
        </p>
        <template v-else>
          <label class="mb-1 block max-w-xs text-sm font-medium">{{ tt('year') }}
            <select v-model="year" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm" data-testid="pohoda-year">
              <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
          </label>
          <p class="mb-5 text-sm text-neutral-500">{{ tt('year_hint') }}</p>

          <p v-if="skipped > 0" class="mb-5 rounded-lg border border-primary-500/30 bg-primary-50 px-3 py-2 text-sm text-primary-700" data-testid="pohoda-skipped-agendas">{{ tt('other_wizard_hint', { n: skipped }) }}</p>

          <label v-if="kind === 'payroll'" class="mb-3 flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3">
            <input v-model="confirmIdentifiers" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-confirm-identifiers" />
            <span class="text-sm text-neutral-700">{{ tt('payroll_identifiers_confirm') }}</span>
          </label>
          <label v-if="kind === 'payroll'" class="mb-5 flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3">
            <input v-model="approveTakenOver" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-approve-taken-over" />
            <span class="text-sm text-neutral-700">{{ tt('payroll_approve_taken_over') }}</span>
          </label>

          <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ tt('preflight_title', { year: year ?? '' }) }}</h3>
          <ul v-if="preflight.length" class="mb-5 space-y-2">
            <li v-for="m in preflight" :key="m.code + m.message" class="rounded-lg border px-3 py-2 text-sm"
              :class="m.level === 'error' ? 'border-danger-500/30 bg-danger-50 text-danger-600' : m.level === 'warning' ? 'border-warning-500/30 bg-warning-50 text-warning-700' : 'border-primary-500/30 bg-primary-50 text-primary-700'">
              {{ m.message }}
            </li>
          </ul>
          <p v-else class="mb-5 rounded-lg border border-success-500/30 bg-success-50 px-3 py-2 text-sm text-success-600">{{ tt('preflight_ok') }}</p>

          <details v-if="noteFiles.length" class="rounded-lg border border-neutral-200 px-4 py-3 text-sm" data-testid="pohoda-file-notes">
            <summary class="cursor-pointer font-medium text-neutral-700">{{ tt('files_title', { n: noteFiles.length }) }}</summary>
            <p class="mt-2 text-neutral-500">{{ tt('files_hint') }}</p>
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
        <h2 class="mb-1 text-lg font-semibold">{{ tt('dry_run_title') }}</h2>
        <p class="mb-2 text-sm text-neutral-500">{{ tt(kind === 'payroll' ? 'payroll_dry_run_hint' : 'dry_run_hint', { year: year ?? '' }) }}</p>
        <p class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ tt('dry_run_locks_hint') }}</p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="null" :cancelling="false" :show-cancel="false"
          counts-key="pohoda.job_counts" background-hint-key="pohoda.background_hint" running-key="pohoda.dry_run_running" />
        <MoneyS3Protocol v-if="run && run.mode === 'dry_run'" :run="run" prefix="pohoda" />
      </template>

      <template v-else>
        <h2 class="mb-1 text-lg font-semibold">{{ tt('import_title') }}</h2>
        <label v-if="!importDone && !jobRunning" class="my-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
          <input v-model="confirmed" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-confirm" />
          <span class="text-sm text-warning-700">{{ tt(kind === 'payroll' ? 'payroll_import_confirm' : 'import_confirm', { company: selectedAgenda?.company ?? '', year: year ?? '' }) }}</span>
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
      <h2 class="border-b border-neutral-200 px-4 py-3 text-lg font-semibold">{{ tt('history_title') }}</h2>
      <p v-if="!runs.length" class="px-4 py-3 text-sm text-neutral-500">{{ tt('history_empty') }}</p>
      <div class="divide-y divide-neutral-100">
        <button v-for="item in runs" :key="item.id" type="button" class="flex w-full cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 text-left hover:bg-neutral-50" @click="showRun(item)">
          <span><strong>#{{ item.id }} · {{ tt(`kind.${item.kind ?? 'accounting'}`) }} · {{ tt(`mode.${item.mode}`) }}</strong><span class="ml-2 text-xs text-neutral-500">{{ [item.agenda_ico, item.agenda_year].filter(Boolean).join(' · ') }} · {{ item.created_at }}</span></span>
          <span class="rounded-full px-2.5 py-1 text-xs font-medium"
            :class="item.status === 'completed' ? 'bg-success-50 text-success-600' : item.status === 'failed' ? 'bg-danger-50 text-danger-600' : item.status === 'completed_with_warnings' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'">{{ tt(`status.${item.status}`) }}</span>
        </button>
      </div>
    </section>

    <section v-if="run && currentStep !== 3 && currentStep !== 4" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <MoneyS3Protocol :run="run" prefix="pohoda" />
    </section>
  </div>
</template>
