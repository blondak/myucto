import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  jmhzTransportHistory: vi.fn(),
  pollJmhzTransportAttempt: vi.fn(),
  closeJmhzTransportAttempt: vi.fn(),
  employerSettings: vi.fn(),
  submissionDetail: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzTransportHistory: m.jmhzTransportHistory,
    pollJmhzTransportAttempt: m.pollJmhzTransportAttempt,
    closeJmhzTransportAttempt: m.closeJmhzTransportAttempt,
    employerSettings: m.employerSettings,
    submissionDetail: m.submissionDetail,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    locale: { value: 'cs' },
  }),
}))

import PayrollTransportHistoryPanel from '@/pages/payroll/PayrollTransportHistoryPanel.vue'

function attempt(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    supplier_id: 1,
    environment: 'production',
    submission_id: 70,
    channel: 'vrep_apep',
    attempt_no: 1,
    status: 'awaiting_protocol',
    correlation_reference: 'ABC-123-XYZ',
    request_sha256: 'a'.repeat(64),
    response_http_status: 200,
    error_code: null,
    error_message: null,
    next_retry_at: null,
    sent_at: '2026-08-10 09:00:00',
    completed_at: null,
    row_version: 2,
    created_by: 3,
    created_at: '2026-08-10 08:59:00',
    updated_at: '2026-08-10 09:00:00',
    ...overrides,
  }
}

