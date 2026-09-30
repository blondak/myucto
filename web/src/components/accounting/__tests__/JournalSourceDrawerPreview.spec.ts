import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({ source: vi.fn(), preview: vi.fn() }))
vi.mock('@/api/accounting', () => ({ accountingApi: { getJournalSource: mocks.source } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { preview: mocks.preview } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
import JournalSourceDrawer from '../JournalSourceDrawer.vue'

const summary = {
  entry_id: 0, source_type: 'purchase_invoice', source_id: 10, available: true,
  title: 'Synthetic preview', subtitle: null, status: null, currency: 'CZK',
  fields: [], blocks: [], route: null, actions: [],
}
const stubs = {
  Drawer: { template: '<div><slot /></div>' }, ActionBar: true,
  SourceBlockRenderer: true, JournalRelatedPanel: true, LinkedDocumentsPanel: true,
}
beforeEach(() => {
  mocks.source.mockReset().mockResolvedValue({ ...summary, entry_id: 20 })
  mocks.preview.mockReset().mockResolvedValue(summary)
})

it('loads an unposted purchase preview once and skips journal relations', async () => {
  const wrapper = mount(JournalSourceDrawer, { props: { purchaseInvoiceId: 10 }, global: { stubs } })
  await flushPromises()
  expect(mocks.preview).toHaveBeenCalledExactlyOnceWith(10)
  expect(mocks.source).not.toHaveBeenCalled()
  expect(wrapper.findComponent({ name: 'JournalRelatedPanel' }).exists()).toBe(false)
  await wrapper.setProps({ purchaseInvoiceId: 11 })
  await flushPromises()
  expect(mocks.preview).toHaveBeenLastCalledWith(11)
  expect(mocks.preview).toHaveBeenCalledTimes(2)
  wrapper.unmount()
})

it('keeps the journal drawer path and follows a posted purchase relation', async () => {
  const journal = mount(JournalSourceDrawer, { props: { entryId: 20 }, global: { stubs } })
  await flushPromises()
  expect(mocks.source).toHaveBeenCalledExactlyOnceWith(20)
  expect(mocks.preview).not.toHaveBeenCalled()
  journal.unmount()
  mocks.preview.mockResolvedValue({ ...summary, entry_id: 20 })
  const purchase = mount(JournalSourceDrawer, { props: { purchaseInvoiceId: 10 }, global: { stubs } })
  await flushPromises()
  const related = purchase.findComponent({ name: 'JournalRelatedPanel' })
  expect(related.props('entryId')).toBe(20)
  related.vm.$emit('preview', 21)
  await flushPromises()
  expect(mocks.source).toHaveBeenLastCalledWith(21)
  await purchase.find('button').trigger('click')
  await flushPromises()
  expect(mocks.preview).toHaveBeenCalledTimes(2)
  purchase.unmount()
})
