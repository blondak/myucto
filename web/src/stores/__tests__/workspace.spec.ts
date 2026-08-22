import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useWorkspaceStore } from '@/stores/workspace'

describe('workspace store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('začíná v jednom panelu bez persistence', () => {
    const store = useWorkspaceStore()

    expect(store.paneCount).toBe(1)
    expect(store.activePaneId).toBe('primary')
    expect(store.panes).toHaveLength(1)
    expect(store.activeFullPath).toBe('/')
    expect(Object.keys(localStorage)).toHaveLength(0)
  })

  it.each([1, 2, 3] as const)('resetuje layout na %i panel(y)', (count) => {
    const store = useWorkspaceStore()
    store.resetLayout(count, '/purchase-invoices/42')

    expect(store.paneCount).toBe(count)
    expect(store.panes.map(pane => pane.id)).toEqual([
      'primary',
      ...(count >= 2 ? ['secondary-2'] : []),
      ...(count >= 3 ? ['secondary-3'] : []),
    ])
    expect(store.activePaneId).toBe('primary')
    expect(store.activeFullPath).toBe('/purchase-invoices/42')
  })

  it('změní počet panelů bez vyčištění obsahu zachovaných panelů', () => {
    const store = useWorkspaceStore()
    store.resetLayout(2, '/invoices/7/edit')
    store.activatePane('secondary-2')
    store.updatePane('secondary-2', { fullPath: '/accounting/journal', title: 'Deník' })
    const revision = store.layoutRevision

    store.resizeLayout(3)

    expect(store.layoutRevision).toBe(revision)
    expect(store.panes).toHaveLength(3)
    expect(store.panes[0]).toMatchObject({ id: 'primary', fullPath: '/invoices/7/edit' })
    expect(store.panes[1]).toMatchObject({ id: 'secondary-2', fullPath: '/accounting/journal', title: 'Deník' })
    expect(store.panes[2]).toMatchObject({ id: 'secondary-3', fullPath: null, title: null })
    expect(store.activePaneId).toBe('secondary-3')
  })

  it('po rozšíření aktivuje první prázdný vedlejší panel pro navigaci z menu', () => {
    const store = useWorkspaceStore()
    store.resetLayout(1, '/invoices/7/edit')

    store.resizeLayout(2)

    expect(store.activePaneId).toBe('secondary-2')
    expect(store.activePane.fullPath).toBeNull()
  })

  it('při rozšíření přednostně použije už existující prázdný panel', () => {
    const store = useWorkspaceStore()
    store.resetLayout(2, '/invoices')

    store.resizeLayout(3)

    expect(store.activePaneId).toBe('secondary-2')
  })

  it('neaktivuje neexistující panel, ale aktivace existujícího funguje', () => {
    const store = useWorkspaceStore()
    store.resetLayout(2, '/invoices')
    store.activatePane('secondary-2')
    expect(store.activePaneId).toBe('secondary-2')
    store.activatePane('secondary-3')
    expect(store.activePaneId).toBe('secondary-2')
  })

  it('publikuje nejvyšší rozložení dostupné podle šířky pracovního prostoru', () => {
    const store = useWorkspaceStore()
    store.setMaximumPaneCount(3)

    expect(store.maximumPaneCount).toBe(3)
  })

  it('odebere prázdný vedlejší panel bez změny obsahu ostatních panelů', () => {
    const store = useWorkspaceStore()
    store.resetLayout(3, '/invoices')
    store.updatePane('secondary-3', { fullPath: '/accounting/journal', title: 'Deník' })
    store.activatePane('secondary-2')

    expect(store.removeEmptyPane('secondary-2')).toBe(true)
    expect(store.paneCount).toBe(2)
    expect(store.panes.map(pane => [pane.id, pane.fullPath])).toEqual([
      ['primary', '/invoices'],
      ['secondary-3', '/accounting/journal'],
    ])
    expect(store.activePaneId).toBe('primary')
  })

  it('neodebere plný ani primární panel', () => {
    const store = useWorkspaceStore()
    store.resetLayout(2, '/invoices')
    store.updatePane('secondary-2', { fullPath: '/clients' })

    expect(store.removeEmptyPane('primary')).toBe(false)
    expect(store.removeEmptyPane('secondary-2')).toBe(false)
    expect(store.paneCount).toBe(2)
  })

  it('zahodí poslední slot a přepne aktivní panel, když se zavíral', () => {
    const store = useWorkspaceStore()
    store.resetLayout(3, '/invoices')
    store.activatePane('secondary-3')

    expect(store.dropLastPane()).toBe(true)

    expect(store.panes.map(pane => pane.id)).toEqual(['primary', 'secondary-2'])
    expect(store.paneCount).toBe(2)
    expect(store.activePaneId).toBe('secondary-2')
  })

  it('poslední zbývající panel nezahodí', () => {
    const store = useWorkspaceStore()
    store.resetLayout(1, '/invoices')

    expect(store.dropLastPane()).toBe(false)
    expect(store.panes).toHaveLength(1)
  })})
