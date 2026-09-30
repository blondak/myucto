import { computed, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TablePrefs } from '@/api/preferences'
import { useTablePrefs, type TablePrefsCtrl } from '@/composables/useTablePrefs'

const pages = ref<Record<string, TablePrefs>>({})
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: (key: string) => computed(() => pages.value[key] ?? {}),
  patchPagePrefs: (key: string, patch: Partial<TablePrefs>) => { pages.value[key] = { ...pages.value[key], ...patch } },
}))

function setup(page = 'invoices') {
  let ctrl!: TablePrefsCtrl
  const wrapper = mount({
    setup() {
      ctrl = useTablePrefs(page, [{ key: 'number', labelKey: 'number', required: true }])
      return () => h('div')
    },
  })
  return { ctrl, wrapper }
}

describe('column label preferences', () => {
  beforeEach(() => { pages.value = {} })

  it('uses the table layout default until the user saves an explicit choice', () => {
    const { ctrl, wrapper } = setup()
    expect(ctrl.showColumnLabels.value).toBe(false)
    ctrl.columnLabelsDefault.value = true
    expect(ctrl.showColumnLabels.value).toBe(true)
    ctrl.setColumnLabels(false)
    expect(ctrl.showColumnLabels.value).toBe(false)
    ctrl.columnLabelsDefault.value = false
    ctrl.columnLabelsDefault.value = true
    expect(ctrl.showColumnLabels.value).toBe(false)
    expect(pages.value.invoices.flags).toEqual({ column_labels: false })
    wrapper.unmount()
  })

  it('restores an explicit choice per page and preserves all other preferences', () => {
    const original: TablePrefs = {
      flags: { analytics: true }, hidden: ['note'], shown: ['country'],
      density: 'compact', sort: { key: 'number', dir: 'asc' },
      column_order: ['number'], column_colors: { number: '#123456' },
    }
    pages.value.invoices = original
    const first = setup()
    first.ctrl.setColumnLabels(true)
    expect(pages.value.invoices).toEqual({ ...original, flags: { analytics: true, column_labels: true } })
    first.wrapper.unmount()
    const restored = setup()
    const otherPage = setup('journal')
    expect(restored.ctrl.showColumnLabels.value).toBe(true)
    expect(otherPage.ctrl.showColumnLabels.value).toBe(false)
    restored.ctrl.setFlag('analytics', false)
    expect(pages.value.invoices.flags).toEqual({ analytics: false, column_labels: true })
    restored.wrapper.unmount()
    otherPage.wrapper.unmount()
  })

  it('allows saved false to override a multiline default and null flags to use it', () => {
    pages.value.invoices = { flags: { column_labels: false } }
    const { ctrl, wrapper } = setup()
    ctrl.columnLabelsDefault.value = true
    expect(ctrl.showColumnLabels.value).toBe(false)
    pages.value.invoices.flags = null
    expect(ctrl.showColumnLabels.value).toBe(true)
    wrapper.unmount()
  })
})
