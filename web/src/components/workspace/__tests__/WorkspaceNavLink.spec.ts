import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import WorkspaceNavLink from '@/components/workspace/WorkspaceNavLink.vue'
import { useWorkspaceStore } from '@/stores/workspace'
import { destroyPaneRuntimes, registerPaneRuntime } from '@/workspace/runtimeRegistry'

async function mountLink() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/invoices', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  const workspace = useWorkspaceStore()
  workspace.resetLayout(2, '/')
  workspace.activatePane('secondary-2')
  const navigate = vi.fn().mockResolvedValue(undefined)
  registerPaneRuntime({
    id: 'secondary-2',
    root: document.createElement('section'),
    router: router as Router,
    navigate,
    back: vi.fn(),
    forward: vi.fn(),
    clear: vi.fn(),
    destroy: vi.fn(),
  })
  const wrapper = mount(WorkspaceNavLink, {
    props: { to: '/invoices' },
    slots: { default: 'Faktury' },
    global: { plugins: [pinia, router] },
  })
  return { wrapper, navigate }
}

describe('WorkspaceNavLink', () => {
  afterEach(() => destroyPaneRuntimes())

  it('běžný levý klik naviguje aktivní vedlejší panel', async () => {
    const { wrapper, navigate } = await mountLink()
    await wrapper.trigger('click', { button: 0 })

    expect(navigate).toHaveBeenCalledWith('/invoices')
    expect(wrapper.attributes('href')).toBe('/invoices')
  })

  it('Ctrl+klik ponechá na obalujícím panelu, který může odkaz otevřít napravo', async () => {
    const { wrapper, navigate } = await mountLink()
    wrapper.element.addEventListener('click', (event: Event) => event.preventDefault())
    await wrapper.trigger('click', { button: 0, ctrlKey: true })

    expect(navigate).not.toHaveBeenCalled()
  })
})
