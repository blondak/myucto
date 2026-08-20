import { computed, inject, type ComputedRef, type InjectionKey } from 'vue'
import { useWorkspaceStore, type PaneId } from '@/stores/workspace'

export const paneIdKey: InjectionKey<PaneId> = Symbol('workspace-pane-id')

export function usePaneId(): PaneId | null {
  return inject(paneIdKey, null)
}

export function usePaneActivity(): ComputedRef<boolean> {
  const paneId = usePaneId()
  if (paneId === null) return computed(() => true)
  const workspace = useWorkspaceStore()
  return computed(() => workspace.activePaneId === paneId)
}
