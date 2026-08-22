import { useRouter, type RouteLocationRaw } from 'vue-router'
import { useWorkspaceStore, type PaneCount, type PaneId } from '@/stores/workspace'
import { getPaneRuntime } from '@/workspace/runtimeRegistry'

export function useWorkspaceNavigation() {
  const router = useRouter()
  const workspace = useWorkspaceStore()

  async function navigate(to: RouteLocationRaw): Promise<void> {
    const runtime = getPaneRuntime(workspace.activePaneId)
    if (!runtime) {
      await router.push(to)
      return
    }
    await runtime.navigate(to)
  }

  function openExternal(url: string): void {
    window.open(url, '_blank', 'noopener')
  }

  function activatePane(id: PaneId): void {
    workspace.activatePane(id)
  }

  async function setPaneCount(count: PaneCount): Promise<void> {
    if (![1, 2, 3].includes(count)) throw new RangeError('Pane count must be 1, 2, or 3.')
    if (count === workspace.paneCount) return

    workspace.resizeLayout(count)
  }

  function back(id: PaneId = workspace.activePaneId): void {
    getPaneRuntime(id)?.back()
  }

  function forward(id: PaneId = workspace.activePaneId): void {
    getPaneRuntime(id)?.forward()
  }

  function closePaneContent(id: PaneId = workspace.activePaneId): void {
    const pane = workspace.panes.find(candidate => candidate.id === id)
    if (!pane) return
    if (!pane.fullPath && workspace.removeEmptyPane(id)) return
    getPaneRuntime(id)?.clear()
  }

  /**
   * Zavře panel — kterýkoli, ne jen ten poslední.
   *
   * Zavřít první panel dřív nešlo, protože `primary` je slot s opravdovým
   * routerem aplikace a odebrat ho nelze. Uživatele to ale nezajímá: má tři
   * panely, chce zavřít levý a čeká, že se z prostředního stane levý.
   *
   * Řeší se to posunem OBSAHU doleva místo přečíslování slotů: každý panel od
   * zavíraného dál převezme cestu svého souseda zprava a nakonec se odebere
   * osiřelý slot na konci. Sloty tím zůstanou na místě, takže se nic
   * neodmountuje a adresa v prohlížeči odpovídá tomu, co je v prvním panelu.
   *
   * Poslední zbývající panel se nezavírá — není kam se posunout, takže se jen
   * vyprázdní.
   */
  async function closePane(id: PaneId = workspace.activePaneId): Promise<void> {
    const index = workspace.panes.findIndex(candidate => candidate.id === id)
    if (index < 0) return
    if (workspace.panes.length <= 1) {
      closePaneContent(id)
      return
    }

    // Stav se přečte dopředu: navigace panelu i přepíše jeho vlastní záznam
    // ve store a bez kopie by se pak četla už posunutá hodnota.
    const moved = workspace.panes.map(pane => ({ fullPath: pane.fullPath, title: pane.title }))
    for (let i = index; i < workspace.panes.length - 1; i++) {
      const pane = workspace.panes[i]!
      const runtime = getPaneRuntime(pane.id)
      if (!runtime) continue
      const next = moved[i + 1]!
      if (next.fullPath) await runtime.navigate(next.fullPath)
      else runtime.clear()
      // Titulek se musí přesunout s obsahem. AppLayout ho dopočítává jen
      // AKTIVNÍMU panelu při změně jeho adresy, takže panelům, kterých se
      // posun týká vedle aktivního, by v záhlaví zůstal název cizí stránky.
      workspace.updatePane(pane.id, { title: next.title })
    }

    workspace.dropLastPane()
  }

  return { navigate, openExternal, activatePane, setPaneCount, back, forward, closePaneContent, closePane }
}
