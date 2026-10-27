import { clearTargetDomainLogin } from '@/security/domainLogin'
import { navigateFullPage } from '@/workspace/fullPageNavigation'

const KEY = 'myinvoice.logout_unconfirmed'
let pendingWithoutStorage = false
let ignoreQuery = false

export function hasUnconfirmedLogout(): boolean {
  if (!ignoreQuery && new URLSearchParams(window.location.search).get('logout') === 'failed') return true
  try { return localStorage.getItem(KEY) === '1' } catch { return pendingWithoutStorage }
}

export function markUnconfirmedLogout(): void {
  pendingWithoutStorage = true
  try { localStorage.setItem(KEY, '1') } catch { /* Při zakázaném úložišti přežije stav alespoň v URL. */ }
}

export function clearUnconfirmedLogout(): void {
  pendingWithoutStorage = false
  ignoreQuery = true
  try { localStorage.removeItem(KEY) } catch { /* Paměťový stav už je vyčištěný. */ }
}

export function logoutRecoveryPath(): string {
  try {
    if (localStorage.getItem(KEY) === '1') return '/login'
  } catch { /* Parametr níže zachová stav i při zakázaném úložišti. */ }
  return '/login?logout=failed'
}

export function reloadAfterLogoutFailure(): void {
  markUnconfirmedLogout()
  try { clearTargetDomainLogin() } catch { /* Zakázané úložiště nesmí zabránit bezpečné navigaci. */ }
  navigateFullPage(logoutRecoveryPath(), 'replace')
}

export function installLogoutRecoveryListener(): () => void {
  const receive = (event: StorageEvent) => {
    if (event.key !== KEY) return
    if (event.newValue === '1') {
      try { clearTargetDomainLogin() } catch { /* Při zakázaném úložišti není uložený callback dostupný. */ }
      // Ostatní okna ihned zakryjí soukromá data, ještě než se dokončí navigace.
      window.dispatchEvent(new CustomEvent('myinvoice:session-locked'))
      navigateFullPage('/login', 'replace')
    } else if (event.newValue === null) {
      pendingWithoutStorage = false
      ignoreQuery = true
    }
  }
  window.addEventListener('storage', receive)
  return () => window.removeEventListener('storage', receive)
}
