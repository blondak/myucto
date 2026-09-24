import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, expect, it, vi } from 'vitest'
import { useScrollLoadMore } from './useScrollLoadMore'

afterEach(() => vi.restoreAllMocks())

it('waits for a downward scroll even when the next-page button is initially visible', () => {
  let scrollY = 0
  vi.spyOn(window, 'scrollY', 'get').mockImplementation(() => scrollY)
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(() => ({ top: 600 } as DOMRect))
  const loadMore = vi.fn(async () => {})
  const Probe = defineComponent({
    setup() {
      const target = ref<HTMLElement | null>(null)
      useScrollLoadMore(target, () => true, loadMore)
      return () => h('div', { ref: target })
    },
  })
  const wrapper = mount(Probe)

  window.dispatchEvent(new Event('scroll'))
  expect(loadMore).not.toHaveBeenCalled()
  scrollY = 100
  window.dispatchEvent(new Event('scroll'))
  expect(loadMore).toHaveBeenCalledTimes(1)
  scrollY = 50
  window.dispatchEvent(new Event('scroll'))
  expect(loadMore).toHaveBeenCalledTimes(1)

  wrapper.unmount()
})

it('loads on scroll inside a workspace pane', () => {
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(() => ({ top: 600, bottom: 800 } as DOMRect))
  const loadMore = vi.fn(async () => {})
  const Probe = defineComponent({
    setup() {
      const target = ref<HTMLElement | null>(null)
      useScrollLoadMore(target, () => true, loadMore)
      return () => h('div', { class: 'workspace-pane-scroll overflow-auto' }, h('div', { ref: target }))
    },
  })
  const wrapper = mount(Probe)
  const pane = wrapper.element as HTMLElement

  pane.scrollTop = 100
  pane.dispatchEvent(new Event('scroll'))
  expect(loadMore).toHaveBeenCalledTimes(1)

  wrapper.unmount()
})
