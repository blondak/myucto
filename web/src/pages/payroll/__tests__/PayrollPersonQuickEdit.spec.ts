import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type {
  PayrollEmployment,
  PayrollPerson,
  PayrollPersonProfile,
} from '@/api/payroll'

const mocks = vi.hoisted(() => ({
  person: vi.fn(),
  personProfile: vi.fn(),
  savePersonQuickEdit: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    person: mocks.person,
    personProfile: mocks.personProfile,
    savePersonQuickEdit: mocks.savePersonQuickEdit,
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
  }),
}))

import PayrollPersonQuickEdit from '@/pages/payroll/PayrollPersonQuickEdit.vue'

function employment(overrides: Partial<PayrollEmployment> = {}): PayrollEmployment {
  return {
    id: 31,
    employee_id: 17,
    office_id: null,
    office_code: null,
    office_name: null,
    code: 'ZAM-17',
    relation_type: 'employment',
    status: 'active',
    is_primary: true,
    start_date: '2026-01-01',
    actual_start_date: '2026-01-01',
    end_date: null,
    archived_at: null,
    is_legacy_projection: false,
    monthly_gross_minor: 4_200_000,
    row_version: 8,
    allowed_transitions: ['suspended', 'ended'],
    accounting: {
      gross_debit: '521',
      gross_credit: '331',
      employer_insurance_debit: '524',
      employer_insurance_credit: '336',
    },
    terms: [{
      id: 41,
      office_id: null,
      office_code: null,
      effective_from: '2026-01-01',
      effective_to: null,
      contract_signed_on: '2025-12-15',
      planned_start_on: '2026-01-01',
      actual_start_on: '2026-01-01',
      fixed_term_end_on: null,
      weekly_hours: '40.00',
      workload_basis_points: 10000,
      work_place: 'Praha',
      regular_workplace: 'Praha',
      cz_isco_code: '25120',
      activity_code: null,
      social_insurance_participation: 'automatic',
      health_insurance_participation: 'automatic',
      tax_regime: 'advance',
      foreign_legislation_country_code: null,
      a1_certificate_until: null,
      risky_work: false,
      tax_declaration_signed: true,
      is_primary: true,
      change_reason: 'Počáteční podmínky',
      row_version: 2,
      created_at: '2026-01-01 08:00:00',
    }],
    checklist: [],
    timeline: [],
    ...overrides,
  }
}

function person(primary = employment()): PayrollPerson {
  return {
    id: 17,
    full_name: 'Jana Testovací',
    is_active: true,
    profile_status: 'ready',
    legacy_taxpayer_type: 'employee',
    legacy_employment_type: 'hpp',
    employment_count: 1,
    relation_types: ['employment'],
    needs_setup: false,
    employments: [primary],
  }
}

function profile(): PayrollPersonProfile {
  return {
    employee_id: 17,
    full_name: 'Jana Testovací',
    profile_status: 'ready',
    payout_method: 'bank',
    cash_allocation_basis_points: 0,
    payout_effective_on: '2026-01-01',
    secure_delivery_channel: 'portal',
    row_version: 5,
    identity_history: [{
      id: 51,
      full_name: 'Jana Testovací',
      first_name: 'Jana',
      last_name: 'Testovací',
      birth_surname_masked: 'N•••••••',
      effective_from: '2026-01-01',
      effective_to: null,
      row_version: 1,
    }],
    addresses: [{
      id: 52,
      address_type: 'residence',
      address_masked: 'T••••••• 1, P••••, 110 00, CZ',
      effective_from: '2026-01-01',
      effective_to: null,
      row_version: 1,
    }],
    contacts: [{
      id: 53,
      contact_type: 'email',
      value_masked: 'j•••@e••••••.invalid',
      is_primary: true,
      is_active: true,
      row_version: 1,
    }, {
      id: 54,
      contact_type: 'phone',
      value_masked: '+420 ••• ••• 789',
      is_primary: true,
      is_active: true,
      row_version: 1,
    }],
    identifiers: [{
      id: 55,
      identifier_type: 'birth_number',
      value_masked: '••••••/1234',
      row_version: 1,
    }],
    accounts: [],
    created_at: '2026-01-01 08:00:00',
    updated_at: '2026-08-01 08:00:00',
  }
}

async function mountedEditor(canWrite = true) {
  const wrapper = mount(PayrollPersonQuickEdit, {
    props: {
      personId: 17,
      canWrite,
    },
  })
  await flushPromises()
  return wrapper
}

