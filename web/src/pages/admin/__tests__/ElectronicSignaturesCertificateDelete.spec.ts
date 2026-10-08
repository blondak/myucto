import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// Mazání certifikátu z trezoru a step-up u výběru certifikátu do podpisového
// profilu: obojí se ověřuje stejně jako nahrání (passkey, nebo heslo + TOTP).

const m = vi.hoisted(() => ({
  listCertificates: vi.fn(),
  deleteCertificate: vi.fn(),
  listSigningProfiles: vi.fn(),
  getSigningProfileCredential: vi.fn(),
  listPersonalSigningCertificates: vi.fn(),
  updateSigningProfile: vi.fn(),
  linkPersonalSigningCertificate: vi.fn(),
  passkeyStepUpOptions: vi.fn(),
  passkeyStepUpVerify: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  confirm: vi.fn(),
}))

vi.mock('@/api/settings', () => ({
  settingsApi: {
    getSigningSettings: vi.fn().mockResolvedValue({ accountant_profiles_enabled: true }),
    getPdfSigningUserDefaults: vi.fn().mockResolvedValue({ output_settings: [], output_types: [], user_defaults: [] }),
    listSigningProfiles: m.listSigningProfiles,
    getSigningProfileCredential: m.getSigningProfileCredential,
    listCertificates: m.listCertificates,
    listPersonalSigningCertificates: m.listPersonalSigningCertificates,
    deleteCertificate: m.deleteCertificate,
    updateSigningProfile: m.updateSigningProfile,
    linkPersonalSigningCertificate: m.linkPersonalSigningCertificate,
  },
}))
vi.mock('@/api/auth', () => ({
  authApi: { passkeyStepUpOptions: m.passkeyStepUpOptions, passkeyStepUpVerify: m.passkeyStepUpVerify },
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_e: unknown, fallback: string) => fallback }))
vi.mock('@/security/webauthn', () => ({ isWebAuthnAvailable: () => true, getCredential: vi.fn().mockResolvedValue({}) }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    isCompanyAdminRole: false,
    canRead: () => true,
    user: { id: 7, totp_enabled: false, mfa_methods: ['passkey'], passkey_count: 1 },
  }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: vi.fn() }),
}))
vi.mock('@/components/ui/EmptyState.vue', () => ({
  default: { name: 'EmptyState', props: ['dense', 'icon', 'title'], template: '<div />' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key} ${JSON.stringify(params)}` : key) }),
}))

import ElectronicSignatures from '@/pages/admin/ElectronicSignatures.vue'

const certificate = {
  id: 41,
  label: 'Syntetický podpis',
  subject_dn: 'CN=Synthetic',
  issuer_dn: 'CN=Synthetic CA',
  serial_hex: '0A',
  fingerprint_sha256: 'ab',
  valid_from: '2026-01-01 00:00:00',
  valid_to: '2099-01-01 00:00:00',
  valid_now: true,
  enabled_for_supplier: true,
  linked_profiles_count: 0,
  linked_supplier_profiles_count: 0,
  linked_data_box_count: 0,
  linked_isds_gateway_count: 0,
  linked_payroll_selections_count: 0,
}

const personalProfile = {
  id: 9,
  owner_user_id: 7,
  name: 'Můj profil',
  code: 'muj',
  allowed_usages: ['pdf'],
  default_backend: 'native',
  pdf_tsa_url: null,
  pdf_tsa_username: null,
  pdf_reason: null,
  is_active: true,
}

async function mountPage() {
  const wrapper = mount(ElectronicSignatures, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
  await flushPromises()
  return wrapper
}

describe('ElectronicSignatures: mazání certifikátu a step-up výběru z trezoru', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listSigningProfiles.mockResolvedValue([])
    m.listPersonalSigningCertificates.mockResolvedValue([certificate])
    m.confirm.mockReturnValue(true)
    vi.stubGlobal('confirm', m.confirm)
  })

  it('smaže certifikát po potvrzení a s ověřením heslem', async () => {
    m.listCertificates.mockResolvedValue([certificate])
    m.deleteCertificate.mockResolvedValue({ deleted: true, payroll_selections_removed: [] })
    const wrapper = await mountPage()

    await wrapper.find('input[autocomplete="current-password"]').setValue('heslo-do-aplikace')
    await wrapper.find('[data-testid="certificate-delete-41"]').trigger('click')
    await flushPromises()

    expect(m.confirm).toHaveBeenCalledWith(expect.stringContaining('Syntetický podpis'))
    expect(m.deleteCertificate).toHaveBeenCalledWith(41, expect.objectContaining({ password: 'heslo-do-aplikace' }))
    expect(m.toastSuccess).toHaveBeenCalledWith('settings.certificate_vault_deleted')
  })

  it('navázaný certifikát nejde smazat a stránka řekne, kde ho odpojit', async () => {
    m.listCertificates.mockResolvedValue([{ ...certificate, linked_profiles_count: 1, linked_data_box_count: 1 }])
    const wrapper = await mountPage()

    const button = wrapper.find('[data-testid="certificate-delete-41"]')
    expect((button.element as HTMLButtonElement).disabled).toBe(true)
    expect(wrapper.text()).toContain('settings.certificate_vault_usage_profiles')
    expect(wrapper.text()).toContain('settings.certificate_vault_usage_data_box')
    expect(m.deleteCertificate).not.toHaveBeenCalled()
  })

  it('odmítnutí 409 přeloží na místa, kde certifikát odpojit', async () => {
    m.listCertificates.mockResolvedValue([certificate])
    m.deleteCertificate.mockRejectedValue({
      response: { data: { error: { code: 'credential_in_use', linked_profiles_count: 2 } } },
    })
    const wrapper = await mountPage()

    await wrapper.find('input[autocomplete="current-password"]').setValue('heslo-do-aplikace')
    await wrapper.find('[data-testid="certificate-delete-41"]').trigger('click')
    await flushPromises()

    expect(m.toastError).toHaveBeenCalledWith(expect.stringContaining('settings.certificate_vault_delete_in_use'))
  })

  it('výběr certifikátu do profilu jde ověřit passkey místo hesla', async () => {
    m.listCertificates.mockResolvedValue([certificate])
    m.listSigningProfiles.mockResolvedValue([personalProfile])
    m.getSigningProfileCredential.mockResolvedValue({ has_certificate: false })
    m.updateSigningProfile.mockResolvedValue(personalProfile)
    m.linkPersonalSigningCertificate.mockResolvedValue({ has_certificate: true, certificate_source: 'personal_vault' })
    m.passkeyStepUpOptions.mockResolvedValue({ flow_token: 'flow', public_key: {} })
    m.passkeyStepUpVerify.mockResolvedValue('passkey-proof-1')
    const wrapper = await mountPage()

    const edit = wrapper.findAll('button').find(button => button.text() === 'common.edit')
    expect(edit).toBeDefined()
    await edit!.trigger('click')
    await flushPromises()

    const select = wrapper.findAll('select').find(item => item.html().includes('settings.signing_vault_choose'))
    expect(select).toBeDefined()
    await select!.setValue('41')
    await wrapper.find('[data-testid="signing-vault-passkey"]').trigger('click')
    await flushPromises()
    expect(m.passkeyStepUpOptions).toHaveBeenCalledWith('epo.certificate')
    expect(wrapper.find('[data-testid="signing-vault-passkey-verified"]').exists()).toBe(true)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.linkPersonalSigningCertificate).toHaveBeenCalledWith(
      9,
      41,
      expect.objectContaining({ step_up_token: 'passkey-proof-1' }),
    )
  })
})
