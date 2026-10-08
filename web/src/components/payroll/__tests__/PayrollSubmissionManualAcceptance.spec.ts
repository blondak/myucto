import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  accept: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    submissionManualAcceptance: m.overview,
    acceptSubmissionManually: m.accept,
  },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: () => false,
    locale: { value: 'cs' },
  }),
}))

import PayrollSubmissionManualAcceptance from '@/components/payroll/PayrollSubmissionManualAcceptance.vue'
import type { PayrollSubmissionManualAcceptance as Summary } from '@/api/payroll'

const MOUNT = { global: { stubs: { teleport: true } } }

function summary(overrides: Partial<Summary> = {}): Summary {
  return {
    id: 5,
    submission_id: 42,
    variant: 'unchanged',
    status_before: 'processing',
    note: 'V aplikaci ČSSZ stav Přijato.',
    authority_accepted_on: null,
    attachment_artifact_id: null,
    recorded_by: 3,
    recorded_by_name: 'Syntetická účetní',
    recorded_at: '2026-08-04 08:11:12',
    contradiction: null,
    ...overrides,
  }
}

describe('PayrollSubmissionManualAcceptance', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('odešle ruční přijetí s variantou, poznámkou a idempotency klíčem', async () => {
    m.overview.mockResolvedValue({
      submission: { id: 42, environment: 'test', agenda_code: 'JMHZ25', status: 'rejected', row_version: 7 },
      supported: true,
      can_accept: true,
      blocked_reason: null,
      history: [],
    })
    m.accept.mockResolvedValue({
      environment: 'test',
      acceptance: summary({ variant: 'changed_by_authority', status_before: 'rejected' }),
      submission: { id: 42, status: 'accepted', row_version: 9 },
      created: true,
    })
    const wrapper = mount(PayrollSubmissionManualAcceptance, {
      ...MOUNT,
      props: { environment: 'test', submissionId: 42, submissionStatus: 'rejected', canWrite: true },
    })

    await wrapper.get('[data-test="manual-acceptance-open-42"]').trigger('click')
    await flushPromises()
    expect(m.overview).toHaveBeenCalledWith('test', 42)

    const submit = wrapper.get('[data-test="manual-acceptance-submit"]')
    await wrapper.get('[data-test="manual-acceptance-note"]').setValue('krátké')
    expect(submit.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="manual-acceptance-variant-changed_by_authority"]').setValue(true)
    await wrapper.get('[data-test="manual-acceptance-note"]').setValue('OSSZ chybu opravila v aplikaci ČSSZ.')
    await wrapper.get('[data-test="manual-acceptance-dialog"]').trigger('submit')
    await flushPromises()

    expect(m.accept).toHaveBeenCalledTimes(1)
    const [environment, submissionId, input] = m.accept.mock.calls[0]!
    expect(environment).toBe('test')
    expect(submissionId).toBe(42)
    expect(input).toMatchObject({
      rowVersion: 7,
      variant: 'changed_by_authority',
      note: 'OSSZ chybu opravila v aplikaci ČSSZ.',
      acceptedOn: null,
      attachment: null,
    })
    expect(input.idempotencyKey).toMatch(/^manual-acceptance-42-/)
    expect(wrapper.emitted('accepted')).toBeTruthy()
  })

  it('nenabízí akci u přijatého podání ani bez oprávnění, ale ukáže štítek a rozpor', () => {
    const accepted = mount(PayrollSubmissionManualAcceptance, {
      ...MOUNT,
      props: {
        environment: 'production',
        submissionId: 42,
        submissionStatus: 'accepted',
        canWrite: true,
        summary: summary(),
      },
    })
    expect(accepted.find('[data-test="manual-acceptance-open-42"]').exists()).toBe(false)
    expect(accepted.get('[data-test="manual-acceptance-badge-42"]').text())
      .toContain('payroll.submissions.manual_acceptance.badge')

    const readOnly = mount(PayrollSubmissionManualAcceptance, {
      ...MOUNT,
      props: { environment: 'production', submissionId: 42, submissionStatus: 'rejected', canWrite: false },
    })
    expect(readOnly.find('[data-test="manual-acceptance-open-42"]').exists()).toBe(false)

    const contradicted = mount(PayrollSubmissionManualAcceptance, {
      ...MOUNT,
      props: {
        environment: 'production',
        submissionId: 42,
        submissionStatus: 'rejected',
        canWrite: true,
        detailed: true,
        summary: summary({
          contradiction: { receipt_id: 8, remote_status: 'rejected', received_at: '2026-08-05 07:00:00', is_resolved: false },
        }),
      },
    })
    expect(contradicted.find('[data-test="manual-acceptance-contradiction-42"]').exists()).toBe(true)
    expect(contradicted.get('[data-test="manual-acceptance-detail-42"]').text())
      .toContain('V aplikaci ČSSZ stav Přijato.')
    expect(contradicted.find('[data-test="manual-acceptance-open-42"]').exists()).toBe(true)
  })

  it('ukáže důvod, proč server ruční přijetí nepustí', async () => {
    m.overview.mockResolvedValue({
      submission: { id: 42, environment: 'production', agenda_code: 'JMHZ25', status: 'processing', row_version: 2 },
      supported: true,
      can_accept: false,
      blocked_reason: 'Povinnost podání je zrušená.',
      history: [],
    })
    const wrapper = mount(PayrollSubmissionManualAcceptance, {
      ...MOUNT,
      props: { environment: 'production', submissionId: 42, submissionStatus: 'processing', canWrite: true },
    })
    await wrapper.get('[data-test="manual-acceptance-open-42"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="manual-acceptance-blocked"]').text()).toContain('Povinnost podání je zrušená.')
    expect(wrapper.find('[data-test="manual-acceptance-submit"]').exists()).toBe(false)
  })
})
