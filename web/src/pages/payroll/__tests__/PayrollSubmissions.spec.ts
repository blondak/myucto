import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  employerSettings: vi.fn(),
  profile: vi.fn(),
  snapshots: vi.fn(),
  prepare: vi.fn(),
  download: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employerSettings: m.employerSettings,
    regzelProfile: m.profile,
    regzelSnapshots: m.snapshots,
    prepareRegzel: m.prepare,
    downloadRegzelSnapshot: m.download,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (permission: string) => permission === 'payroll.submissions',
  }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollSubmissions from '@/pages/payroll/PayrollSubmissions.vue'

function setup() {
  m.employerSettings.mockResolvedValue({
    offices: [{
      id: 42,
      code: 'MAIN',
      name: 'Hlavní účtárna',
      is_active: true,
    }],
  })
  m.profile.mockResolvedValue({
    supplier_id: 1,
    social_enterprise: false,
    employment_agency: false,
    protected_labor_market: false,
    evidence_confirmed_at: '2026-08-04 12:00:00',
    row_version: 1,
    updated_at: '2026-08-04 12:00:00',
  })
  m.snapshots.mockResolvedValue([])
  m.prepare.mockResolvedValue({
    id: 9,
    environment: 'production',
    office_id: 42,
    document_type: 'REGZELDOPL25',
    interaction_code: 'supplemental_information',
    mapping_version: 'regzeldopl25-map-1',
    xsd_version: '1.2',
    source_snapshot_hash: 'a'.repeat(64),
    xml_sha256: 'b'.repeat(64),
    xml_byte_size: 123,
    request_fingerprint: 'c'.repeat(64),
    created: true,
  })
}

describe('PayrollSubmissions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setup()
  })

  it('oddělí test a produkci, používá standardní záložky a SearchableSelect', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    expect(wrapper.findAll('[role="tab"]')).toHaveLength(3)
    expect(wrapper.findAll('input[role="combobox"]').length).toBeGreaterThanOrEqual(2)
    expect(wrapper.text()).toContain('payroll.regzel.environment.production_warning')

    const environment = wrapper.get('[data-test="regzel-environment"] input')
    await environment.trigger('focus')
    await environment.trigger('keydown', { key: 'ArrowDown' })
    await environment.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(m.snapshots).toHaveBeenLastCalledWith('test')
    expect(wrapper.text()).toContain('payroll.regzel.environment.test_warning')

    const tabs = wrapper.findAll('[role="tab"]')
    await tabs[1]!.trigger('click')
    expect(wrapper.text()).toContain('payroll.submissions.unsupported')
    expect(wrapper.text()).toContain('payroll.submissions.jmhz_fail_closed')
  })

  it('bez potvrzení XML nevytvoří a API chybu zobrazí trvale inline', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    await wrapper.get('[data-test="regzel-prepare"]').trigger('click')
    expect(m.prepare).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="regzel-error"]').text()).toContain(
      'payroll.regzel.prepare.confirmation_required',
    )

    m.prepare.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Produkční VS nesmí být testovací.',
          },
        },
      },
    })
    await wrapper.get('[data-test="regzel-prepare-confirmation"]').setValue(true)
    await wrapper.get('[data-test="regzel-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepare).toHaveBeenCalledWith(expect.objectContaining({
      office_id: 42,
      environment: 'production',
      evidence_confirmed: true,
    }))
    expect(wrapper.get('[data-test="regzel-error"]').text()).toContain(
      'Produkční VS nesmí být testovací.',
    )
  })
})
