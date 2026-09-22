import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { useMigrationWizard, type MigrationWizardRun } from '@/composables/useMigrationWizard'

const m = vi.hoisted(() => ({ fetchImportJob: vi.fn(), cancelImportJob: vi.fn(), toastError: vi.fn(), toastSuccess: vi.fn() }))

vi.mock('@/api/imports', () => ({ fetchImportJob: m.fetchImportJob, cancelImportJob: m.cancelImportJob }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

interface Upload { token: string; agendas: number[] }
interface Pending { token: string; status: 'uploading' | 'processing' | 'failed'; error: string | null }
type Run = MigrationWizardRun & { agenda_year: number }

function mountWizard(multiYear: boolean, api: Record<string, any>, extra: Record<string, unknown> = {}) {
  let wizard!: ReturnType<typeof useMigrationWizard<Upload, Pending, Run, { mode: string }>>
  const Host = defineComponent({
    setup() {
      wizard = useMigrationWizard<Upload, Pending, Run, { mode: string }>({
        api: api as any,
        tokenKey: () => 'test.migration.token',
        isReady: (u): u is Upload => 'agendas' in u,
        text: key => `src.${key}`,
        multiYear,
        ...extra,
      })
      return () => h('div')
    },
  })
  const wrapper = mount(Host)
  return { wrapper, wizard: () => wizard }
}

function api(runs: Run[]) {
  return {
    uploadChunked: vi.fn(),
    show: vi.fn(),
    start: vi.fn().mockResolvedValue({ job_id: 7, status: 'queued', mode: 'dry_run' }),
    runs: vi.fn().mockResolvedValue({ items: runs }),
    run: vi.fn(async (id: number) => runs.find(r => r.id === id)),
    deleteRun: vi.fn().mockResolvedValue({ ok: true }),
  }
}

beforeEach(() => {
  sessionStorage.clear()
  vi.resetAllMocks()
})
afterEach(() => vi.useRealTimers())

describe('useMigrationWizard', () => {
  it('u převodu víc roků načte všechny běhy jobu vzestupně a úspěch bere ze stavu jobu', async () => {
    const runs: Run[] = [
      { id: 12, job_id: 7, mode: 'dry_run', status: 'failed', agenda_year: 2025 },
      { id: 11, job_id: 7, mode: 'dry_run', status: 'completed', agenda_year: 2024 },
      { id: 5, job_id: 3, mode: 'import', status: 'completed', agenda_year: 2023 },
    ]
    const client = api(runs)
    const { wizard } = mountWizard(true, client)
    await flushPromises()
    m.fetchImportJob.mockResolvedValue({ id: 7, status: 'completed_with_warnings', processed: 1, total_items: 1 })
    wizard().upload.value = { token: 'tok', agendas: [] }

    await wizard().start('dry_run', { mode: 'dry_run' })

    expect(client.start).toHaveBeenCalledWith('tok', { mode: 'dry_run' })
    expect(wizard().jobRuns.value.map(r => r.id)).toEqual([11, 12])
    expect(wizard().run.value?.id).toBe(12)
    expect(wizard().dryRunPassed.value).toBe(true)
    expect(wizard().currentStep.value).toBe(3)
  })

  it('u převodu jednoho běhu bere úspěch ze stavu běhu', async () => {
    const client = api([{ id: 4, job_id: 7, mode: 'dry_run', status: 'failed', agenda_year: 0 }])
    const { wizard } = mountWizard(false, client)
    await flushPromises()
    m.fetchImportJob.mockResolvedValue({ id: 7, status: 'completed', processed: 1, total_items: 1 })
    wizard().upload.value = { token: 'tok', agendas: [] }

    await wizard().start('dry_run', { mode: 'dry_run' })

    expect(wizard().run.value?.id).toBe(4)
    expect(wizard().dryRunPassed.value).toBe(false)
  })

  it('po obnovení stránky čeká na zpracování nahraného souboru a pak přejde na náhled', async () => {
    vi.useFakeTimers()
    sessionStorage.setItem('test.migration.token', 'tok')
    const client = api([])
    client.show
      .mockResolvedValueOnce({ token: 'tok', status: 'processing', error: null })
      .mockResolvedValueOnce({ token: 'tok', agendas: [2024] })
    const onReady = vi.fn()
    const { wizard } = mountWizard(true, client, { onReady })
    await flushPromises()
    expect(wizard().processing.value).toBe(true)
    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()

    expect(onReady).toHaveBeenCalledWith({ token: 'tok', agendas: [2024] })
    expect(wizard().currentStep.value).toBe(2)
    expect(wizard().processing.value).toBe(false)
  })

  it('chybu zpracování ukáže a token zapomene', async () => {
    sessionStorage.setItem('test.migration.token', 'tok')
    const client = api([])
    client.show.mockResolvedValueOnce({ token: 'tok', status: 'failed', error: null })
    mountWizard(true, client)
    await flushPromises()

    expect(m.toastError).toHaveBeenCalledWith('src.upload_failed')
    expect(sessionStorage.getItem('test.migration.token')).toBeNull()
  })

  it('smaže protokol zkoušky po potvrzení a přehled načte znovu', async () => {
    const run: Run = { id: 9, job_id: 1, mode: 'dry_run', status: 'completed', agenda_year: 2024 }
    const client = api([run])
    const { wizard } = mountWizard(true, client, { filterRuns: (items: Run[]) => items.filter(r => r.agenda_year === 2024) })
    await flushPromises()
    vi.stubGlobal('confirm', vi.fn(() => true))
    wizard().run.value = run

    await wizard().deleteRun(run)

    expect(client.deleteRun).toHaveBeenCalledWith(9)
    expect(wizard().run.value).toBeNull()
    expect(client.runs).toHaveBeenCalledTimes(2)
    expect(m.toastSuccess).toHaveBeenCalledWith('src.run_deleted')
    vi.unstubAllGlobals()
  })
})
