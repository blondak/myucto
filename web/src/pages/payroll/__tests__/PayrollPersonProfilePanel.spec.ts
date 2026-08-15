import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollPersonProfile } from '@/api/payroll'

const mocks = vi.hoisted(() => ({
  personProfile: vi.fn(),
  savePersonProfile: vi.fn(),
  verifyPersonAccount: vi.fn(),
  countries: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    personProfile: mocks.personProfile,
    savePersonProfile: mocks.savePersonProfile,
    verifyPersonAccount: mocks.verifyPersonAccount,
  },
}))

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    countries: mocks.countries,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    success: mocks.success,
    error: mocks.error,
  }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollPersonProfilePanel from '@/pages/payroll/PayrollPersonProfilePanel.vue'

function profile(): PayrollPersonProfile {
  return {
    employee_id: 17,
    full_name: 'Testovací Zaměstnanec',
    profile_status: 'setup',
    payout_method: 'bank',
  partner_settlement_account_code: null,
    cash_allocation_basis_points: 0,
    payout_effective_on: '2026-08-01',
    secure_delivery_channel: 'portal',
    row_version: 3,
    identity_history: [{
      id: 1,
      full_name: 'Testovací Zaměstnanec',
      first_name: 'Testovací',
      last_name: 'Zaměstnanec',
      birth_surname_masked: 'T•••••••',
      effective_from: '2026-01-01',
      effective_to: null,
      row_version: 1,
    }],
    addresses: [{
      id: 2,
      address_type: 'residence',
      address_masked: 'P••••, CZ',
      effective_from: '2026-01-01',
      effective_to: null,
      row_version: 1,
    }],
    contacts: [{
      id: 3,
      contact_type: 'email',
      value_masked: 't•••@e••••••.cz',
      is_primary: true,
      is_active: true,
      row_version: 1,
    }],
    identifiers: [{
      id: 4,
      identifier_type: 'birth_number',
      value_masked: '••••••/••••',
      row_version: 1,
    }],
    accounts: [{
      id: 5,
      label: 'Výplata',
      bank_account_masked: '••••••0005/0100',
      allocation_basis_points: 10000,
      effective_from: '2026-01-01',
      effective_to: null,
      is_active: true,
      row_version: 4,
      verification_source: 'bank_document',
      verified_on: '2026-07-31',
      verified_by: 9,
    }],
    created_at: '2026-01-01 10:00:00',
    updated_at: '2026-08-01 10:00:00',
  }
}

async function mountedPanel() {
  const wrapper = mount(PayrollPersonProfilePanel, {
    props: {
      personId: 17,
      canWrite: true,
    },
  })
  await flushPromises()
  return wrapper
}

async function openPayout(wrapper: Awaited<ReturnType<typeof mountedPanel>>) {
  const button = wrapper.findAll('button').find(item =>
    item.text().includes('payroll.people.profile.tabs.payout'),
  )
  expect(button).toBeDefined()
  await button!.trigger('click')
}

describe('PayrollPersonProfilePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.personProfile.mockResolvedValue(profile())
    mocks.savePersonProfile.mockResolvedValue(profile())
    mocks.countries.mockResolvedValue([{
      id: 1,
      iso2: 'CZ',
      iso3: 'CZE',
      name_cs: 'Česko',
      name_en: 'Czechia',
      is_eu: true,
    }])
    mocks.verifyPersonAccount.mockResolvedValue({
      ...profile().accounts[0],
      verification_source: 'user_verified',
      verified_on: '2026-08-04',
      row_version: 5,
    })
  })

  it('zobrazuje pouze maskované citlivé hodnoty', async () => {
    const wrapper = await mountedPanel()

    expect(wrapper.text()).toContain('T•••••••')
    expect(wrapper.text()).toContain('P••••, CZ')
    expect(wrapper.text()).not.toContain('1000000005/0100')

    await openPayout(wrapper)
    expect(wrapper.text()).toContain('••••••0005/0100')
    expect(wrapper.get<HTMLInputElement>('[data-test="bank-account-plaintext"]').element.value).toBe('')
  })

  it('používá pro všechny adresy společný číselník států', async () => {
    const wrapper = await mountedPanel()

    expect(wrapper.find('[data-test="profile-country-code"] input').exists()).toBe(true)
  })

  it('používá pro přidávací akce plné primární tlačítko', async () => {
    const wrapper = await mountedPanel()
    const addIdentity = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.profile.add_identity'),
    )

    expect(addIdentity).toBeDefined()
    expect(addIdentity!.classes()).toContain('bg-primary-600')
    expect(addIdentity!.classes()).toContain('text-white')
  })

  it('pošle nový plaintext jen v PUT payloadu a po uložení input vyčistí', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)
    const input = wrapper.get<HTMLInputElement>('[data-test="bank-account-plaintext"]')
    await input.setValue('1000000005/0100')

    await wrapper.get('[data-test="save-profile"]').trigger('click')
    await flushPromises()

    expect(mocks.savePersonProfile).toHaveBeenCalledWith(
      17,
      expect.objectContaining({
        row_version: 3,
        identity_history: [expect.objectContaining({
          id: 1,
          full_name: 'Testovací Zaměstnanec',
          first_name: 'Testovací',
          last_name: 'Zaměstnanec',
        })],
        accounts: [expect.objectContaining({
          id: 5,
          bank_account: '1000000005/0100',
        })],
      }),
    )
    expect(wrapper.get<HTMLInputElement>('[data-test="bank-account-plaintext"]').element.value).toBe('')
  })

  it('ověření účtu používá jeho expected row_version', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    await wrapper.get('[data-test="verify-account"]').trigger('click')
    await flushPromises()

    expect(mocks.verifyPersonAccount).toHaveBeenCalledWith(17, 5, {
      verification_source: 'bank_document',
      verified_on: '2026-07-31',
      row_version: 4,
    })
    expect(wrapper.text()).toContain('payroll.people.profile.verified_summary')
  })

  it('neumožní ověřit účet s dosud neuloženou změnou', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)
    await wrapper
      .get<HTMLInputElement>('[data-test="bank-account-plaintext"]')
      .setValue('1000000005/0100')

    const verify = wrapper.get<HTMLButtonElement>(
      '[data-test="verify-account"]',
    )
    expect(verify.element.disabled).toBe(true)
    await verify.trigger('click')

    expect(mocks.verifyPersonAccount).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain(
      'payroll.people.profile.save_before_verify',
    )
  })

  it.each([
    [409, 'Profil mezitím změnil jiný uživatel.'],
    [422, 'Datum ověření není platné.'],
  ])('zobrazí konkrétní chybu API pro stav %i', async (status, message) => {
    mocks.verifyPersonAccount.mockRejectedValueOnce({
      response: {
        status,
        data: { error: { code: 'validation_failed', message } },
      },
    })
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    await wrapper.get('[data-test="verify-account"]').trigger('click')
    await flushPromises()

    expect(mocks.error).toHaveBeenCalledWith(message)
  })
})
