import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import { useFillViewportHeight } from '../useFillViewportHeight'

afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks() })

it('keeps filters above the bank table visible while scrolling rows', async () => {
  vi.useFakeTimers()
  const el = document.createElement('div')
  vi.spyOn(el, 'getBoundingClientRect').mockReturnValue({ top: 250 } as DOMRect)
  const scroll = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
  const wrapper = mount({ setup() {
    useFillViewportHeight(ref(el), { keepFiltersVisible: true })
    return () => null
  } })
  await vi.runOnlyPendingTimersAsync()
  expect(el.style.maxHeight).toBe(`${Math.max(240, window.innerHeight - 250 - 16)}px`)
  el.scrollTop = 40
  el.dispatchEvent(new Event('scroll'))
  expect(scroll).not.toHaveBeenCalled()
  wrapper.unmount()
})
