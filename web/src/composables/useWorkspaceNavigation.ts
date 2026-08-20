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

  return { navigate, openExternal, activatePane, setPaneCount, back, forward, closePaneContent }
}
