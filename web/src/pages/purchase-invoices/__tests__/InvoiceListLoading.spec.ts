import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  defaultQuery: null as Record<string, string> | null,
  apply: null as ((query: Record<string, string>) => void) | null,
}))
vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: { listGrouped: mocks.list, listImportBatches: async () => [], get: vi.fn() },
}))
vi.mock('@/api/clients', () => ({ clientsApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/projects', () => ({ projectsApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/accounting', () => ({ accountingApi: {}, postingErrorI18nKey: () => '' }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isClientRole: false, canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [] }))
vi.mock('@/composables/useListKeyboard', () => ({ useListKeyboard: () => ({ activeIndex: -1 }) }))
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: () => ({ isVisible: () => true }) }))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: (_page: string, opts: { applyQuery: (query: Record<string, string>) => void }) => {
    mocks.apply = opts.applyQuery
    return {
      activeId: { value: null },
      applyDefaultIfAny: async () => {
        if (!mocks.defaultQuery) return false
        opts.applyQuery(mocks.defaultQuery)
        return true
      },
    }
  },
  savedFilterTone: () => 'neutral',
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
import InvoiceList from '../InvoiceList.vue'

async function open(query: Record<string, string> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/purchase-invoices', component: { render: () => null } }] })
  await router.push({ path: '/purchase-invoices', query })
  const wrapper = mount({ ...InvoiceList, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('purchase invoice list loading', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mocks.defaultQuery = null
    mocks.list.mockReset().mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
  })
  afterEach(() => { vi.useRealTimers() })

  it.each<Record<string, string>>([{}, { year: 'all' }, { year: 'all', vendor: '7', q: 'synthetic' }])('loads the list once when opened with query %j', async query => {
    const { wrapper } = await open(query)
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('applies a saved default and another saved view once each', async () => {
    mocks.defaultQuery = { year: 'all', unpaid: '1' }
    const { wrapper } = await open()
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(1)
    mocks.apply!({ status: 'paid' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(2)
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'paid' }))
    wrapper.unmount()
  })

  it('keeps the newest result when an older response arrives later', async () => {
    let resolveOld: (v: unknown) => void = () => {}
    mocks.list.mockReset()
      .mockImplementationOnce(() => new Promise(r => { resolveOld = r }))
      .mockResolvedValue({ data: [{ month: '2099-02', invoices: [] }], meta: { total: 5, pages: 1 } })
    const { wrapper } = await open()
    const vm = wrapper.vm as unknown as { statusFilter: string; total: number }
    vm.statusFilter = 'paid'
    await flushPromises()
    resolveOld({ data: [], meta: { total: 99, pages: 1 } })
    await flushPromises()
    expect(vm.total).toBe(5)
    wrapper.unmount()
  })
})
