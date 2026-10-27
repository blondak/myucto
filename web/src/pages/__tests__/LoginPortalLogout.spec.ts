import { markUnconfirmedLogout, clearUnconfirmedLogout, hasUnconfirmedLogout } from '@/security/logoutRecovery'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { markPortalLoggedOut, isPortalLoggedOut } from '@/security/portalLogout'
const m = vi.hoisted(() => ({
  navigate: vi.fn(), sessionStatus: vi.fn(),
  replace: vi.fn(), begin: vi.fn(), query: {} as Record<string, unknown>,
  auth: { logout: vi.fn(), setSessionCsrfToken: vi.fn(), fetchSetupStatus: vi.fn(), refresh: vi.fn(), needsSetup: false, setupStatus: null,
    isAuthenticated: false, mustSetupMfa: false, mustSetupTotp: false, shouldOfferMfa: false,
    domainContext: { mode: 'custom', locked: true } },
}))
vi.mock('@/components/layout/AppShell.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m.auth }))
vi.mock('@/api/auth', () => ({ authApi: { sessionStatus: m.sessionStatus } }))
vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: m.navigate }))
vi.mock('@/router', () => ({ clearLoginBounces: vi.fn(), loginRedirectLoopDetected: () => false }))
vi.mock('@/composables/useTurnstile', () => ({ useTurnstile: () => ({}) }))
vi.mock('@/security/webauthn', () => ({ isWebAuthnAvailable: () => false }))
vi.mock('@/security/domainLogin', () => ({
  beginDomainLogin: m.begin, hasPendingCanonicalDomainLogin: () => false,
  canonicalDomainLoginSessionCsrf: () => null,
}))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: m.query }), useRouter: () => ({ replace: m.replace }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Login from '../Login.vue'
beforeEach(() => {
  clearUnconfirmedLogout(); vi.clearAllMocks(); localStorage.clear(); m.query = {}; m.auth.isAuthenticated = false
  m.begin.mockResolvedValue(undefined)
  m.sessionStatus.mockReset().mockResolvedValue({ csrf_token: 'retry-csrf' })
  m.auth.logout.mockReset().mockResolvedValue(undefined)
})
describe('vlastní doména po odhlášení', () => {
  it('při opakovaném mountu zůstane odhlášená bez formuláře a SSO', async () => {
    markPortalLoggedOut()
    for (let reload = 0; reload < 2; reload++) {
      const wrapper = mount(Login); await flushPromises()
      expect(wrapper.find('[role="status"]').text()).toBe('domain_login.logged_out')
      expect(wrapper.find('form').exists()).toBe(false)
      expect(wrapper.find('input').exists()).toBe(false)
      expect(m.begin).not.toHaveBeenCalled(); wrapper.unmount()
    }
    expect(m.auth.refresh).toHaveBeenCalledTimes(2)
  })
  it('kliknutím zahájí přihlášení s bezpečnou cílovou cestou', async () => {
    markPortalLoggedOut(); m.query = { return_to: '/invoices' }
    const wrapper = mount(Login); await flushPromises(); await wrapper.get('button').trigger('click')
    expect(m.begin).toHaveBeenCalledWith('/invoices', undefined)
    expect(isPortalLoggedOut()).toBe(true); wrapper.unmount()
  })
  it('po chybě umožní opakování a nebezpečný návrat nahradí portálem', async () => {
    markPortalLoggedOut(); m.query = { return_to: '//evil.example.test' }
    m.begin.mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(Login); await flushPromises()
    await wrapper.get('button').trigger('click'); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('domain_login.start_failed')
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    await wrapper.get('button').trigger('click'); await flushPromises()
    expect(m.begin).toHaveBeenNthCalledWith(2, '/portal', undefined)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false); wrapper.unmount()
  })
  it('handoff čeká po odhlášení na kliknutí', async () => {
    markPortalLoggedOut(); m.query = { domain_handoff: '/profile/password?tab=totp' }
    const wrapper = mount(Login); await flushPromises()
    expect(m.begin).not.toHaveBeenCalled()
    await wrapper.get('button').trigger('click')
    expect(m.begin).toHaveBeenCalledWith('/portal', '/profile/password?tab=totp'); wrapper.unmount()
  })
  it('platná nezávislá relace má přednost před starým markerem', async () => {
    markPortalLoggedOut(); m.auth.isAuthenticated = true
    const wrapper = mount(Login); await flushPromises()
    expect(m.replace).toHaveBeenCalledWith('/portal'); expect(m.begin).not.toHaveBeenCalled(); wrapper.unmount()
  })
  it('první návštěva automaticky začne SSO', async () => {
    const wrapper = mount(Login); await flushPromises()
    expect(m.begin.mock.calls[0]?.[0]).toBe('/portal'); wrapper.unmount()
  })
})

