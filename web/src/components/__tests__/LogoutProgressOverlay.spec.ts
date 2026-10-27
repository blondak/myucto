import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import LogoutProgressOverlay from '../LogoutProgressOverlay.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
afterEach(() => vi.restoreAllMocks())

describe('celoplošné odhlašování', () => {
  it('otevře nativní modální dialog blokující zbytek dokumentu', () => {
    const showModal = vi.spyOn(HTMLDialogElement.prototype, 'showModal')
    const wrapper = mount(LogoutProgressOverlay, { attachTo: document.body })
    const dialog = wrapper.get('dialog')
    expect(showModal).toHaveBeenCalledOnce()
    expect(dialog.attributes('open')).toBeDefined()
    expect(dialog.classes()).toEqual(expect.arrayContaining(['fixed', 'inset-0', 'h-dvh', 'w-screen']))
    expect(dialog.attributes('aria-busy')).toBe('true')
    expect(wrapper.get('[role="status"]').text()).toBe('nav.logging_out')
    expect(wrapper.find('.animate-spin').exists()).toBe(true)
    wrapper.unmount()
    expect(document.querySelector('dialog')).toBeNull()
  })

  it('nelze zavřít klávesou Escape a nenabízí žádnou další akci', () => {
    const wrapper = mount(LogoutProgressOverlay)
    const event = new Event('cancel', { cancelable: true })
    wrapper.get('dialog').element.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(true)
    expect(wrapper.get('dialog').attributes('open')).toBeDefined()
    expect(wrapper.findAll('button, a, input')).toHaveLength(0)
    wrapper.unmount()
  })
})
