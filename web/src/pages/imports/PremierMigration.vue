<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { isPremierUploadReady, premierApi, type PremierRun, type PremierUpload } from '@/api/premier'
import { cancelImportJob, fetchImportJob, type FileImportJob } from '@/api/imports'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import type { PermissionKey } from '@/security/permissions'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'

/**
 * Průvodce převodem z PREMIER: záloha dat (Správce → Záloha dat, F11) → náhled agend
 * a volba roku → zkouška nanečisto → ostrý převod. Nahraná záloha zůstává na serveru
 * pod tokenem, takže obnovení stránky průvodce nevrátí na začátek (token drží
 * sessionStorage).
 *
 * Na rozdíl od průvodce POHODA/PAMICA (PohodaMigration.vue) tu není exportní nástroj
 * ke stažení (záloha se dělá přímo v PREMIERu) ani volba druhu převodu (mzdy PREMIER
 * v téže záloze nevede — nepřevádí se vůbec). Sdílený je jen server a protokol, proto
 * vlastní, trimnutá komponenta místo dalšího `system` v PohodaMigration.vue.
 */
const TOKEN_KEY = 'myucto.premier.token'

const { t, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()

function tt(key: string, params?: Record<string, unknown>): string {
  return t(`premier.${key}`, params ?? {})
}
function list(key: string): string[] {
  const items = tm(`premier.${key}`) as unknown[]
  return (Array.isArray(items) ? items : []).map(item => rt(item as Parameters<typeof rt>[0]))
}
const backupHelpItems = computed(() => list('backup_help_items'))
const fileHelpItems = computed(() => list('file_help_items'))

const currentStep = ref(1)
const upload = ref<PremierUpload | null>(null)
const file = ref<File | null>(null)
const year = ref<number | null>(null)
const job = ref<FileImportJob | null>(null)
const jobMode = ref<'dry_run' | 'import' | null>(null)
const run = ref<PremierRun | null>(null)
const runs = ref<PremierRun[]>([])
const busy = ref(false)
const cancelling = ref(false)
const confirmed = ref(false)
const dryRunPassed = ref(false)
const uploadPercent = ref<number | null>(null)
const processing = ref(false)
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

const steps = computed(() => [1, 2, 3, 4].map(number => ({ number, label: tt(`step${number}`) })))
const agendas = computed(() => (upload.value?.agendas ?? []).filter(a => a.has_accounting))
// Roky vzestupně: záloha nese celé účetnictví najednou, ale doporučený postup je
// převádět od nejstaršího nepřevedeného roku, protože počáteční stavy roku vycházejí
// z let předchozích v záloze.
const years = computed(() => [...new Set(agendas.value.filter(a => a.ico === upload.value?.supplier_ico).map(a => a.year))].sort((a, b) => a - b))
const selectedAgenda = computed(() => agendas.value.find(a => a.ico === upload.value?.supplier_ico && a.year === year.value) ?? null)
const preflight = computed(() => (year.value === null ? [] : upload.value?.preflight?.[String(year.value)] ?? []))
const preflightErrors = computed(() => preflight.value.filter(m => m.level === 'error'))
const missingRights = computed<PermissionKey[]>(() => (['accounting.journal.write', 'settings.company.write'] as PermissionKey[]).filter(key => !auth.canWrite(key)))
const rightsMessage = computed(() => tt('rights_missing', { rights: missingRights.value.join(', ') }))
const blocked = computed(() => year.value === null || preflightErrors.value.length > 0)
const jobRunning = computed(() => job.value?.status === 'queued' || job.value?.status === 'running')
const blockedReason = computed(() => jobRunning.value && !blocked.value
  ? tt(jobMode.value === 'import' ? 'import_running' : 'dry_run_running')
  : year.value === null ? tt('choose_year_first') : tt('preflight_blocked'))
const percent = computed(() => {
  if (jobMode.value !== 'import' || !job.value?.total_items) return null
  return Math.min(100, Math.round(job.value.processed / job.value.total_items * 100))
})
const importDone = computed(() => jobMode.value === 'import' && !jobRunning.value && run.value?.mode === 'import'
  && (run.value.status === 'completed' || run.value.status === 'completed_with_warnings'))

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

async function doUpload(): Promise<void> {
  if (!file.value) return
  busy.value = true
  uploadPercent.value = 0
  try {
    const { token } = await premierApi.uploadChunked(
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

/** Polluje stav nahrané zálohy, dokud ho server nerozbalí a nenačte (nebo nenahlásí chybu). */
async function waitForUpload(token: string): Promise<void> {
  processing.value = true
  try {
    while (!disposed) {
      const result = await premierApi.show(token)
      if (isPremierUploadReady(result)) {
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
    const started = await premierApi.start(upload.value.token, { mode, year: year.value })
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
  run.value = finished ? await premierApi.run(finished.id) : null
  const ok = run.value?.status === 'completed' || run.value?.status === 'completed_with_warnings'
  if (jobMode.value === 'dry_run') {
    dryRunPassed.value = ok
  } else if (jobMode.value === 'import' && ok) {
    // Server nahranou zálohu po úspěšném převodu smazal.
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
  runs.value = (await premierApi.runs()).items
}

async function showRun(item: PremierRun): Promise<void> {
  try {
    run.value = await premierApi.run(item.id)
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
  if (importDone.value) return [
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

    <div class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-testid="premier-support-notice">
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

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="premier-backup-help">
          <h3 class="mb-2 font-medium text-neutral-700">{{ tt('backup_help_title') }}</h3>
          <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
            <li v-for="(item, i) in backupHelpItems" :key="i">{{ item }}</li>
          </ol>
        </div>

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="premier-file-help">
          <h3 class="mb-2 font-medium text-neutral-700">{{ tt('file_help_title') }}</h3>
          <ul class="list-disc space-y-1 pl-5 text-neutral-600">
            <li v-for="(item, i) in fileHelpItems" :key="i">{{ item }}</li>
          </ul>
        </div>

        <label class="block max-w-xl text-sm font-medium">
          {{ tt('choose_file') }}
          <input type="file" accept=".izip,.icab,.zip" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" data-testid="premier-input" :disabled="busy" @change="onFile" />
        </label>
        <div v-if="uploadPercent !== null || processing" class="mt-4 max-w-xl space-y-2 rounded-md border border-primary-200 bg-primary-50/50 px-3 py-3" data-testid="premier-progress">
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
                <th class="px-3 py-2 text-right">{{ tt('col_entries') }}</th>
                <th class="px-3 py-2">{{ tt('col_payroll') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in agendas" :key="`${a.ico}-${a.year}`" :class="a.ico === upload.supplier_ico && a.year === year ? 'bg-primary-50/60' : ''">
                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ a.ico || '—' }}</td>
                <td class="px-3 py-2">
                  {{ a.company || '—' }}
                  <span v-if="a.ico !== upload.supplier_ico" class="ml-2 rounded-full bg-neutral-100 px-2 py-0.5 text-xs whitespace-nowrap text-neutral-600">{{ tt('other_company') }}</span>
                </td>
                <td class="px-3 py-2 font-medium">{{ a.year }}</td>
                <td class="px-3 py-2 text-right">{{ a.entries }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ a.has_payroll ? tt('payroll_not_transferred') : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mb-5 text-sm text-neutral-500">{{ tt('supplier_ico', { ico: upload.supplier_ico || '—' }) }}</p>

        <p v-if="!years.length" class="mb-5 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="premier-no-agenda">
          {{ upload.supplier_ico ? tt('no_matching_agenda', { ico: upload.supplier_ico }) : tt('supplier_ico_missing') }}
        </p>
        <template v-else>
          <label class="mb-1 block max-w-xs text-sm font-medium">{{ tt('year') }}
            <select v-model="year" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm" data-testid="premier-year">
              <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
          </label>
          <p class="mb-5 text-sm text-neutral-500">{{ tt('year_hint') }}</p>

          <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ tt('preflight_title', { year: year ?? '' }) }}</h3>
          <ul v-if="preflight.length" class="mb-5 space-y-2">
            <li v-for="m in preflight" :key="m.code + m.message" class="rounded-lg border px-3 py-2 text-sm"
              :class="m.level === 'error' ? 'border-danger-500/30 bg-danger-50 text-danger-600' : m.level === 'warning' ? 'border-warning-500/30 bg-warning-50 text-warning-700' : 'border-primary-500/30 bg-primary-50 text-primary-700'">
              {{ m.message }}
            </li>
          </ul>
          <p v-else class="mb-5 rounded-lg border border-success-500/30 bg-success-50 px-3 py-2 text-sm text-success-600">{{ tt('preflight_ok') }}</p>
        </template>
      </template>

      <template v-else-if="currentStep === 3">
        <h2 class="mb-1 text-lg font-semibold">{{ tt('dry_run_title') }}</h2>
        <p class="mb-2 text-sm text-neutral-500">{{ tt('dry_run_hint', { year: year ?? '' }) }}</p>
        <p class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ tt('dry_run_locks_hint') }}</p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="null" :cancelling="false" :show-cancel="false"
          counts-key="premier.job_counts" background-hint-key="premier.background_hint" running-key="premier.dry_run_running" />
        <MoneyS3Protocol v-if="run && run.mode === 'dry_run'" :run="run" prefix="premier" />
      </template>

      <template v-else>
        <h2 class="mb-1 text-lg font-semibold">{{ tt('import_title') }}</h2>
        <label v-if="!importDone && !jobRunning" class="my-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
          <input v-model="confirmed" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="premier-confirm" />
          <span class="text-sm text-warning-700">{{ tt('import_confirm', { company: selectedAgenda?.company ?? '', year: year ?? '' }) }}</span>
        </label>
        <p v-if="!importDone && !jobRunning && missingRights.length" class="my-4 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="premier-rights-missing">
          {{ rightsMessage }}
        </p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="percent" :cancelling="cancelling" :show-cancel="true"
          counts-key="premier.job_counts" background-hint-key="premier.background_hint" running-key="premier.import_running"
          cancel-key="premier.cancel" cancelling-key="premier.cancelling" @cancel="cancel" />
        <MoneyS3Protocol v-if="run && run.mode === 'import'" :run="run" prefix="premier" />
      </template>
    </section>

    <div class="flex flex-wrap justify-end"><ActionBar :actions="actions" /></div>

    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <h2 class="border-b border-neutral-200 px-4 py-3 text-lg font-semibold">{{ tt('history_title') }}</h2>
      <p v-if="!runs.length" class="px-4 py-3 text-sm text-neutral-500">{{ tt('history_empty') }}</p>
      <div class="divide-y divide-neutral-100">
        <button v-for="item in runs" :key="item.id" type="button" class="flex w-full cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 text-left hover:bg-neutral-50" @click="showRun(item)">
          <span><strong>#{{ item.id }} · {{ tt(`mode.${item.mode}`) }}</strong><span class="ml-2 text-xs text-neutral-500">{{ [item.agenda_ico, item.agenda_year].filter(Boolean).join(' · ') }} · {{ item.created_at }}</span></span>
          <span class="rounded-full px-2.5 py-1 text-xs font-medium"
            :class="item.status === 'completed' ? 'bg-success-50 text-success-600' : item.status === 'failed' ? 'bg-danger-50 text-danger-600' : item.status === 'completed_with_warnings' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'">{{ tt(`status.${item.status}`) }}</span>
        </button>
      </div>
    </section>

    <section v-if="run && currentStep !== 3 && currentStep !== 4" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <MoneyS3Protocol :run="run" prefix="premier" />
    </section>
  </div>
</template>
