import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  freeze: vi.fn(),
  dryRun: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    freezeJmhzPreparation: m.freeze,
    jmhzXmlDryRun: m.dryRun,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollJmhzXmlDryRunPanel from '@/pages/payroll/PayrollJmhzXmlDryRunPanel.vue'

const run = {
  id: 8,
  revision_id: 18,
  revision_no: 2,
  revision_status: 'approved',
  period_start: '2026-08-01',
}

const xml = '<?xml version="1.0" encoding="UTF-8"?>\n<jmhz verze="1.4.3"/>'

describe('PayrollJmhzXmlDryRunPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.freeze.mockResolvedValue({ id: 77, readiness_status: 'source_ready' })
  })

  it('zmrazí přípravu a ukáže XML ověřené proti připnutému schématu', async () => {
    m.dryRun.mockResolvedValue({
      status: 'dry_run_valid',
      preparation_id: 77,
      blockers: [],
      xml,
      xml_sha256: 'b'.repeat(64),
      schema: {
        package_key: 'jmhz-1.4.3.4',
        data_version: '1.4.3',
        bundle_sha256: 'c'.repeat(64),
        document_sha256: 'd'.repeat(64),
      },
      official_submission: {
        supported: false,
        reason_code: 'jmhz_transport_not_implemented',
        reason: 'Kanál zatím není zapojený.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(m.freeze).toHaveBeenCalledWith(18, expect.any(String))
    expect(m.dryRun).toHaveBeenCalledWith(77)
    expect(wrapper.text()).toContain('jmhz_dry_run_valid')
    expect(wrapper.find('pre').exists()).toBe(false)

    await wrapper.findAll('button')[1].trigger('click')
    expect(wrapper.get('pre').text()).toContain('jmhz')
  })

  it('blokovaný dokument vypíše důvody a nenabídne XML', async () => {
    m.dryRun.mockResolvedValue({
      status: 'blocked',
      preparation_id: 77,
      blockers: [
        {
          code: 'jmhz_taxpayer_declaration_unresolved',
          entity_type: 'person',
          entity_id: 11,
          attribute_ids: ['10419'],
        },
      ],
      official_submission: {
        supported: false,
        reason_code: 'jmhz_transport_not_implemented',
        reason: 'Kanál zatím není zapojený.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('jmhz_dry_run_blocked')
    expect(wrapper.text()).toContain('10419')
    expect(wrapper.find('pre').exists()).toBe(false)
  })

  it('vypíše nepropustné vady z katalogu kontrol i s kódem chyby', async () => {
    m.dryRun.mockResolvedValue({
      status: 'dry_run_incomplete',
      preparation_id: 77,
      blockers: [],
      xml,
      xml_sha256: 'b'.repeat(64),
      schema: {
        package_key: 'jmhz-1.4.3.4',
        data_version: '1.4.3',
        bundle_sha256: 'c'.repeat(64),
        document_sha256: 'd'.repeat(64),
      },
      controls: {
        schema_reference: 'payroll-jmhz-control-evaluation.v1',
        catalog_key: 'jmhz-controls-1.4.2.7-source-v3',
        catalog_manifest_sha256: 'e'.repeat(64),
        submittable: false,
        counts: {
          passed: 70,
          failed: 1,
          not_applicable: 90,
          not_evaluable: 33,
          unimplemented: 5,
        },
        blocking: [
          {
            control_id: 8,
            name: 'Pojistné za zaměstnavatele',
            outcome: 'failed',
            scope: 'pvpoj',
            passability: 'blocking',
            technical: false,
            part: 'pvpoj',
            form_ordinal: null,
            message: 'Pojistné 10024 neodpovídá sazbě.',
            attribute_ids: ['10024', '10023'],
            error_code: 20008,
          },
        ],
        warnings: [],
        coverage_gaps: [
          {
            control_id: 59,
            name: 'Vyměřovací základ s podmínkami',
            outcome: 'unimplemented',
            scope: 'employee_form',
            passability: 'blocking',
            technical: false,
            part: 'submission',
            form_ordinal: 0,
            message: 'Kontrola dopadá na podání, ale implementaci nemá.',
            attribute_ids: ['10245'],
            error_code: 20059,
          },
        ],
        evaluated: [],
      },
      official_submission: {
        supported: false,
        reason_code: 'jmhz_transport_not_implemented',
        reason: 'Kanál zatím není zapojený.',
      },
    })

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })
    await wrapper.get('[data-test="jmhz-dry-run-start-18"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('jmhz_dry_run_incomplete')
    expect(wrapper.get('[data-test="jmhz-controls-blocking"]').text()).toContain(
      'Pojistné 10024 neodpovídá sazbě.',
    )
    expect(wrapper.get('[data-test="jmhz-controls-blocking"]').text()).toContain('20008')
    expect(wrapper.get('[data-test="jmhz-controls-gaps"]').text()).toContain('20059')
    expect(wrapper.find('[data-test="jmhz-controls-warnings"]').exists()).toBe(false)
  })

  it('v režimu jen pro čtení nespustí nácvik', async () => {
    m.canWrite.mockReturnValue(false)

    const wrapper = mount(PayrollJmhzXmlDryRunPanel, {
      props: { runs: [run] as never[] },
    })

    expect(
      wrapper.get('[data-test="jmhz-dry-run-start-18"]').attributes('disabled'),
    ).toBeDefined()
    expect(m.freeze).not.toHaveBeenCalled()
  })
})
