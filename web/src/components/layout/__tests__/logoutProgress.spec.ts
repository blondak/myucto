import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { compile } from '@vue/compiler-dom'
import * as Vue from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

// Izolujeme skutečnou obsluhu a obě tlačítka z layoutu, aby test nepotřeboval
// inicializovat všechny účetní agendy a jejich síťové požadavky.
const source = readFileSync(resolve(process.cwd(), 'src/components/layout/AppLayout.vue'), 'utf8')
const buttons = [...source.matchAll(/<button\b[^>]*>[\s\S]*?<\/button>/g)]
  .map(match => match[0]).filter(button => button.includes('@click="logout"'))
const handler = source.match(/async function logout\(\) \{([\s\S]*?)\n\}/)![1]

describe.each(buttons.map((template, index) => ({ template, menu: index === 0 ? 'desktop' : 'mobil' })))('odhlašování — $menu', ({ template }) => {
  function setup() {
    let resolveLogout!: () => void
    let rejectLogout!: (error: Error) => void
    const pending = new Promise<void>((resolve, reject) => { resolveLogout = resolve; rejectLogout = reject })
    const auth = { logout: vi.fn(() => pending) }
    const security = { clear: vi.fn(), markLocked: vi.fn(), error: '' }
    const router = { replace: vi.fn().mockResolvedValue(undefined) }
    const toast = { error: vi.fn() }
    const busy = ref(false)
    const mobileOpen = ref(true)
    const t = (key: string) => key
    const logout = new Function('logoutBusy', 'auth', 'sessionSecurity', 'mobileOpen', 'router', 'toast', 't',
      `return async function logout() {${handler}}`)(busy, auth, security, mobileOpen, router, toast, t)
    const render = new Function('Vue', compile(template, { mode: 'function' }).code)(Vue)
    const wrapper = mount(defineComponent({ setup: () => ({ logoutBusy: busy, logout, t }), render }))
    return { wrapper, auth, security, router, toast, resolveLogout, rejectLogout, logout }
  }

  it('při pomalé odpovědi zobrazí průběh a nepřipustí druhý požadavek', async () => {
    const ctx = setup()
    const button = ctx.wrapper.get('button')
    expect(button.text()).toBe('nav.logout')
    expect(button.find('.animate-spin').exists()).toBe(false)
    await button.trigger('click')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-busy')).toBe('true')
    expect(button.text()).toBe('nav.logging_out')
    expect(button.find('.animate-spin').exists()).toBe(true)
    await ctx.logout()
    expect(ctx.auth.logout).toHaveBeenCalledTimes(1)
    expect(ctx.router.replace).not.toHaveBeenCalled()
    ctx.resolveLogout()
    await flushPromises()
    expect(ctx.security.clear).toHaveBeenCalledOnce()
    expect(ctx.router.replace).toHaveBeenCalledWith('/login')
    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.find('.animate-spin').exists()).toBe(false)
    ctx.wrapper.unmount()
  })

  it('při chybě odstraní indikátor, povolí opakování a zamkne aplikaci', async () => {
    const ctx = setup()
    await ctx.wrapper.get('button').trigger('click')
    ctx.rejectLogout(new Error('offline'))
    await flushPromises()
    expect(ctx.security.markLocked).toHaveBeenCalledOnce()
    expect(ctx.security.error).toBe('logout_failed')
    expect(ctx.toast.error).toHaveBeenCalledWith('auth.logout_failed')
    expect(ctx.router.replace).not.toHaveBeenCalled()
    expect(ctx.wrapper.get('button').attributes('disabled')).toBeUndefined()
    expect(ctx.wrapper.get('button').text()).toBe('nav.logout')
    expect(ctx.wrapper.find('.animate-spin').exists()).toBe(false)
    ctx.wrapper.unmount()
  })
})

it('testuje desktopové i mobilní tlačítko', () => { expect(buttons).toHaveLength(2) })
