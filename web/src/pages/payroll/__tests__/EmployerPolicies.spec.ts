import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollEmployerPolicy, PayrollSetupCheck } from '@/api/payroll'

const m = vi.hoisted(() => ({
  employerPolicies: vi.fn(),
  payrollSetupCheck: vi.fn(),
  createEmployerPolicy: vi.fn(),
  updateEmployerPolicy: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employerPolicies: m.employerPolicies,
    payrollSetupCheck: m.payrollSetupCheck,
    createEmployerPolicy: m.createEmployerPolicy,
    updateEmployerPolicy: m.updateEmployerPolicy,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import EmployerPolicies from '@/pages/payroll/EmployerPolicies.vue'

function policy(overrides: Partial<PayrollEmployerPolicy> = {}): PayrollEmployerPolicy {
  return {
    id: 41,
    supplier_id: 7,
    valid_from: '2026-01-01',
    valid_to: null,
    payday_day: 10,
    payday_month_offset: 1,
    payday_business_day_rule: 'previous_business_day',
    balance_rounding_mode: 'exact_minor_units',
    home_office_policy: 'not_used',
    travel_expense_policy: 'not_used',
    four_eyes_required: true,
    automatic_calculation_enabled: false,
    automatic_posting_enabled: false,
    automatic_payments_enabled: false,
    delivery_channel: 'disabled',
    delivery_verified_on: null,
    source_kind: 'migration',
    source_reference: 'synthetic-source',
    created_by: 3,
    updated_by: 3,
    row_version: 2,
    created_at: '2026-01-01 08:00:00',
    updated_at: '2026-01-01 08:00:00',
    ...overrides,
  }
}

function setup(ready = true): PayrollSetupCheck {
  return {
    ready,
    effective_on: '2026-08-04',
    policy_id: ready ? 41 : null,
    checks: [
      {
        code: 'employer_settings',
        status: 'ok',
        message: 'server Czech text must not be the English UI source',
      },
      {
        code: 'effective_policy',
        status: ready ? 'ok' : 'blocked',
        message: 'server fallback',
      },
    ],
    blockers: ready ? [] : ['effective_policy'],
  }
}

async function mountComponent(canWrite = true, policies = [policy()]) {
  m.employerPolicies.mockResolvedValue(policies)
  m.payrollSetupCheck.mockResolvedValue(setup(policies.length > 0))
  m.createEmployerPolicy.mockImplementation(async payload => policy({
    id: 42,
    row_version: 1,
    source_kind: payload.source_kind,
  }))
  m.updateEmployerPolicy.mockImplementation(async (_id, payload) => policy({
    ...payload,
    row_version: payload.row_version + 1,
  }))
  const wrapper = mount(EmployerPolicies, {
    props: { canWrite },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

describe('EmployerPolicies', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    document.body.innerHTML = ''
  })

  it('zobrazuje kontrolu připravenosti, desktopovou historii i mobilní karty', async () => {
    const wrapper = await mountComponent()

    expect(m.employerPolicies).toHaveBeenCalledOnce()
    expect(m.payrollSetupCheck).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('payroll.employer.policies.setup_ready')
    expect(wrapper.text()).toContain('payroll.employer.policies.checks.employer_settings.ok')
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.find('[class*="md:hidden"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('novou politiku odešle jako ruční zdroj s bezpečnými výchozími hodnotami', async () => {
    const wrapper = await mountComponent(true, [])

    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    expect(save).toBeDefined()
    await save!.trigger('click')
    await flushPromises()

    expect(m.createEmployerPolicy).toHaveBeenCalledOnce()
    expect(m.createEmployerPolicy.mock.calls[0][0]).toMatchObject({
      row_version: 0,
      source_kind: 'manual',
      four_eyes_required: true,
      automatic_calculation_enabled: false,
      automatic_posting_enabled: false,
      automatic_payments_enabled: false,
      delivery_channel: 'disabled',
      delivery_verified_on: null,
    })

    wrapper.unmount()
  })

  it('při úpravě zachová původ a optimistickou verzi', async () => {
    const current = policy({ source_kind: 'migration', row_version: 8 })
    const wrapper = await mountComponent(true, [current])
    const edit = wrapper.findAll('button')
      .find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.updateEmployerPolicy).toHaveBeenCalledOnce()
    expect(m.updateEmployerPolicy.mock.calls[0][0]).toBe(41)
    expect(m.updateEmployerPolicy.mock.calls[0][1]).toMatchObject({
      source_kind: 'migration',
      row_version: 8,
    })

    wrapper.unmount()
  })

  it('umožní zrušit konec platnosti a odešle otevřený interval jako null', async () => {
    const wrapper = await mountComponent(true, [policy({ valid_to: '2026-12-31' })])
    const edit = wrapper.findAll('button')
      .find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    const dateInputs = wrapper.findAll('input[type="date"]')
    await dateInputs[2]!.setValue('')
    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.updateEmployerPolicy).toHaveBeenCalledOnce()
    expect(m.updateEmployerPolicy.mock.calls[0][1].valid_to).toBeNull()

    wrapper.unmount()
  })

  it('po úspěšném uložení neoznačí selhání obnovy checklistu za selhání mutace', async () => {
    const wrapper = await mountComponent(true, [])
    m.payrollSetupCheck.mockRejectedValueOnce(new Error('setup unavailable'))

    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.createEmployerPolicy).toHaveBeenCalledOnce()
    expect(m.toastSuccess).toHaveBeenCalledWith('payroll.employer.policies.saved')
    expect(wrapper.text()).toContain('payroll.employer.policies.setup_failed')
    expect(wrapper.text()).not.toContain('payroll.employer.policies.save_failed')

    wrapper.unmount()
  })

  it('ponechá přesný důvod konfliktu ve formuláři a nabídne reload', async () => {
    const error = Object.assign(new Error('conflict'), {
      isAxiosError: true,
      response: {
        status: 409,
        data: {
          error: {
            code: 'row_version_conflict',
            message: 'Syntetická novější verze existuje.',
            current_row_version: 9,
          },
        },
      },
    })
    const wrapper = await mountComponent()
    m.updateEmployerPolicy.mockRejectedValueOnce(error)
    const edit = wrapper.findAll('button')
      .find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Syntetická novější verze existuje.')
    expect(wrapper.text()).toContain('payroll.employer.policies.reload_current')

    m.employerPolicies.mockResolvedValueOnce([
      policy({ source_kind: 'migration', row_version: 9 }),
    ])
    const reload = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.policies.reload_current')
    await reload!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).not.toContain('Syntetická novější verze existuje.')

    const retrySave = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await retrySave!.trigger('click')
    await flushPromises()
    expect(m.updateEmployerPolicy).toHaveBeenCalledTimes(2)
    expect(m.updateEmployerPolicy.mock.calls[1][1]).toMatchObject({
      source_kind: 'migration',
      row_version: 9,
    })

    wrapper.unmount()
  })

  it('read-only role nevidí žádnou mutační akci', async () => {
    const wrapper = await mountComponent(false)

    expect(wrapper.text()).not.toContain('payroll.employer.policies.add')
    expect(wrapper.findAll('button').some(button => button.text() === 'common.edit')).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === 'common.save')).toBe(false)

    wrapper.unmount()
  })
})
