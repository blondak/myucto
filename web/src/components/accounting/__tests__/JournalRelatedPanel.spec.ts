import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => key }),
}))

const relatedMock = vi.fn()
const notesMock = vi.fn()
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    getJournalRelated: (...args: unknown[]) => relatedMock(...args),
    getEntry: async () => ({ lines: [] }),
    listJournalNotes: (...args: unknown[]) => notesMock(...args),
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true }) }))
vi.mock('@/components/accounting/JournalLinesTable.vue', () => ({
  default: { name: 'JournalLinesTable', props: ['lines', 'dense', 'contextDate'], template: '<div />' },
}))

import JournalRelatedPanel from '@/components/accounting/JournalRelatedPanel.vue'

function payment(entryId: number | null) {
  return {
    relation: 'payment', source_type: 'bank', source_id: 77, entry_id: entryId, title: 'BV1', subtitle: null,
    date: '2026-09-09', amount: 179, allocated_amount: 179, currency: 'CZK', route: null, permission: 'bank',
  }
}

function mountPanel() {
  return mount(JournalRelatedPanel, {
    props: { entryId: 10 },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

describe('JournalRelatedPanel', () => {
  it('ukáže poznámku zápisu úhrady i u dokladu', async () => {
    relatedMock.mockResolvedValueOnce({ items: [payment(29216)], truncated: false })
    notesMock.mockResolvedValueOnce([{ id: 1, entry_id: 29216, body: 'test', pinned: false }])
    const wrapper = mountPanel()
    await flushPromises()
    expect(notesMock).toHaveBeenCalledWith(29216)
    expect(wrapper.find('[data-test="related-entry-notes"]').text()).toContain('test')
  })

  it('proforma bez zápisu má neutrální štítek, ne „Nezaúčtováno"', async () => {
    relatedMock.mockResolvedValueOnce({
      items: [
        { ...payment(null), relation: 'document', source_type: 'invoice', source_id: 5, postable: false },
        { ...payment(null), relation: 'document', source_type: 'invoice', source_id: 6, postable: true },
      ],
      truncated: false,
    })
    const wrapper = mountPanel()
    await flushPromises()
    const text = wrapper.text()
    expect(wrapper.findAll('[data-test="related-advance-not-postable"]')).toHaveLength(1)
    expect(text).toContain('accounting.journal.related.advance_not_postable')
    expect(text.split('accounting.journal.related.not_posted').length - 1).toBe(1)
  })

  it('nezaúčtovaný protějšek poznámky nenačítá', async () => {
    notesMock.mockClear()
    relatedMock.mockResolvedValueOnce({ items: [payment(null)], truncated: false })
    const wrapper = mountPanel()
    await flushPromises()
    expect(notesMock).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="related-entry-notes"]').exists()).toBe(false)
  })
})
