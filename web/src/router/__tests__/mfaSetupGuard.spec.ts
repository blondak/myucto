import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'

// Router si při importu tahá i18n (import.meta.glob nad chunky překladů). Guard
// z něj nepotřebuje nic, takže ho zaslepíme — test má stát na autorizaci, ne na
// načítání překladů.
vi.mock('@/i18n', () => ({
  ensureNamespaces: vi.fn().mockResolvedValue(undefined),
  namespacesForRoute: () => [],
}))

import { authorizationGuard, canonicalInternalUrl, router } from '../index'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

/**
 * Regrese #5: účet s `must_setup_mfa = true` skončil v nekonečné smyčce
 * home → setup-mfa → home. Guard ho na `/setup-mfa` sice poslal, ale
 * deny-by-default kontrola tu routu neznala jako samoobslužnou a vrátila ho
 * domů — a tam ho MFA gate poslal zase zpátky. Prohlížeč jen zamrzl.
 *
 * Test proto guard NEspouští přes router.push (ta smyčka by se v testu jen
 * zacyklila stejně jako v prohlížeči), ale sám sleduje řetěz přesměrování
 * s tvrdým stropem — smyčka se tak projeví jako čitelný seznam skoků.
 */
function resolveTarget(location: RouteLocationRaw): RouteLocationNormalized {
  // `router.resolve()` samo `redirect` z route recordu nenásleduje — ten aplikuje
  // až navigace. Bez toho by /setup-totp skončil sám na sobě.
  let resolved = router.resolve(location)
  for (let hop = 0; hop < 4; hop++) {
    const redirect = resolved.matched[resolved.matched.length - 1]?.redirect
    if (!redirect) break
    const next = typeof redirect === 'function'
      ? (redirect as unknown as (to: typeof resolved) => RouteLocationRaw)(resolved)
      : redirect
    resolved = router.resolve(next as RouteLocationRaw)
  }
  return resolved as unknown as RouteLocationNormalized
}

async function followRedirects(start: RouteLocationRaw, maxHops = 8): Promise<{ path: string[]; final: string }> {
  let target = resolveTarget(start)
  const path: string[] = [String(target.name)]
  for (let hop = 0; hop < maxHops; hop++) {
    const result = await authorizationGuard(target)
    if (result === true || result === undefined) {
      return { path, final: String(target.name) }
    }
    target = resolveTarget(result as RouteLocationRaw)
    path.push(String(target.name))
  }
  return { path, final: 'SMYČKA' }
}

function signIn(overrides: Record<string, unknown> = {}) {
  const auth = useAuthStore()
  auth.setupStatus = { needs_setup: false } as never
  auth.user = {
    id: 1,
    email: 'mfa@example.invalid',
    name: 'Synthetic User',
    role: { type: 'admin' },
    is_superadmin: false,
    must_setup_mfa: false,
    must_setup_totp: false,
    ...overrides,
  } as never
  // Bez naplněného stavu by si guard sáhl na /api/auth/session/status.
  useSessionSecurityStore().state = { session_state: 'active' } as never
  return auth
}

describe('router guard vynuceného nastavení MFA', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('pustí účet s must_setup_mfa na setup-mfa a nevrátí ho domů', async () => {
    signIn({ must_setup_mfa: true })

    const { path, final } = await followRedirects({ name: 'home' })

    expect(final).toBe('setup-mfa')
    expect(path).toEqual(['home', 'setup-mfa'])
  })

  it('nechá na setup-mfa i účet, který na ni přijde přímo z URL', async () => {
    signIn({ must_setup_mfa: true })

    const { final } = await followRedirects({ name: 'setup-mfa' })

    expect(final).toBe('setup-mfa')
  })

  it('funguje i pro starší příznak must_setup_totp přes redirect /setup-totp', async () => {
    signIn({ must_setup_totp: true })

    const { final } = await followRedirects({ path: '/setup-totp' })

    expect(final).toBe('setup-mfa')
  })

  it('účet s hotovým MFA na setup-mfa nepustí — výjimka z deny-by-default ji neotevírá', async () => {
    signIn({ must_setup_mfa: false })

    const { final } = await followRedirects({ name: 'setup-mfa' })

    expect(final).not.toBe('setup-mfa')
    expect(final).toBe('home')
  })

  it('deny-by-default drží: routa bez permission a bez výjimky končí zpět na home', async () => {
    signIn()

    const result = await authorizationGuard(resolveTarget({ name: 'admin-users' }))

    expect(result).toEqual({ name: 'home' })
  })
})

describe('oddělení klientské a interní domény', () => {
  const customContext = {
    mode: 'custom',
    hostname: 'portal.example.test',
    origin: 'https://portal.example.test',
    locked: true,
    supplier_id: 7,
    purpose: 'portal',
    canonical_base_url: 'https://ucto.example.test/app-path',
  } as const

  it('nechá klientský portál na vlastní doméně', () => {
    const target = resolveTarget({ name: 'portal-document-requests', query: { page: '2' } })

    expect(canonicalInternalUrl(target, customContext)).toBeNull()
  })

  it('přesměruje interní obrazovku na canonical origin a zachová cestu', () => {
    const target = resolveTarget({ name: 'admin-settings', query: { tab: 'company' }, hash: '#domains' })

    expect(canonicalInternalUrl(target, customContext))
      .toBe('https://ucto.example.test/admin/settings?tab=company#domains')
  })
})
