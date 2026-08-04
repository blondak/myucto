import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  employerSettings: vi.fn(),
  profile: vi.fn(),
  snapshots: vi.fn(),
  prepare: vi.fn(),
  download: vi.fn(),
  overview: vi.fn(),
  runs: vi.fn(),
  healthOverviews: vi.fn(),
  downloadHealthOverview: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employerSettings: m.employerSettings,
    regzelProfile: m.profile,
    regzelSnapshots: m.snapshots,
    prepareRegzel: m.prepare,
    downloadRegzelSnapshot: m.download,
    submissionOverview: m.overview,
    runs: m.runs,
    healthPaymentOverviews: m.healthOverviews,
    downloadHealthPaymentOverview: m.downloadHealthOverview,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (permission: string) => permission === 'payroll.submissions',
  }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters
        ? `${key} ${Object.values(parameters).join(' ')}`
        : key,
    locale: { value: 'cs' },
  }),
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
  m.overview.mockResolvedValue({
    environment: 'production',
    period: '2026-08',
    summary: {
      total: 1,
      open: 1,
      prepared: 0,
      submitted: 0,
      fulfilled: 0,
      overdue: 0,
      manual_review: 0,
      other: 0,
    },
    items: [{
      id: 7,
      environment: 'production',
      agenda_code: 'JMHZ',
      subject_type: 'office',
      subject_reference: 'office:synthetic',
      period_start: '2026-08-01',
      period_end: '2026-08-31',
      obligation_kind: 'regular',
      preferred_channel: 'manual_upload',
      status: 'open',
      row_version: 1,
      earliest_submission_on: '2026-09-01',
      due_on: '2026-09-20',
      calendar_basis: 'calendar_days',
      latest_submission: null,
    }],
  })
  m.runs.mockResolvedValue([{
    id: 8,
    status: 'approved',
    revision_id: 18,
    revision_status: 'approved',
  }, {
    id: 9,
    status: 'posted',
    revision_id: 19,
    revision_status: 'approved',
  }])
  m.healthOverviews.mockImplementation(async (revisionId: number) => ({
    items: [{
      schema_reference: 'payroll-health-payment-overview.v1',
      document_kind: 'internal_health_payment_overview',
      official_submission: {
        supported: false,
        reason_code: 'health_insurance_official_format_unavailable',
      },
      supplier_id: 1,
      run_id: revisionId === 18 ? 8 : 9,
      revision_id: revisionId,
      revision_no: 1,
      period: '2026-08',
      currency_code: 'CZK',
      insurer: { code: '111' },
      source: {
        statutory_result_id: 90,
        statutory_result_hash: 'a'.repeat(64),
        ruleset_id: 'cz-health-2026',
        ruleset_hash: 'b'.repeat(64),
      },
      totals: {
        person_count: 2,
        assessment_base_minor_units: 10_000_000,
        employee_contribution_minor_units: 450_000,
        employer_contribution_minor_units: 900_000,
        total_contribution_minor_units: 1_350_000,
      },
      people: [],
      sha256: 'c'.repeat(64),
      filename: `zp-prehled-2026-08-111-revize-${revisionId}.json`,
    }],
    electronic_submission: {
      supported: false,
      reason_code: 'health_insurance_transport_unavailable',
    },
  }))
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
    await flushPromises()
    expect(m.overview).toHaveBeenCalledWith(
      'production',
      expect.stringMatching(/^[0-9]{4}-[0-9]{2}$/),
    )
    expect(wrapper.text()).toContain('JMHZ')
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

  it('nabídne interní měsíční přehled zdravotní pojišťovny ke stažení', async () => {
    const wrapper = mount(PayrollSubmissions)
    await flushPromises()

    await wrapper.findAll('[role="tab"]')[2]!.trigger('click')
    await flushPromises()

    expect(m.runs).toHaveBeenCalledWith(expect.stringMatching(/^[0-9]{4}-[0-9]{2}$/))
    expect(m.healthOverviews).toHaveBeenCalledWith(18)
    expect(m.healthOverviews).toHaveBeenCalledWith(19)
    expect(m.healthOverviews).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('[data-test="health-payment-overviews"] article')).toHaveLength(2)
    expect(wrapper.get('[data-test="health-payment-overviews"]').text()).toContain('111')
    expect(wrapper.get('[data-test="health-payment-overviews"]').text())
      .toContain('payroll.submissions.overview.health_description')

    const download = wrapper.get('[data-test="health-payment-overviews"] button')
    await download.trigger('click')
    await flushPromises()
    expect(m.downloadHealthOverview).toHaveBeenCalledWith(
      expect.objectContaining({ revision_id: 18, insurer: { code: '111' } }),
    )
  })
})
