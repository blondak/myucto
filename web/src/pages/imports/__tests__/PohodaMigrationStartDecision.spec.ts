import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import PohodaMigration from '../PohodaMigration.vue'

const m = vi.hoisted(() => ({ start: vi.fn(), options: null as any, wizard: null as any }))

vi.mock('@/composables/useMigrationWizard', () => ({
  useMigrationWizard: (options: unknown) => {
    m.options = options
    return m.wizard
  },
}))
vi.mock('@/api/pohoda', () => ({ pohodaApi: {}, isPohodaUploadReady: () => true }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { ic: '12345678', company_name: 'Syntetická firma' } }) }))
vi.mock('@/components/settings/CompanyProfileBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/MoneyS3Protocol.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/exchange/ImportJobProgress.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/ActionBar.vue', () => ({ default: {
  props: ['actions'],
  template: '<div><button v-for="a in actions" :key="a.key" :data-testid="`action-${a.key}`" :disabled="a.disabled" :title="a.disabled ? a.disabledReason : undefined" @click="a.run && a.run()">{{ a.label }}</button></div>',
} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, params?: Record<string, unknown>) => params && Object.keys(params).length ? `${key} ${JSON.stringify(params)}` : key,
  te: () => false, tm: () => [], rt: (value: string) => value,
}) }))

const behind = {
  level: 'warning', code: 'payroll_start_behind_takeover', message: 'Synthetic start behind',
  context: { from: '2026-06', to: '2026-09', last: '2026-08' },
}

function wizard(messages: unknown[]) {
  return {
    currentStep: ref(2), file: ref(null), job: ref(null), jobMode: ref(null), run: ref(null), jobRuns: ref([]), runs: ref([]),
    busy: ref(false), cancelling: ref(false), confirmed: ref(false), dryRunPassed: ref(false), uploadPercent: ref(null),
    processing: ref(false), processingProgress: ref(null), processingSlow: ref(false), loadError: ref(null), deletingRun: ref(null),
    jobRunning: ref(false), jobSucceeded: ref(false), percent: ref(0),
    differencesAcceptable: ref(false), differences: ref([]), acceptDifferences: ref(false), canImport: ref(false), clearDifferences: () => {},
    upload: ref({
      token: 'synthetic', supplier_ico: '12345678', default_year: 2026, preflight: {},
      agendas: [{ ico: '12345678', company: '', year: 2026, has_payroll: true, has_accounting: false, files: [], counts: {}, payroll: { employees: 2, months: 8 } }],
      payroll_preflight: { 2026: messages },
    }),
    canGoTo: () => true, goTo: vi.fn(), onFile: vi.fn(), doUpload: vi.fn(), resetUpload: vi.fn(), retryUpload: vi.fn(), abandonUpload: vi.fn(),
    start: m.start, cancel: vi.fn(), showRun: vi.fn(), deleteRun: vi.fn(), errorMessage: (_e: unknown, fallback: string) => fallback,
  }
}

async function mountPage(messages: unknown[]) {
  m.wizard = wizard(messages)
  const wrapper = mount(PohodaMigration, {
    props: { system: 'pamica' },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
  })
  m.options.onReady()
  await flushPromises()
  return wrapper
}

async function dryRun(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  await wrapper.get('[data-testid="action-continue"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-testid="action-dry"]').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  vi.clearAllMocks()
  m.start.mockResolvedValue(undefined)
})

describe('PAMICA: začátek vedení mezd před zpracovanými měsíci', () => {
  it('nabídne posun jako výchozí volbu a pošle ho s převodem', async () => {
    const wrapper = await mountPage([behind])
    const box = wrapper.get('[data-testid="pohoda-start-decision"]')
    expect(box.text()).toContain('"period":"09/2026"')
    expect((wrapper.get('[data-testid="pohoda-start-advance"]').element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.get('[data-testid="action-continue"]').attributes('disabled')).toBeUndefined()
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ start_decision: 'advance' }))
  })

  it('převod bez posunu pustí až po vědomém potvrzení', async () => {
    const wrapper = await mountPage([behind])
    await wrapper.get('[data-testid="pohoda-start-keep"]').setValue(true)
    const next = wrapper.get('[data-testid="action-continue"]')
    expect(next.attributes('disabled')).toBeDefined()
    expect(next.attributes('title')).toBe('pohoda.payroll_start.keep_confirm_first')
    await wrapper.get('[data-testid="pohoda-start-keep-confirm"]').setValue(true)
    expect(wrapper.get('[data-testid="action-continue"]').attributes('disabled')).toBeUndefined()
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ start_decision: 'keep' }))
  })

  it('bez kontroly začátku volbu neukazuje ani neposílá', async () => {
    const wrapper = await mountPage([{ level: 'info', code: 'payroll_summary', message: 'Synthetic summary', context: {} }])
    expect(wrapper.find('[data-testid="pohoda-start-decision"]').exists()).toBe(false)
    await dryRun(wrapper)
    expect(m.start.mock.calls[0][1]).not.toHaveProperty('start_decision')
  })
})
