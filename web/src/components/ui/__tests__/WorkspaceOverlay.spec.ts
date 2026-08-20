import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, type Component } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Drawer from '@/components/ui/Drawer.vue'
import { useWorkspaceStore } from '@/stores/workspace'
import { paneIdKey } from '@/workspace/paneActivity'

let wrappers: VueWrapper[] = []

beforeEach(() => {
  document.body.replaceChildren()
  document.body.style.overflow = ''
  setActivePinia(createPinia())
})

afterEach(() => {
  for (const wrapper of wrappers) wrapper.unmount()
  wrappers = []
  document.body.replaceChildren()
  document.body.style.overflow = ''
})

describe.each([
  ['modal', Modal],
  ['drawer', Drawer],
] as Array<[string, Component]>)('%s v pracovním panelu', (_name, component) => {
  it('skryje overlay a uvolní scroll, když jeho panel přestane být aktivní', async () => {
    const workspace = useWorkspaceStore()
    workspace.resetLayout(2, '/invoices')
    workspace.activatePane('primary')

    const wrapper = mount(component, {
      attachTo: document.body,
      props: { title: 'Test' },
      global: { provide: { [paneIdKey as symbol]: 'primary' } },
    })
    wrappers.push(wrapper)

    const overlay = document.body.querySelector<HTMLElement>('[data-workspace-pane="primary"]')
    expect(overlay).not.toBeNull()
    expect(overlay?.style.display).not.toBe('none')
    expect(document.body.style.overflow).toBe('hidden')

    workspace.activatePane('secondary-2')
    await nextTick()

    expect(overlay?.style.display).toBe('none')
    expect(overlay?.getAttribute('aria-hidden')).toBe('true')
    expect(document.body.style.overflow).toBe('')

    workspace.activatePane('primary')
    await nextTick()

    expect(overlay?.style.display).not.toBe('none')
    expect(overlay?.hasAttribute('aria-hidden')).toBe(false)
    expect(document.body.style.overflow).toBe('hidden')
  })
})

describe('výškové omezení modalu', () => {
  it('roluje pouze tělo a nechává volitelnou patičku viditelnou', () => {
    const wrapper = mount(Modal, {
      attachTo: document.body,
      props: { title: 'Test' },
      slots: { default: 'Obsah', footer: 'Akce' },
    })
    wrappers.push(wrapper)

    const panel = document.body.querySelector<HTMLElement>('[role="dialog"] > div')
    const body = panel?.querySelector<HTMLElement>(':scope > div')
    const footer = panel?.querySelector<HTMLElement>('footer')

    expect(panel?.className).toContain('max-h-[calc(100dvh-2rem)]')
    expect(panel?.className).toContain('overflow-hidden')
    expect(body?.className).toContain('overflow-y-auto')
    expect(body?.className).toContain('min-h-0')
    expect(footer?.className).toContain('shrink-0')
    expect(footer?.textContent).toContain('Akce')
  })
})
