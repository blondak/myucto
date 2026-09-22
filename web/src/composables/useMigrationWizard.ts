import { computed, onBeforeUnmount, onMounted, ref, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { cancelImportJob, fetchImportJob, type FileImportJob } from '@/api/imports'
import type { ChunkedUploadProgress } from '@/api/chunkedUpload'
import { useToast } from '@/composables/useToast'

/** Běh převodu tak, jak ho průvodce potřebuje (protokol v přehledu běhů). */
export interface MigrationWizardRun {
  id: number
  job_id: number | null
  mode: 'dry_run' | 'import'
  status: string
}

/** Soubor, který se ještě nahrává nebo ho server na pozadí zpracovává. */
export interface MigrationWizardPending {
  status: 'uploading' | 'processing' | 'failed'
  error: string | null
}

export interface MigrationWizardApi<TUpload, TPending, TRun, TStart> {
  uploadChunked: (file: File, onProgress?: ChunkedUploadProgress, onStarted?: (token: string) => void) => Promise<{ token: string; job_id: number | null }>
  show: (token: string) => Promise<TUpload | TPending>
  start: (token: string, params: TStart) => Promise<{ job_id: number; status: string; mode: string }>
  runs: () => Promise<{ items: TRun[] }>
  run: (id: number) => Promise<TRun>
  deleteRun?: (id: number) => Promise<unknown>
}

export interface MigrationWizardOptions<TUpload extends { token: string }, TPending, TRun, TStart> {
  api: MigrationWizardApi<TUpload, TPending, TRun, TStart>
  /** Klíč sessionStorage s tokenem nahraného souboru (obnovení stránky vrátí průvodce tam, kde byl). */
  tokenKey: () => string
  isReady: (upload: TUpload | TPending) => upload is TUpload
  /** Text průvodce podle klíče ve jmenném prostoru zdroje. */
  text: (key: string, params?: Record<string, unknown>) => string
  /**
   * Job víc roků má běh a protokol za každý rok: průvodce načte všechny a úspěch bere
   * ze stavu jobu. Bez toho (Money S3) má job jeden běh a úspěch se bere z jeho stavu.
   */
  multiYear: boolean
  /** Soubor je zpracovaný a průvodce přechází na náhled (předvolby zdroje). */
  onReady?: (upload: TUpload) => void
  /** Průvodce začíná znovu od nahrání. */
  onReset?: () => void
  /** Přehled běhů, které tenhle průvodce ukazuje. */
  filterRuns?: (runs: TRun[]) => TRun[]
}

/**
 * Společný průběh průvodců převodem (Money S3, POHODA/PAMICA, PREMIER): nahrání souboru
 * po částech a čekání na jeho zpracování, kroky průvodce, spuštění zkoušky nanečisto
 * a ostrého převodu, sledování jobu, zrušení a přehled protokolů. Stránka dodá texty,
 * předvolby po nahrání a vlastní obsah kroků.
 */
export function useMigrationWizard<TUpload extends { token: string }, TPending extends MigrationWizardPending, TRun extends MigrationWizardRun, TStart>(
  options: MigrationWizardOptions<TUpload, TPending, TRun, TStart>,
) {
  const { t } = useI18n()
  const toast = useToast()
  const { api, text } = options

  const currentStep = ref(1)
  const upload = ref<TUpload | null>(null) as Ref<TUpload | null>
  const file = ref<File | null>(null)
  const job = ref<FileImportJob | null>(null)
  const jobMode = ref<'dry_run' | 'import' | null>(null)
  const run = ref<TRun | null>(null) as Ref<TRun | null>
  // Běhy (protokoly) aktuálního jobu vzestupně: job víc roků má běh za každý rok.
  const jobRuns = ref<TRun[]>([]) as Ref<TRun[]>
  const runs = ref<TRun[]>([]) as Ref<TRun[]>
  const busy = ref(false)
  const cancelling = ref(false)
  const confirmed = ref(false)
  const dryRunPassed = ref(false)
  // Nahrávání po částech (procenta) a následné zpracování souboru serverem na pozadí.
  const uploadPercent = ref<number | null>(null)
  const processing = ref(false)
  const deletingRun = ref<number | null>(null)
  let pollTimer: ReturnType<typeof setTimeout> | null = null
  let disposed = false

  function readToken(): string | null {
    try { return sessionStorage.getItem(options.tokenKey()) } catch { return null }
  }
  function writeToken(token: string | null): void {
    try {
      if (token) sessionStorage.setItem(options.tokenKey(), token)
      else sessionStorage.removeItem(options.tokenKey())
    } catch { /* prohlížeč bez úložiště: průvodce jen nepřežije obnovení stránky */ }
  }

  function errorMessage(error: any, fallback: string): string {
    const message = String(error?.response?.data?.error?.message ?? '').trim()
    return message || fallback
  }

  const jobRunning = computed(() => job.value?.status === 'queued' || job.value?.status === 'running')
  const jobSucceeded = computed(() => job.value?.status === 'completed' || job.value?.status === 'completed_with_warnings')
  const percent = computed(() => {
    if (jobMode.value !== 'import' || !job.value?.total_items) return null
    return Math.min(100, Math.round(job.value.processed / job.value.total_items * 100))
  })

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
      const { token } = await api.uploadChunked(
        file.value,
        (sent, total) => { uploadPercent.value = total > 0 ? Math.floor(sent / total * 100) : 100 },
        started => writeToken(started),
      )
      uploadPercent.value = null
      await waitForUpload(token)
    } catch (error: any) {
      writeToken(null)
      toast.error(errorMessage(error, text('upload_failed')))
    } finally {
      uploadPercent.value = null
      busy.value = false
    }
  }

  /** Polluje stav nahraného souboru, dokud ho server nerozbalí a nenačte (nebo nenahlásí chybu). */
  async function waitForUpload(token: string): Promise<void> {
    processing.value = true
    try {
      while (!disposed) {
        const result = await api.show(token)
        if (options.isReady(result)) {
          upload.value = result
          options.onReady?.(result)
          dryRunPassed.value = false
          confirmed.value = false
          run.value = null
          currentStep.value = 2
          return
        }
        if (result.status !== 'processing') {
          // 'uploading' po obnovení stránky: soubor v prohlížeči už není, nahrávání nejde dokončit.
          writeToken(null)
          toast.error(result.status === 'failed' ? (result.error || text('upload_failed')) : text('upload_interrupted'))
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
    options.onReset?.()
    run.value = null
    jobRuns.value = []
    dryRunPassed.value = false
    confirmed.value = false
    writeToken(null)
    currentStep.value = 1
  }

  async function start(mode: 'dry_run' | 'import', params: TStart): Promise<void> {
    if (!upload.value) return
    busy.value = true
    try {
      const started = await api.start(upload.value.token, params)
      jobMode.value = mode
      run.value = null
      jobRuns.value = []
      currentStep.value = mode === 'dry_run' ? 3 : 4
      await pollJob(started.job_id)
    } catch (error: any) {
      toast.error(errorMessage(error, text('start_failed')))
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
    let ok: boolean
    if (options.multiYear) {
      // Job víc roků má běh a protokol za každý rok - průvodce ukazuje všechny, vzestupně.
      const ids = runs.value.filter(r => r.job_id === id).map(r => r.id).sort((a, b) => a - b)
      jobRuns.value = await Promise.all(ids.map(runId => api.run(runId)))
      run.value = jobRuns.value[jobRuns.value.length - 1] ?? null
      ok = jobRuns.value.length > 0 && jobSucceeded.value
    } else {
      const finished = runs.value.find(r => r.job_id === id)
      run.value = finished ? await api.run(finished.id) : null
      ok = run.value?.status === 'completed' || run.value?.status === 'completed_with_warnings'
    }
    if (jobMode.value === 'dry_run') {
      dryRunPassed.value = ok
    } else if (jobMode.value === 'import' && ok) {
      // Server nahraný soubor po úspěšném převodu smazal (nebo si ho drží pro další roky).
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
    const items = (await api.runs()).items
    runs.value = options.filterRuns ? options.filterRuns(items) : items
  }

  async function showRun(item: TRun): Promise<void> {
    try {
      run.value = await api.run(item.id)
    } catch (error: any) {
      toast.error(errorMessage(error, t('common.error')))
    }
  }

  /** Smazat jde jen doběhlou zkoušku nanečisto, protokol ostrého převodu zůstává. */
  async function deleteRun(item: TRun): Promise<void> {
    if (!api.deleteRun || !confirm(text('run_delete_confirm', { id: item.id }))) return
    deletingRun.value = item.id
    try {
      await api.deleteRun(item.id)
      if (run.value?.id === item.id) run.value = null
      await loadRuns()
      toast.success(text('run_deleted'))
    } catch (error: any) {
      toast.error(errorMessage(error, t('common.error')))
    } finally {
      deletingRun.value = null
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

  onMounted(load)
  onBeforeUnmount(() => {
    disposed = true
    if (pollTimer) clearTimeout(pollTimer)
  })

  return {
    currentStep, upload, file, job, jobMode, run, jobRuns, runs, busy, cancelling, confirmed, dryRunPassed,
    uploadPercent, processing, deletingRun, jobRunning, jobSucceeded, percent,
    canGoTo, goTo, onFile, doUpload, resetUpload, start, cancel, showRun, deleteRun, loadRuns, errorMessage, writeToken,
  }
}
