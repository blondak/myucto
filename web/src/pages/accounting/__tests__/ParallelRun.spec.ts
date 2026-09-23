import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { ParallelRunCheck } from '@/api/parallelRun'

const m = vi.hoisted(() => ({
  sources: vi.fn(),
  backups: vi.fn(),
  history: vi.fn(),
  get: vi.fn(),
  run: vi.fn(),
  classify: vi.fn(),
  close: vi.fn(),
  reopen: vi.fn(),
  remove: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
  canWrite: true,
}))

vi.mock('@/api/parallelRun', () => ({
  parallelRunApi: {
    sources: m.sources, backups: m.backups, history: m.history, get: m.get, run: m.run,
    classify: m.classify, close: m.close, reopen: m.reopen, remove: m.remove,
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number) => String(v),
  formatDateTime: (v: string) => v,
}))
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: vi.fn() }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a class="router-link"><slot /></a>' },
}))

import ParallelRun from '@/pages/accounting/ParallelRun.vue'

function makeCheck(overrides: Partial<ParallelRunCheck> = {}): ParallelRunCheck {
  return {
    id: 7, month: '2099-10', source: 'money_s3', status: 'differences', cycle_status: 'open',
    inputs: [{ kind: 'saldo', name: 'saldo.csv', sha256: 'abc', size: 10 }],
    classifications: {}, note: null, created_by: 1, created_at: '2099-11-02 10:00:00', closed_by: null, closed_at: null,
    result: {
      month: '2099-10', period: { id: 1, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31' },
      as_of: '2099-10-31', source: 'money_s3', status: 'differences', warnings: [], inputs: [],
      criteria: [
        { key: 'K1', status: 'ok', tolerance: 'cent', summary: {}, difference_count: 0, differences: [] },
        {
          key: 'K7', status: 'differences', tolerance: 'cent', summary: {}, difference_count: 1,
          differences: [{
            id: 'K7:311|FV1', subject: 'FV1', label: 'Beta a.s.', note: 'open_only_in_myucto',
            values: [{ field: 'remaining', mine: 1210, theirs: null }], link: { type: 'invoice', id: 55 },
          }],
        },
      ],
    },
    classification: { differences: 1, unclassified: 1, source: 0, migration: 0, interpretation: 0, errors: 0, can_close: false },
    ...overrides,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  m.canWrite = true
  m.sources.mockResolvedValue({ sources: [{ key: 'money_s3', inputs: [], reads_backup: true }, { key: 'pohoda', inputs: [], reads_backup: false }], inputs: {}, categories: [] })
  m.backups.mockResolvedValue([])
  m.history.mockResolvedValue([{ ...makeCheck(), classification: { source: 0, migration: 0, interpretation: 0 } }])
  m.get.mockResolvedValue(makeCheck())
})

describe('ParallelRun', () => {
  it('otevře poslední kontrolu a ukáže rozdíl s odkazem na doklad', async () => {
    const w = mount(ParallelRun, { global: { stubs: { ActionBar: true, EmptyState: true } } })
    await flushPromises()

    expect(m.get).toHaveBeenCalledWith(7)
    const k7 = w.find('[data-test="criterion-K7"]')
    expect(k7.text()).toContain('FV1')
    expect(k7.text()).toContain('parallelRun.notes.open_only_in_myucto')
    expect(k7.find('.router-link').exists()).toBe(true)
    // Kritérium bez rozdílů je sbalené, rozdíly rozbalené.
    expect(w.find('[data-test="criterion-K1"] table').exists()).toBe(false)
  })

  it('zařazení rozdílu volá API a promítne nový stav', async () => {
    m.classify.mockResolvedValue(makeCheck({
      classifications: { 'K7:311|FV1': { category: 'source', note: null, by: 1, at: '2099-11-02' } },
      classification: { differences: 1, unclassified: 0, source: 1, migration: 0, interpretation: 0, errors: 0, can_close: true },
    }))
    const w = mount(ParallelRun, { global: { stubs: { ActionBar: true, EmptyState: true } } })
    await flushPromises()

    await w.find('[data-test="classify-K7:311|FV1"]').setValue('source')
    await flushPromises()

    expect(m.classify).toHaveBeenCalledWith(7, 'K7:311|FV1', 'source', null)
    expect((w.find('[data-test="classify-K7:311|FV1"]').element as HTMLSelectElement).value).toBe('source')
  })

  it('výběr zálohy nabízí jen program, který ji umí číst', async () => {
    const w = mount(ParallelRun, { global: { stubs: { ActionBar: true, EmptyState: true } } })
    await flushPromises()
    expect(w.find('[data-test="backup"]').exists()).toBe(true)

    await w.find('[data-test="source"]').setValue('pohoda')
    expect(w.find('[data-test="backup"]').exists()).toBe(false)
  })

  it('bez práva zápisu není formulář nové kontroly a zařazení je zamčené', async () => {
    m.canWrite = false
    const w = mount(ParallelRun, { global: { stubs: { ActionBar: true, EmptyState: true } } })
    await flushPromises()

    expect(w.find('[data-test="new-check"]').exists()).toBe(false)
    expect((w.find('[data-test="classify-K7:311|FV1"]').element as HTMLSelectElement).disabled).toBe(true)
  })
})