it('po neúspěšném odhlášení nepoužije platnou relaci ani automatické SSO, ani po reloadu', async () => {
  markUnconfirmedLogout()
  m.auth.isAuthenticated = true
  m.query = { domain_handoff: '/profile/password' }
  for (let reload = 0; reload < 2; reload++) {
    const wrapper = mount(Login)
    await flushPromises()
    expect(wrapper.get('[data-testid="logout-unconfirmed"]').text()).toContain('auth.logout_unconfirmed')
    expect(m.auth.refresh).not.toHaveBeenCalled()
    expect(m.begin).not.toHaveBeenCalled()
    expect(m.replace).not.toHaveBeenCalled()
    wrapper.unmount()
  }
  clearUnconfirmedLogout()
})

it('po neúspěšném odhlášení zahájí SSO až výslovným kliknutím', async () => {
  markUnconfirmedLogout()
  const wrapper = mount(Login)
  await flushPromises()
  const login = wrapper.findAll('button').find(button => button.text() === 'auth.login')!
  await login.trigger('click')
  expect(m.begin).toHaveBeenCalledWith('/portal', undefined)
  wrapper.unmount()
  clearUnconfirmedLogout()
})

it('opakování odhlášení načte jen CSRF, během čekání blokuje UI a po úspěchu načte login', async () => {
  markUnconfirmedLogout()
  let finish!: (value: { csrf_token: string }) => void
  m.sessionStatus.mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
  const wrapper = mount(Login)
  await flushPromises()
  await wrapper.get('[data-testid="logout-unconfirmed"] button').trigger('click')
  expect(document.querySelector('dialog[open]')).not.toBeNull()
  expect(m.auth.logout).not.toHaveBeenCalled()
  finish({ csrf_token: 'retry-csrf' })
  await flushPromises()
  expect(m.auth.setSessionCsrfToken).toHaveBeenCalledWith('retry-csrf')
  expect(m.auth.logout).toHaveBeenCalledOnce()
  expect(isPortalLoggedOut()).toBe(true)
  expect(m.auth.refresh).not.toHaveBeenCalled()
  expect(m.navigate).toHaveBeenCalledWith('/login', 'replace')
  wrapper.unmount()
  clearUnconfirmedLogout()
})

it.each([true, false])('při opakování a chybě session statusu potvrdí odhlášení jen při 401 (%s)', async unauthenticated => {
  markUnconfirmedLogout()
  m.sessionStatus.mockRejectedValueOnce(unauthenticated
    ? { response: { status: 401, data: { error: { code: 'unauthenticated' } } } }
    : new Error('offline'))
  const wrapper = mount(Login)
  await flushPromises()
  await wrapper.get('[data-testid="logout-unconfirmed"] button').trigger('click')
  await flushPromises()
  expect(m.auth.logout).not.toHaveBeenCalled()
  expect(hasUnconfirmedLogout()).toBe(!unauthenticated)
  expect(isPortalLoggedOut()).toBe(unauthenticated)
  expect(m.navigate).toHaveBeenCalledTimes(unauthenticated ? 1 : 0)
  expect(wrapper.get('[data-testid="logout-unconfirmed"] button').attributes('disabled')).toBeUndefined()
  wrapper.unmount()
  clearUnconfirmedLogout()
})

it('upozornění na neúspěch zůstane viditelné i při nedostupném backendu', async () => {
  markUnconfirmedLogout()
  m.auth.fetchSetupStatus.mockRejectedValueOnce(new Error('offline'))
  const wrapper = mount(Login)
  expect(wrapper.get('[data-testid="logout-unconfirmed"]').text()).toContain('auth.logout_unconfirmed')
  await flushPromises()
  expect(m.auth.refresh).not.toHaveBeenCalled()
  expect(m.begin).not.toHaveBeenCalled()
  wrapper.unmount()
  clearUnconfirmedLogout()
})
