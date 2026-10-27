import { beforeEach, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../auth'
import { clearUnconfirmedLogout, hasUnconfirmedLogout, markUnconfirmedLogout } from '@/security/logoutRecovery'
import { navigateFullPage } from '@/workspace/fullPageNavigation'

const m = vi.hoisted(() => ({ logout: vi.fn(), me: vi.fn(), login: vi.fn(), domainContext: vi.fn() }))
vi.mock('@/api/auth', () => ({ authApi: m }))
vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: vi.fn() }))
vi.mock('@/security/sessionChannel', () => ({ broadcastSessionEvent: vi.fn() }))
beforeEach(() => {
  localStorage.clear(); sessionStorage.clear(); clearUnconfirmedLogout(); vi.resetAllMocks(); setActivePinia(createPinia())
  m.domainContext.mockResolvedValue({ mode: 'canonical', locked: false })
})

it.each([new Error('offline'), Object.assign(new Error('timeout'), { code: 'ECONNABORTED' })])('po chybě %s vyčistí profil a načte login s přetrvávající blokací', async error => {
  const auth = useAuthStore()
  auth.user = { id: 1 } as never
  auth.setSessionCsrfToken('synthetic-csrf')
  m.logout.mockRejectedValueOnce(error)
  await expect(auth.logout()).rejects.toBe(error)
  expect(m.logout).toHaveBeenCalledWith('synthetic-csrf')
  expect(auth.user).toBeNull()
  expect(auth.csrfToken).toBe('')
  expect(hasUnconfirmedLogout()).toBe(true)
  expect(navigateFullPage).toHaveBeenCalledWith('/login', 'replace')
})

it('příznak z jiného okna zakáže hydrataci profilu i s platnou cookie', async () => {
  const auth = useAuthStore()
  auth.user = { id: 1 } as never
  markUnconfirmedLogout()
  expect(await auth.refresh()).toBe(false)
  expect(auth.user).toBeNull()
  expect(m.me).not.toHaveBeenCalled()
  expect(m.domainContext).not.toHaveBeenCalled()
})

it('blokaci odstraní až úspěšné výslovné přihlášení', async () => {
  const auth = useAuthStore()
  markUnconfirmedLogout()
  m.login.mockRejectedValueOnce(new Error('invalid credentials'))
  await expect(auth.login('user@example.test', 'synthetic-password')).rejects.toThrow()
  expect(hasUnconfirmedLogout()).toBe(true)
  m.login.mockResolvedValueOnce({ user: { id: 1 }, csrf_token: 'fresh-csrf' })
  m.me.mockResolvedValueOnce({ user: { id: 1 }, suppliers: [], csrf_token: 'fresh-csrf' })
  await auth.login('user@example.test', 'synthetic-password')
  expect(hasUnconfirmedLogout()).toBe(false)
  expect(auth.user?.id).toBe(1)
})

it('úspěšné opakované odhlášení odstraní sdílenou blokaci', async () => {
  markUnconfirmedLogout()
  m.logout.mockResolvedValueOnce(undefined)
  await useAuthStore().logout()
  expect(hasUnconfirmedLogout()).toBe(false)
  expect(navigateFullPage).not.toHaveBeenCalled()
})

it('souběžná obnova profilu nesebere odhlašovacímu požadavku původní CSRF', async () => {
  const auth = useAuthStore()
  auth.setSessionCsrfToken('original-csrf')
  let finish!: () => void
  m.logout.mockReturnValueOnce(new Promise<void>(resolve => { finish = resolve }))
  const pending = auth.logout()
  expect(await auth.refresh()).toBe(false)
  expect(auth.csrfToken).toBe('')
  expect(m.logout).toHaveBeenCalledWith('original-csrf')
  expect(m.me).not.toHaveBeenCalled()
  finish()
  await pending
  expect(hasUnconfirmedLogout()).toBe(false)
})
