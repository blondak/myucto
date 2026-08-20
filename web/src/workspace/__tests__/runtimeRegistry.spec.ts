import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Router } from 'vue-router'
import {
  destroyPaneRuntimes,
  getPaneRuntime,
  registerPaneRuntime,
  runtimeCount,
  type PaneRuntime,
} from '@/workspace/runtimeRegistry'

function runtime(id: PaneRuntime['id']) {
  return {
    id,
    root: document.createElement('section'),
    router: {} as Router,
    navigate: vi.fn().mockResolvedValue(undefined),
    back: vi.fn(),
    forward: vi.fn(),
    clear: vi.fn(),
    destroy: vi.fn(),
  } satisfies PaneRuntime
}

describe('workspace runtime registry', () => {
  afterEach(() => destroyPaneRuntimes())

  it('registruje runtime mimo Pinia a odstraní jej přes cleanup', () => {
    const pane = runtime('secondary-2')
    const unregister = registerPaneRuntime(pane)

    expect(getPaneRuntime('secondary-2')).toBe(pane)
    expect(runtimeCount()).toBe(1)

    unregister()
    expect(getPaneRuntime('secondary-2')).toBeUndefined()
  })

  it('při nahrazení a hromadném zničení zavolá destroy právě jednou', () => {
    const first = runtime('secondary-2')
    const replacement = runtime('secondary-2')
    const third = runtime('secondary-3')

    registerPaneRuntime(first)
    registerPaneRuntime(replacement)
    registerPaneRuntime(third)

    expect(first.destroy).toHaveBeenCalledOnce()
    destroyPaneRuntimes()
    expect(replacement.destroy).toHaveBeenCalledOnce()
    expect(third.destroy).toHaveBeenCalledOnce()
    expect(runtimeCount()).toBe(0)
  })
})
