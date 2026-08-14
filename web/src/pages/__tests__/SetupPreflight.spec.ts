import { ref } from 'vue'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PreflightReport } from '@/api/diagnostics'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: ref('cs'),
    t: (key: string) => key,
    te: () => false,
  }),
}))

const replace = vi.fn()
vi.mock('vue-router', () => ({ useRouter: () => ({ replace }) }))

const fetchSetupStatus = vi.fn()
const authStore = { fetchSetupStatus, needsSetup: true, refresh: vi.fn(), isAuthenticated: true, setSessionCsrfToken: vi.fn() }
vi.mock('@/stores/auth', () => ({ useAuthStore: () => authStore }))

vi.mock('@/api/auth', () => ({ authApi: { setup: vi.fn(), setupSample: vi.fn(), setupAresLookup: vi.fn(), setupCrpdphLookup: vi.fn() } }))

const preflight = vi.fn()
vi.mock('@/api/diagnostics', () => ({ diagnosticsApi: { preflight: () => preflight() } }))

// AppShell tahá logo z /styles — pro test wizardu je to jen rám okolo obsahu.
vi.mock('@/components/layout/AppShell.vue', () => ({ default: { template: '<div><slot /></div>' } }))

import Setup from '@/pages/Setup.vue'

function report(over: Partial<PreflightReport['summary']>, checks: PreflightReport['checks'] = []): PreflightReport {
  return {
    generated_at: '2026-08-14T10:00:00+02:00',
    environment: 'native',
    summary: { status: 'ok', ok: 16, warn: 0, fail: 0, skip: 0, ...over },
    checks,
  }
}

async function mountSetup() {
  const wrapper = mount(Setup)
  await flushPromises()
  return wrapper
}

describe('Setup — kontrola prostředí před prvním setupem', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authStore.needsSetup = true
  })

  it('vyhovující prostředí wizard nezdržuje', async () => {
    preflight.mockResolvedValue(report({}))
    const wrapper = await mountSetup()

    expect(wrapper.text()).not.toContain('setup.preflight.title')
    expect(wrapper.text()).toContain('setup.create_admin')
  })

  it('při problému ukáže kontrolu jako první obrazovku', async () => {
    preflight.mockResolvedValue(
      report({ status: 'fail', fail: 1, warn: 1, ok: 14 }, [
        { id: 'php_version', status: 'fail', actual: '8.3.0', expected: '>= 8.5.0', manual: '04_Instalace_Nativni' },
      ]),
    )
    const wrapper = await mountSetup()

    expect(wrapper.text()).toContain('setup.preflight.title')
    expect(wrapper.text()).toContain('setup.preflight.blocking_hint')
    expect(wrapper.text()).not.toContain('setup.create_admin')
  })

  it('u varování jde pokračovat a připomínka zůstane viditelná', async () => {
    preflight.mockResolvedValue(report({ status: 'warn', warn: 2, ok: 14 }))
    const wrapper = await mountSetup()

    expect(wrapper.text()).toContain('setup.preflight.subtitle_warn')
    await wrapper.findAll('button').find((b) => b.text() === 'setup.preflight.continue')!.trigger('click')

    expect(wrapper.text()).toContain('setup.create_admin')
    expect(wrapper.text()).toContain('setup.preflight.reminder')
  })

  it('nedostupná kontrola instalaci neblokuje', async () => {
    preflight.mockRejectedValue(new Error('offline'))
    const wrapper = await mountSetup()

    expect(wrapper.text()).toContain('setup.create_admin')
    expect(wrapper.text()).toContain('setup.preflight.unavailable')
  })

  it('hotová instalace jde rovnou na login a prostředí neměří', async () => {
    authStore.needsSetup = false
    const wrapper = await mountSetup()

    expect(replace).toHaveBeenCalledWith('/login')
    expect(preflight).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