describe('PayrollTransportHistoryPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.employerSettings.mockResolvedValue({
      offices: [{
        id: 42,
        code: 'MAIN',
        name: 'Hlavní účtárna',
        social_security_variable_symbol: '1234567890',
        is_active: true,
      }],
    })
    m.submissionDetail.mockResolvedValue({
      submission: { period_start: '2026-07-01', period_end: '2026-07-31' },
    })
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt()],
    })
  })

  it('seskupí pokusy jednoho podání a zachová pořadí z ledgeru', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [
        attempt({ id: 3, attempt_no: 2, status: 'completed', completed_at: '2026-08-11 10:00:00' }),
        attempt({ id: 2, attempt_no: 1, status: 'failed', error_code: 'jmhz_vrep_http_error' }),
        attempt({ id: 9, submission_id: 71, attempt_no: 1, status: 'sent' }),
      ],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(m.jmhzTransportHistory).toHaveBeenCalledWith('production')
    const group = wrapper.get('[data-test="transport-group-70"]')
    expect(group.text()).toContain('payroll.submissions.transport.group.attempts 2')
    expect(group.text()).toContain('2026-07-01')
    const numbers = group.findAll('[data-test^="transport-attempt-"]')
      .map(node => node.attributes('data-test'))
    expect(numbers).toEqual(['transport-attempt-3', 'transport-attempt-2'])
    expect(wrapper.find('[data-test="transport-group-71"]').exists()).toBe(true)
  })

  it('převzetí neoznačí jako přijaté a uzavření u něj vůbec nenabídne', async () => {
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-status-1"]').text())
      .toBe('payroll.submissions.transport.status.awaiting_protocol')
    expect(wrapper.get('[data-test="transport-awaiting-note-1"]').text())
      .toContain('payroll.submissions.transport.awaiting_note')
    expect(wrapper.find('[data-test="transport-close-1"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-poll-1"]').exists()).toBe(true)
  })

  it('selhaný pokus ukáže kód i hlášku rovnou, bez rozklikávání', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({
        id: 5,
        status: 'failed',
        error_code: 'jmhz_vrep_unavailable',
        error_message: 'Brána VREP odpověděla chybou 503.',
        response_http_status: 503,
      })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    const failure = wrapper.get('[data-test="transport-failure-5"]')
    expect(failure.text()).toContain('jmhz_vrep_unavailable')
    expect(failure.text()).toContain('Brána VREP odpověděla chybou 503.')
    expect(wrapper.get('[data-test="transport-attempt-5"]').text()).toContain('503')
  })

  it('selhané načtení nikdy nevykreslí jako „nic neodesláno"', async () => {
    m.jmhzTransportHistory.mockRejectedValue({
      response: { data: { error: { message: 'Databáze je nedostupná.' } } },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-load-error"]').text())
      .toContain('Databáze je nedostupná.')
    expect(wrapper.get('[data-test="transport-load-error"]').text())
      .toContain('payroll.submissions.transport.state_unknown')
    expect(wrapper.find('[data-test="transport-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-loading"]').exists()).toBe(false)
  })

  it('rozliší načítání, prázdný ledger a chybu', async () => {
    m.jmhzTransportHistory.mockReturnValue(new Promise(() => {}))
    const pending = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    expect(pending.find('[data-test="transport-loading"]').exists()).toBe(true)
    expect(pending.find('[data-test="transport-empty"]').exists()).toBe(false)

    m.jmhzTransportHistory.mockResolvedValue({ environment: 'production', attempts: [] })
    const empty = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    expect(empty.get('[data-test="transport-empty"]').text())
      .toContain('payroll.submissions.transport.empty.title')
    expect(empty.find('[data-test="transport-load-error"]').exists()).toBe(false)
  })

  it('bez variabilního symbolu se nedoptá a řekne proč', async () => {
    m.employerSettings.mockResolvedValue({ offices: [] })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-vs-missing"]').text())
      .toContain('payroll.submissions.transport.vs.missing')
    expect(wrapper.get('[data-test="transport-vs-required"]').text())
      .toContain('payroll.submissions.transport.vs.required')
    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()
    expect(m.pollJmhzTransportAttempt).not.toHaveBeenCalled()
  })

  it('jednoznačný variabilní symbol převezme z nastavení a pošle ho při doptání', async () => {
    m.pollJmhzTransportAttempt.mockResolvedValue({
      attempt: attempt(),
      acknowledgement: {
        correlation_id: 'ABC-123-XYZ',
        poll_interval_seconds: 60,
        gateway_timestamp: '2026-08-10 09:05:00',
      },
      settled: false,
      report: null,
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(
      (wrapper.get('[data-test="transport-variable-symbol"]').element as HTMLInputElement).value,
    ).toBe('1234567890')

    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()

    expect(m.pollJmhzTransportAttempt).toHaveBeenCalledWith(1, '1234567890', 'production')
    // Potvrzení o převzetí není výsledek — text o běžícím zpracování, ne o přijetí.
    expect(wrapper.get('[data-test="transport-acknowledgement-1"]').text())
      .toContain('payroll.submissions.transport.acknowledged 60')
    expect(wrapper.find('[data-test="transport-report-1"]').exists()).toBe(false)
  })

  it('protokol vypíše chyby včetně názvu kontroly a dotčených atributů', async () => {
    m.pollJmhzTransportAttempt.mockResolvedValue({
      attempt: attempt({ status: 'completed', completed_at: '2026-08-11 10:00:00' }),
      acknowledgement: null,
      settled: true,
      report: {
        status: 'PartiallyAccepted',
        errors: [
          {
            code: 20370,
            message: 'Pojistné neodpovídá vyměřovacímu základu.',
            origin: 'dis',
            control_id: 370,
            form_guid: 'form-1',
            ik_mpsv: '123456789',
            id_ppv: 'PPV-1',
            control: {
              name: 'Kontrola pojistného',
              detail: 'Pojistné musí odpovídat vyměřovacímu základu.',
              area: 'Pojistné',
              category: 'F1',
              attribute_ids: ['10370', '10477'],
            },
          },
          {
            code: 20022,
            message: 'Neznámá vada podání.',
            origin: 'dis',
            control_id: 22,
            form_guid: null,
            ik_mpsv: null,
            id_ppv: null,
            control: null,
          },
        ],
      },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()
    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()

    const report = wrapper.get('[data-test="transport-report-1"]')
    expect(report.text())
      .toContain('payroll.submissions.transport.protocol_status.PartiallyAccepted')

    const first = wrapper.get('[data-test="transport-report-error-1-0"]')
    expect(first.text()).toContain('Pojistné neodpovídá vyměřovacímu základu.')
    expect(first.text()).toContain('Kontrola pojistného')
    const attributes = wrapper.get('[data-test="transport-report-attributes-1-0"]')
    expect(attributes.text()).toContain('10370')
    expect(attributes.text()).toContain('10477')

    // Kontrola mimo náš katalog se nesmí zamlčet — hláška zůstává vidět.
    const second = wrapper.get('[data-test="transport-report-error-1-1"]')
    expect(second.text()).toContain('Neznámá vada podání.')
    expect(wrapper.get('[data-test="transport-report-uncatalogued-1-1"]').text())
      .toContain('payroll.submissions.transport.report.control_unknown')
  })

  it('uzavřít nabídne jen u dotaženého protokolu a pošle variabilní symbol', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ id: 8, status: 'completed', completed_at: '2026-08-11 10:00:00' })],
    })
    m.closeJmhzTransportAttempt.mockResolvedValue({ closed: true })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-close-note-8"]').text())
      .toContain('payroll.submissions.transport.close_note')
    await wrapper.get('[data-test="transport-close-8"]').trigger('click')
    await flushPromises()

    expect(m.closeJmhzTransportAttempt).toHaveBeenCalledWith(8, '1234567890', 'production')
    expect(wrapper.get('[data-test="transport-success"]').text())
      .toContain('payroll.submissions.transport.closed 8')
  })

  it('v režimu jen pro čtení uzavření nenabídne, doptat se ale nechá', async () => {
    m.canWrite.mockReturnValue(false)
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ id: 8, status: 'completed' })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-close-8"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="transport-poll-8"]').exists()).toBe(true)
  })

  it('bez přiděleného CorrelationID doptání nenabízí', async () => {
    m.jmhzTransportHistory.mockResolvedValue({
      environment: 'production',
      attempts: [attempt({ id: 4, status: 'prepared', correlation_reference: null })],
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="transport-poll-4"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="transport-correlation-4"]').text())
      .toContain('payroll.submissions.transport.correlation_missing')
  })

  it('přepnutí prostředí načte ledger znovu — testovací podání je jiné', async () => {
    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    await wrapper.get('[data-test="transport-environment-test"]').trigger('click')
    await flushPromises()

    expect(m.jmhzTransportHistory).toHaveBeenLastCalledWith('test')
    expect(wrapper.get('[data-test="transport-environment-note"]').text())
      .toContain('payroll.submissions.transport.environment.test_note')
  })

  it('chybu doptání vypíše a seznam pokusů nevyprázdní', async () => {
    m.pollJmhzTransportAttempt.mockRejectedValue({
      response: { data: { error: { message: 'Brána VREP neodpovídá.' } } },
    })

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    await wrapper.get('[data-test="transport-poll-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="transport-error"]').text())
      .toContain('Brána VREP neodpovídá.')
    expect(wrapper.find('[data-test="transport-attempt-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="transport-empty"]').exists()).toBe(false)
  })

  it('nedohledané období nezabrání zobrazení stavů', async () => {
    m.submissionDetail.mockRejectedValue(new Error('nedostupné'))

    const wrapper = mount(PayrollTransportHistoryPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="transport-group-70"]').text())
      .toContain('payroll.submissions.transport.group.period_unknown')
    expect(wrapper.find('[data-test="transport-status-1"]').exists()).toBe(true)
  })
})