describe('PayrollPersonQuickEdit', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.person.mockResolvedValue(person())
    mocks.personProfile.mockResolvedValue(profile())
    mocks.savePersonQuickEdit.mockResolvedValue({
      profile: profile(),
      employment: employment(),
    })
  })

  it('ukáže běžné údaje v jediném formuláři bez záložek a citlivé hodnoty jen maskovaně', async () => {
    const wrapper = await mountedEditor()

    expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
    expect(wrapper.findAll('form')).toHaveLength(1)
    expect(wrapper.get<HTMLInputElement>('[data-test="first-name"]').element.value).toBe('Jana')
    expect(wrapper.get<HTMLInputElement>('[data-test="last-name"]').element.value).toBe('Testovací')
    expect(wrapper.get<HTMLInputElement>('[data-test="birth-number"]').attributes('placeholder'))
      .toBe('••••••/1234')
    expect(wrapper.get<HTMLInputElement>('[data-test="email"]').attributes('placeholder'))
      .toBe('j•••@e••••••.invalid')
    expect(wrapper.get<HTMLInputElement>('[data-test="phone"]').attributes('placeholder'))
      .toBe('+420 ••• ••• 789')
    expect(wrapper.text()).toContain('T••••••• 1, P••••, 110 00, CZ')
    expect(wrapper.get<HTMLInputElement>('[data-test="birth-number"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="email"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="phone"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="weekly-hours"]').element.value).toBe('40.00')
    expect(wrapper.get<HTMLInputElement>('[data-test="monthly-gross"]').element.value).toBe('42000')
  })

  it('uloží profil a primární vztah jedním atomickým požadavkem s oběma verzemi', async () => {
    const wrapper = await mountedEditor()

    await wrapper.get('[data-test="first-name"]').setValue('Jana Marie')
    await wrapper.get('[data-test="last-name"]').setValue('Bezpečná')
    await wrapper.get('[data-test="birth-number"]').setValue('530101123')
    await wrapper.get('[data-test="street-line"]').setValue('Testovací 12')
    await wrapper.get('[data-test="city"]').setValue('Praha')
    await wrapper.get('[data-test="postal-code"]').setValue('110 00')
    await wrapper.get('[data-test="country-code"]').setValue('CZ')
    await wrapper.get('[data-test="email"]').setValue('jana@example.invalid')
    await wrapper.get('[data-test="phone"]').setValue('+420 777 888 999')
    await wrapper.get('[data-test="weekly-hours"]').setValue('37.5')
    await wrapper.get('[data-test="monthly-gross"]').setValue('45000')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.savePersonQuickEdit).toHaveBeenCalledWith(
      17,
      expect.objectContaining({
        profile: expect.objectContaining({
          row_version: 5,
          identity_history: [expect.objectContaining({
            id: 51,
            full_name: 'Jana Marie Bezpečná',
            first_name: 'Jana Marie',
            last_name: 'Bezpečná',
          })],
          addresses: [expect.objectContaining({
            id: 52,
            street_line: 'Testovací 12',
            city: 'Praha',
            postal_code: '110 00',
            country_code: 'CZ',
          })],
          contacts: expect.arrayContaining([
            expect.objectContaining({ id: 53, value: 'jana@example.invalid' }),
            expect.objectContaining({ id: 54, value: '+420 777 888 999' }),
          ]),
          identifiers: [expect.objectContaining({
            id: 55,
            value: '530101123',
          })],
        }),
        employment: expect.objectContaining({
          id: 31,
          row_version: 8,
          monthly_gross_minor: 4_500_000,
          terms: expect.objectContaining({
            weekly_hours: '37.5',
            planned_start_on: '2026-01-01',
            actual_start_on: '2026-01-01',
          }),
        }),
      }),
    )
    expect(JSON.stringify(mocks.savePersonQuickEdit.mock.calls[0][1]))
      .not.toContain('••')
    expect(wrapper.get<HTMLInputElement>('[data-test="birth-number"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="email"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="phone"]').element.value).toBe('')
    expect(mocks.success).toHaveBeenCalledWith('payroll.people.quick_edit.saved')
  })

  it('při změně jen osobního údaje nevytvoří zbytečnou verzi pracovních podmínek', async () => {
    const wrapper = await mountedEditor()
    await wrapper.get('[data-test="first-name"]').setValue('Jana Marie')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.savePersonQuickEdit).toHaveBeenCalledWith(
      17,
      expect.objectContaining({
        profile: expect.objectContaining({ row_version: 5 }),
        employment: null,
      }),
    )
  })

  it('ponechá při chybě celý formulář beze změny a ukáže přesnou atomickou chybu inline', async () => {
    mocks.savePersonQuickEdit.mockRejectedValueOnce({
      response: {
        status: 409,
        data: {
          error: {
            code: 'row_version_conflict',
            message: 'Profil nebo pracovní vztah mezitím změnil jiný uživatel.',
          },
        },
      },
    })
    const wrapper = await mountedEditor()
    await wrapper.get('[data-test="first-name"]').setValue('Neuložená')
    await wrapper.get('[data-test="monthly-gross"]').setValue('47000')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-test="quick-edit-error"]').text())
      .toContain('Profil nebo pracovní vztah mezitím změnil jiný uživatel.')
    expect(wrapper.get<HTMLInputElement>('[data-test="first-name"]').element.value)
      .toBe('Neuložená')
    expect(wrapper.get<HTMLInputElement>('[data-test="monthly-gross"]').element.value)
      .toBe('47000')
    expect(mocks.error).not.toHaveBeenCalled()
  })

  it('v režimu pouze pro čtení nenechá běžné údaje měnit ani ukládat', async () => {
    const wrapper = await mountedEditor(false)

    expect(wrapper.get<HTMLInputElement>('[data-test="first-name"]').element.matches(':disabled')).toBe(true)
    expect(wrapper.get<HTMLInputElement>('[data-test="birth-number"]').element.matches(':disabled')).toBe(true)
    expect(wrapper.find('[data-test="save-quick-edit"]').exists()).toBe(false)
  })
})
