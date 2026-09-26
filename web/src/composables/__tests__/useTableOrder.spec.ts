import { computed, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TablePrefs } from '@/api/preferences'
import { useTablePrefs, type TablePrefsCtrl, type ColumnDef } from '@/composables/useTablePrefs'
import { useColumnDrag } from '@/composables/useColumnDrag'

const pages = ref<Record<string, TablePrefs>>({})
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: (key: string) => computed(() => pages.value[key] ?? {}),
  patchPagePrefs: (key: string, patch: Partial<TablePrefs>) => { pages.value[key] = { ...pages.value[key], ...patch } },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const columns: ColumnDef[] = [
  { key: 'number', labelKey: 'number', required: true },
  { key: 'client', labelKey: 'client' },
  { key: 'due', labelKey: 'due', defaultHidden: true },
  { key: 'amount', labelKey: 'amount' },
]

function setup(page = 'invoices') {
  let ctrl!: TablePrefsCtrl
  const wrapper = mount({
    setup() {
      ctrl = useTablePrefs(page, columns)
      return { ctrl, drag: useColumnDrag(ctrl) }
    },
    template: '<table><thead><tr><th v-for="c in ctrl.orderedColumns.value" :key="c.key" v-bind="drag.headerAttrs(c.key)">{{ c.key }}</th></tr></thead><tbody><tr><td v-for="c in ctrl.orderedColumns.value" :key="c.key">{{ c.key }}</td></tr></tbody></table>',
  })
  return { ctrl, wrapper }
}

describe('table column order', () => {
  beforeEach(() => { pages.value = {} })

  it('restores saved order, ignores old and duplicate keys and appends new columns', () => {
    pages.value.invoices = { column_order: ['amount', 'removed', 'amount', 'number'] }
    const { ctrl, wrapper } = setup()
    expect(ctrl.orderedColumns.value.map(c => c.key)).toEqual(['amount', 'number', 'client', 'due'])
    expect(wrapper.findAll('th').map(th => th.text())).toEqual(wrapper.findAll('td').map(td => td.text()))
    wrapper.unmount()
  })

  it('moves in either direction, keeps hidden columns and resets only order', () => {
    pages.value.journal = { hidden: ['client'], column_colors: { amount: '#123456' }, density: 'compact' }
    const { ctrl, wrapper } = setup('journal')
    ctrl.moveColumn('amount', 'number')
    expect(pages.value.journal.column_order).toEqual(['amount', 'number', 'client', 'due'])
    ctrl.moveColumn('amount', 'due', true)
    expect(pages.value.journal.column_order).toEqual(['number', 'client', 'due', 'amount'])
    ctrl.moveColumn('unknown', 'number')
    ctrl.moveColumn('number', 'number')
    expect(pages.value.journal.column_order).toEqual(['number', 'client', 'due', 'amount'])
    ctrl.resetColumnOrder()
    expect(pages.value.journal).toEqual({ hidden: ['client'], column_colors: { amount: '#123456' }, density: 'compact', column_order: null })
    expect(pages.value.invoices).toBeUndefined()
    wrapper.unmount()
  })

  it('accepts its own drag, highlights the drop position and keeps header and cells aligned', async () => {
    const { ctrl, wrapper } = setup()
    await flushPromises()
    const transfer = { setData: vi.fn(), effectAllowed: '', dropEffect: '' }
    await wrapper.get('th[data-column-key="amount"]').trigger('dragstart', { dataTransfer: transfer })
    expect(transfer.effectAllowed).toBe('move')
    const destination = wrapper.get('th[data-column-key="number"]')
    await destination.trigger('dragover', { dataTransfer: transfer, clientX: 0 })
    expect(destination.attributes('data-column-drop')).toBe('before')
    await destination.trigger('drop', { dataTransfer: transfer })
    expect(ctrl.orderedColumns.value.map(c => c.key)).toEqual(['amount', 'number', 'client', 'due'])
    expect(wrapper.findAll('th').map(th => th.text())).toEqual(wrapper.findAll('td').map(td => td.text()))
    expect(wrapper.find('[data-column-drop]').exists()).toBe(false)
    wrapper.unmount()
  })
})
