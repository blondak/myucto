import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import { useFillViewportHeight } from '../useFillViewportHeight'

afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks() })

it('fills the window under the page header and slides the page when rows scroll', async () => {
  vi.useFakeTimers()
  const el = document.createElement('div')
  vi.spyOn(el, 'getBoundingClientRect').mockReturnValue({ top: 400 } as DOMRect)
  const scroll = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
  const wrapper = mount({ setup() {
    useFillViewportHeight(ref(el))
    return () => null
  } })
  await vi.runOnlyPendingTimersAsync()
  expect(el.style.maxHeight).toBe(`${window.innerHeight - 2 * 16}px`)
  el.scrollTop = 40
  el.dispatchEvent(new Event('scroll'))
  expect(scroll).toHaveBeenCalledWith({ top: 400 - 16, behavior: 'smooth' })
  wrapper.unmount()
})
