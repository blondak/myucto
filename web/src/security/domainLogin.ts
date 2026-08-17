import { authApi } from '@/api/auth'

const TARGET_KEY = 'myinvoice.domain_login.target'
const CANONICAL_KEY = 'myinvoice.domain_login.canonical'

interface TargetState {
  requestToken: string
  state: string
  verifier: string
  expiresAt: number
}

interface CanonicalState {
  requestToken: string
  state: string
}

function base64Url(bytes: Uint8Array): string {
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function randomToken(): string {
  return base64Url(crypto.getRandomValues(new Uint8Array(32)))
}

async function challenge(verifier: string): Promise<string> {
  return base64Url(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))))
}

export async function beginDomainLogin(returnPath = '/portal'): Promise<never> {
  const verifier = randomToken()
  const result = await authApi.domainLoginStart(await challenge(verifier), returnPath)
  const target: TargetState = {
    requestToken: result.request_token,
    state: result.state,
    verifier,
    expiresAt: Date.now() + result.expires_in * 1000,
  }
  sessionStorage.setItem(TARGET_KEY, JSON.stringify(target))
  window.location.replace(result.login_url)
  return new Promise<never>(() => undefined)
}

export function captureCanonicalDomainLogin(requestToken: unknown, state: unknown): boolean {
  if (typeof requestToken !== 'string' || typeof state !== 'string'
      || !/^[A-Za-z0-9_-]{43}$/.test(requestToken)
      || !/^[A-Za-z0-9_-]{43}$/.test(state)) {
    if (requestToken !== undefined || state !== undefined) sessionStorage.removeItem(CANONICAL_KEY)
    return false
  }
  sessionStorage.setItem(CANONICAL_KEY, JSON.stringify({ requestToken, state } satisfies CanonicalState))
  return true
}

export function hasPendingCanonicalDomainLogin(): boolean {
  return readCanonical() !== null
}

export async function authorizePendingDomainLogin(): Promise<boolean> {
  const pending = readCanonical()
  if (!pending) return false
  try {
    const result = await authApi.domainLoginAuthorize(pending.requestToken, pending.state)
    window.location.replace(result.redirect_url)
  } finally {
    // Neplatný nebo už spotřebovaný request nesmí po reloadu vytvořit login smyčku.
    // Při síťové chybě uživatel zahájí čerstvý tok z cílové domény.
    sessionStorage.removeItem(CANONICAL_KEY)
  }
  return true
}

export function readTargetDomainLogin(
  requestToken: unknown,
  code: unknown,
  state: unknown,
): (TargetState & { code: string }) | null {
  if (typeof requestToken !== 'string' || typeof code !== 'string' || typeof state !== 'string') return null
  try {
    const saved = JSON.parse(sessionStorage.getItem(TARGET_KEY) || 'null') as TargetState | null
    if (!saved || saved.expiresAt < Date.now()
        || saved.requestToken !== requestToken || saved.state !== state
        || !/^[A-Za-z0-9_-]{43}$/.test(code)) {
      sessionStorage.removeItem(TARGET_KEY)
      return null
    }
    return { ...saved, code }
  } catch {
    sessionStorage.removeItem(TARGET_KEY)
    return null
  }
}

export function clearTargetDomainLogin(): void {
  sessionStorage.removeItem(TARGET_KEY)
}

function readCanonical(): CanonicalState | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(CANONICAL_KEY) || 'null') as CanonicalState | null
    return value && /^[A-Za-z0-9_-]{43}$/.test(value.requestToken)
      && /^[A-Za-z0-9_-]{43}$/.test(value.state) ? value : null
  } catch {
    return null
  }
}
