import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { clearUnconfirmedLogout, hasUnconfirmedLogout, installLogoutRecoveryListener, logoutRecoveryPath, reloadAfterLogoutFailure } from '../logoutRecovery'
import { navigateFullPage } from '@/workspace/fullPageNavigation'

vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: vi.fn() }))
beforeEach(() => { localStorage.clear(); clearUnconfirmedLogout(); vi.clearAllMocks() })
afterEach(() => { vi.restoreAllMocks(); clearUnconfirmedLogout() })

it('po chybě plně načte login a uloží jen sdílený příznak bez tokenů', () => {
  sessionStorage.setItem('myinvoice.domain_login.target', 'synthetic-pending-flow')
  reloadAfterLogoutFailure()
  expect(sessionStorage.getItem('myinvoice.domain_login.target')).toBeNull()
  expect(navigateFullPage).toHaveBeenCalledWith('/login', 'replace')
  expect(hasUnconfirmedLogout()).toBe(true)
  expect(localStorage.getItem('myinvoice.logout_unconfirmed')).toBe('1')
})

it('nová instance stránky přečte stav z místního úložiště', () => {
  localStorage.setItem('myinvoice.logout_unconfirmed', '1')
  expect(hasUnconfirmedLogout()).toBe(true)
  clearUnconfirmedLogout()
  expect(hasUnconfirmedLogout()).toBe(false)
})

it('bez úložiště nevyhodí chybu a nese příznak v adrese', () => {
  vi.spyOn(localStorage, 'setItem').mockImplementation(() => { throw new Error('disabled') })
  vi.spyOn(localStorage, 'getItem').mockImplementation(() => { throw new Error('disabled') })
  vi.spyOn(sessionStorage, 'removeItem').mockImplementation(() => { throw new Error('disabled') })
  reloadAfterLogoutFailure()
  expect(navigateFullPage).toHaveBeenCalledWith('/login?logout=failed', 'replace')
  expect(hasUnconfirmedLogout()).toBe(true)
  expect(logoutRecoveryPath()).toBe('/login?logout=failed')
})

it('už otevřené okno při sdíleném příznaku nejprve zakryje data a pak načte login', () => {
  const cover = vi.fn()
  window.addEventListener('myinvoice:session-locked', cover)
  const stop = installLogoutRecoveryListener()
  window.dispatchEvent(new StorageEvent('storage', { key: 'myinvoice.logout_unconfirmed', newValue: '1' }))
  expect(cover).toHaveBeenCalledOnce()
  expect(navigateFullPage).toHaveBeenCalledWith('/login', 'replace')
  stop()
  window.removeEventListener('myinvoice:session-locked', cover)
})

it('při zakázaném úložišti po úplném načtení přečte neúspěch z URL', async () => {
  history.replaceState({}, '', '/login?logout=failed')
  vi.resetModules()
  const reloaded = await import('../logoutRecovery')
  expect(reloaded.hasUnconfirmedLogout()).toBe(true)
  reloaded.clearUnconfirmedLogout()
  expect(reloaded.hasUnconfirmedLogout()).toBe(false)
  history.replaceState({}, '', '/')
})
