import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

/**
 * Záložka Profil → Heslo → 2FA: po aktivaci TOTP vrací API první sadu záložních
 * kódů (`recovery_codes`). Zobrazují se jen jednou — stránka je musí ukázat
 * a nechat uživatele potvrdit uložení, stejně jako ForcedMfaSetup. Dřív je
 * tahle záložka tiše zahodila (toast „aktivováno" a nic víc).
 */
const m = vi.hoisted(() => ({
  totpStatus: vi.fn(),
  totpSetup: vi.fn(),
  totpEnable: vi.fn(),
  store: { user: { totp_enabled: false, mfa_methods: [], passkey_count: 0 } as any },
}))

vi.mock('@/api/auth', () => ({
  authApi: {
    totpStatus: m.totpStatus,
    totpSetup: m.totpSetup,
    totpEnable: m.totpEnable,
    passkeyStepUpOptions: vi.fn(),
    passkeyStepUpVerify: vi.fn(),
    sessionLockPreference: vi.fn(),
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ...m.store }),
}))
vi.mock('@/stores/sessionSecurity', () => ({
  useSessionSecurityStore: () => ({ refresh: vi.fn(), clear: vi.fn() }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/security/webauthn', () => ({
  getCredential: vi.fn(),
  webAuthnErrorKey: () => null,
}))
vi.mock('@/security/domainLogin', () => ({
  hasPendingCanonicalDomainLogin: () => false,
  authorizePendingDomainLogin: vi.fn(),
}))
vi.mock('@/pages/Passkeys.vue', () => ({ default: { name: 'Passkeys', template: '<div />' } }))
vi.mock('@/pages/KeyboardShortcuts.vue', () => ({ default: { name: 'KeyboardShortcuts', template: '<div />' } }))
vi.mock('@/components/security/RecoveryCodesOnce.vue', () => ({
  default: {
    name: 'RecoveryCodesOnce',
    props: ['codes', 'busy'],
    emits: ['confirm'],
    template: '<div data-test="recovery-codes">{{ codes.join(",") }}<button data-test="recovery-confirm" @click="$emit(\'confirm\')" /></div>',
  },
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: { tab: 'totp' } }),
  useRouter: () => ({ replace: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import PasswordChange from '../PasswordChange.vue'

const mountPage = () => mount(PasswordChange, { global: { stubs: { RouterLink: true } } })

async function activate(wrapper: ReturnType<typeof mountPage>) {
  await wrapper.get('[data-test="totp-current-password"]').setValue('Synthetic-test-password-42')
  await wrapper.get('[data-test="totp-start"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-test="totp-code"]').setValue('123456')
  await wrapper.get('[data-test="totp-activate"]').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  vi.clearAllMocks()
  m.store.user = { totp_enabled: false, mfa_methods: [], passkey_count: 0 }
  m.totpStatus.mockResolvedValue({ enabled: false })
  m.totpSetup.mockResolvedValue({ secret: 'S', uri: 'otpauth://x', qr_data_uri: 'data:,' })
})

describe('PasswordChange — záložní kódy po aktivaci TOTP', () => {
  it('ukáže první sadu záložních kódů z odpovědi a stav přepne až po potvrzení', async () => {
    m.totpEnable.mockResolvedValue({ enabled: true, recovery_codes: ['AAAA-BBBB', 'CCCC-DDDD'] })
    const wrapper = mountPage()
    await flushPromises()
    m.totpStatus.mockResolvedValue({ enabled: true })

    await activate(wrapper)

    expect(wrapper.get('[data-test="recovery-codes"]').text()).toContain('AAAA-BBBB,CCCC-DDDD')
    expect(wrapper.text()).not.toContain('auth.totp_status_enabled')

    await wrapper.get('[data-test="recovery-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="recovery-codes"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('auth.totp_status_enabled')
  })

  it('bez kódů v odpovědi (uživatel už sadu má) rovnou ukáže aktivní stav', async () => {
    m.totpEnable.mockResolvedValue({ enabled: true })
    const wrapper = mountPage()
    await flushPromises()
    m.totpStatus.mockResolvedValue({ enabled: true })

    await activate(wrapper)

    expect(wrapper.find('[data-test="recovery-codes"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('auth.totp_status_enabled')
  })
})
