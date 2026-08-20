import { getPaneRuntime } from '@/workspace/runtimeRegistry'
import { usePaneId } from '@/workspace/paneActivity'

export function usePaneDom() {
  const paneId = usePaneId()

  function root(): ParentNode {
    return paneId ? (getPaneRuntime(paneId)?.root ?? document) : document
  }

  function querySelector<T extends Element = Element>(selector: string): T | null {
    return root().querySelector<T>(selector)
  }

  function querySelectorAll<T extends Element = Element>(selector: string): NodeListOf<T> {
    return root().querySelectorAll<T>(selector)
  }

  return { root, querySelector, querySelectorAll }
}
