import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollInstitutionAccount } from '@/api/payroll'

const m = vi.hoisted(() => ({
  institutionAccounts: vi.fn(),
  createInstitutionAccount: vi.fn(),
  updateInstitutionAccount: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    institutionAccounts: m.institutionAccounts,
    createInstitutionAccount: m.createInstitutionAccount,
    updateInstitutionAccount: m.updateInstitutionAccount,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
  }),
}))

import HealthInsurerAccounts from '@/pages/payroll/HealthInsurerAccounts.vue'

function account(overrides: Partial<PayrollInstitutionAccount> = {}): PayrollInstitutionAccount {
  return {
    id: 7,
    supplier_id: 1,
    institution_id: 11,
    institution_type: 'health_insurer',
    institution_code: 'SYNTH-111',
    institution_name: 'Syntetická zdravotní pojišťovna',
    bank_account_masked: '••••0005/0100',
    currency_code: 'CZK',
    variable_symbol: '0012345678',
    specific_symbol: null,
    constant_symbol: '0558',
    valid_from: '2026-01-01',
    valid_to: null,
    source_kind: 'official_document',
    source_reference: 'SYNTHETIC-DOCUMENT-001',
    verified_on: '2026-01-01',
    verified_by: 1,
    row_version: 3,
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-01 00:00:00',
    ...overrides,
  }
}

async function mountComponent(items: PayrollInstitutionAccount[] = [account()]) {
  m.institutionAccounts.mockResolvedValue(items)
  const wrapper = mount(HealthInsurerAccounts, {
    props: { canWrite: true },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

describe('HealthInsurerAccounts', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    document.body.innerHTML = ''
  })

  it('zobrazuje pouze maskovaný účet a údaje o účinnosti a ověření', async () => {
    const wrapper = await mountComponent()

    expect(wrapper.text()).toContain('••••0005/0100')
    expect(wrapper.text()).toContain('0012345678')
    expect(wrapper.text()).toContain('SYNTHETIC-DOCUMENT-001')
    expect(wrapper.text()).not.toContain('1000000005/0100')

    wrapper.unmount()
  })

  it('založí nový historický účet a odešle nemaskovaný účet pouze create endpointu', async () => {
    const created = account({ id: 8, institution_code: 'SYNTH-201', bank_account_masked: '••••0005/0300' })
    m.createInstitutionAccount.mockResolvedValue(created)
    const wrapper = await mountComponent([])

    const add = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.add')
    await add!.trigger('click')
    await wrapper.get('[data-testid="health-create-code"]').setValue('synth-201')
    await wrapper.get('[data-testid="health-create-name"]').setValue('Syntetická zaměstnanecká pojišťovna')
    await wrapper.get('[data-testid="health-create-account"]').setValue('1000000005/0300')
    await wrapper.get('[data-testid="health-create-vs"]').setValue('0000000042')
    await wrapper.get('[data-testid="health-create-source-reference"]').setValue('SYNTHETIC-NOTICE-002')

    const create = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.create')
    await create!.trigger('click')
    await flushPromises()

    expect(m.createInstitutionAccount).toHaveBeenCalledTimes(1)
    expect(m.createInstitutionAccount.mock.calls[0][0]).toMatchObject({
      institution_type: 'health_insurer',
      institution_code: 'SYNTH-201',
      institution_name: 'Syntetická zaměstnanecká pojišťovna',
      bank_account: '1000000005/0300',
      currency_code: 'CZK',
      variable_symbol: '0000000042',
      source_reference: 'SYNTHETIC-NOTICE-002',
    })
    expect(m.createInstitutionAccount.mock.calls[0][0]).not.toHaveProperty('bank_account_masked')
    expect(wrapper.text()).toContain('••••0005/0300')

    wrapper.unmount()
  })

  it('při editaci posílá jen povolená pole a zachová optimistickou verzi', async () => {
    m.updateInstitutionAccount.mockResolvedValue(account({
      variable_symbol: '0000000042',
      row_version: 4,
    }))
    const wrapper = await mountComponent()

    const edit = wrapper.findAll('button').find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    await wrapper.get('[data-testid="health-edit-vs"]').setValue('0000000042')
    const save = wrapper.get('[data-testid="health-account-edit"]').findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.updateInstitutionAccount).toHaveBeenCalledTimes(1)
    expect(m.updateInstitutionAccount.mock.calls[0][0]).toBe(7)
    expect(m.updateInstitutionAccount.mock.calls[0][1]).toMatchObject({
      row_version: 3,
      variable_symbol: '0000000042',
      constant_symbol: '0558',
    })
    expect(m.updateInstitutionAccount.mock.calls[0][1]).not.toHaveProperty('bank_account')
    expect(m.updateInstitutionAccount.mock.calls[0][1]).not.toHaveProperty('bank_account_masked')
    expect(m.updateInstitutionAccount.mock.calls[0][1]).not.toHaveProperty('institution_code')
    expect(m.updateInstitutionAccount.mock.calls[0][1]).not.toHaveProperty('valid_from')

    wrapper.unmount()
  })

  it('neodešle nečíselný VS a konflikt verze vyžádá načtení aktuálních dat', async () => {
    const wrapper = await mountComponent()
    const edit = wrapper.findAll('button').find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    await wrapper.get('[data-testid="health-edit-vs"]').setValue('VS-42')
    let save = wrapper.get('[data-testid="health-account-edit"]').findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    expect(m.updateInstitutionAccount).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="health-edit-vs"]').setValue('42')
    m.updateInstitutionAccount.mockRejectedValue({
      isAxiosError: true,
      response: { status: 409, data: { error: { code: 'row_version_conflict' } } },
    })
    save = wrapper.get('[data-testid="health-account-edit"]').findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.employer.health_accounts.conflict')
    expect(m.updateInstitutionAccount).toHaveBeenCalledTimes(1)

    wrapper.unmount()
  })
})
