import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import PohodaMigration from '../PohodaMigration.vue'

const m = vi.hoisted(() => ({ toolFiles: vi.fn(), wizard: null as any }))

vi.mock('@/composables/useMigrationWizard', () => ({ useMigrationWizard: () => m.wizard }))
vi.mock('@/api/pohoda', () => ({ pohodaApi: { toolFiles: m.toolFiles }, isPohodaUploadReady: () => true }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { ic: '12345678', company_name: 'Syntetická firma' } }) }))
vi.mock('@/components/settings/CompanyProfileBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/MoneyS3Protocol.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/exchange/ImportJobProgress.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/ActionBar.vue', () => ({ default: { props: ['actions'], template: '<div />' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string) => key,
  // Každý klíč existuje ve vlastním prostoru průvodce, takže text ukáže, odkud se bral.
  te: () => true, tm: () => [], rt: (value: string) => value,
}) }))

const POHODA_FILES = [
  'Export-Pohoda.cmd', 'Export-Pohoda.ps1', 'Export-PohodaMdb.cmd', 'Export-PohodaMdb.ps1', 'Export-PohodaMdbAccounting.cmd',
  'Export-PohodaMdbAccounting.ps1', 'Export-PohodaSQL.cmd', 'Export-PohodaSQL.ps1', 'Pohoda-Common.ps1', 'PohodaSql-Common.ps1',
  'pohoda-sql.example.json',
]
const PAMICA_FILES = ['Export-Pamica.cmd', 'Export-Pamica.ps1', 'Export-PamicaSQL.cmd', 'Export-PamicaSQL.ps1', 'PohodaSql-Common.ps1', 'pamica-sql.example.json']

function wizard() {
  return {
    currentStep: ref(1), file: ref(null), job: ref(null), jobMode: ref(null), run: ref(null), jobRuns: ref([]), runs: ref([]),
    busy: ref(false), cancelling: ref(false), confirmed: ref(false), dryRunPassed: ref(false), uploadPercent: ref(null),
    processing: ref(false), processingProgress: ref(null), processingSlow: ref(false), loadError: ref(null), deletingRun: ref(null),
    jobRunning: ref(false), jobSucceeded: ref(false), percent: ref(0), upload: ref(null),
    canGoTo: () => true, goTo: vi.fn(), onFile: vi.fn(), doUpload: vi.fn(), resetUpload: vi.fn(), retryUpload: vi.fn(), abandonUpload: vi.fn(),
    start: vi.fn(), cancel: vi.fn(), showRun: vi.fn(), deleteRun: vi.fn(), errorMessage: (_e: unknown, fallback: string) => fallback,
  }
}

async function openTools(system: 'pohoda' | 'pamica', files: string[]) {
  m.wizard = wizard()
  m.toolFiles.mockResolvedValue({ files: files.map(name => ({ name, size: 1024 })) })
  const wrapper = mount(PohodaMigration, {
    props: { system },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
  })
  await wrapper.get('[data-testid="pohoda-tool-toggle"]').trigger('click')
  await flushPromises()
  return wrapper
}

function groupFiles(wrapper: Awaited<ReturnType<typeof openTools>>, key: string): string[] {
  return wrapper.get(`[data-testid="pohoda-tool-group-${key}"]`).findAll('.font-mono').map(node => node.text())
}

beforeEach(() => vi.clearAllMocks())

describe('Přechod z POHODA: export z POHODA SQL', () => {
  it('ukáže třetí cestu s požadavky a odkazem na ODBC ovladač', async () => {
    const wrapper = await openTools('pohoda', POHODA_FILES)
    const help = wrapper.get('[data-testid="pohoda-sql-help"]')
    expect(help.text()).toContain('pohoda.sql_help_title')
    expect(help.text()).toContain('pohoda.sql_help_requirements')
    expect(help.get('a').attributes('href')).toBe('https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server')
    expect(wrapper.find('[data-testid="pohoda-mdb-help"]').exists()).toBe(true)
  })

  it('nabídne skupinu nástrojů SQL se vzorem konfigurace a podpůrnými skripty', async () => {
    const wrapper = await openTools('pohoda', POHODA_FILES)
    expect(groupFiles(wrapper, 'sql')).toEqual([
      'Export-PohodaSQL.cmd', 'Export-PohodaSQL.ps1', 'pohoda-sql.example.json', 'Pohoda-Common.ps1', 'PohodaSql-Common.ps1', 'Export-PohodaMdb.ps1',
    ])
    expect(groupFiles(wrapper, 'mdb')).toContain('Pohoda-Common.ps1')
    const sql = wrapper.get('[data-testid="pohoda-tool-group-sql"]').text()
    expect(sql).toContain('pohoda.tool_group_sql_title')
    expect(sql).toContain('pohoda.tool_role_config')
    expect(sql).toContain('pohoda.tool_role_launcher')
    expect(sql).toContain('pohoda.tool_role_shared')
    expect(m.toolFiles).toHaveBeenCalledWith('pohoda')
  })
})

describe('Přechod z PAMICA: export z PAMICA SQL', () => {
  it('ukáže cestu PAMICA SQL a nástroje po skupinách', async () => {
    const wrapper = await openTools('pamica', PAMICA_FILES)
    expect(wrapper.get('[data-testid="pohoda-sql-help"]').text()).toContain('pamica.sql_help_title')
    expect(wrapper.find('[data-testid="pohoda-mdb-help"]').exists()).toBe(false)
    expect(groupFiles(wrapper, 'mdb')).toEqual(['Export-Pamica.cmd', 'Export-Pamica.ps1'])
    expect(groupFiles(wrapper, 'sql')).toEqual(['Export-PamicaSQL.cmd', 'Export-PamicaSQL.ps1', 'pamica-sql.example.json', 'PohodaSql-Common.ps1', 'Export-Pamica.ps1'])
    expect(wrapper.get('[data-testid="pohoda-tool-group-sql"]').text()).toContain('pamica.tool_role_config')
    expect(m.toolFiles).toHaveBeenCalledWith('pamica')
  })
})
