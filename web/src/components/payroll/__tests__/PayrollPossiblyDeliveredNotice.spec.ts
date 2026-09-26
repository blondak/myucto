import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  confirmSubmissionRetry: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    confirmSubmissionRetry: m.confirmSubmissionRetry,
  },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import PayrollPossiblyDeliveredNotice from '@/components/payroll/PayrollPossiblyDeliveredNotice.vue'

function mountNotice(props: Record<string, unknown> = {}) {
  return mount(PayrollPossiblyDeliveredNotice, {
    props: {
      environment: 'production',
      submissionId: 42,
      errorCode: 'jmhz_vrep_response_lost',
      canWrite: true,
      ...props,
    },
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
}

describe('PayrollPossiblyDeliveredNotice', () => {
  beforeEach(() => {
    m.confirmSubmissionRetry.mockReset()
  })

  it('explains what happened, whom it concerns and where to look for the protocol', () => {
    const wrapper = mountNotice({ correlationReference: 'CORR-1' })

    const text = wrapper.text()
    expect(text).toContain('payroll.transport_delivery.possibly_title')
    expect(text).toContain('payroll.transport_delivery.possibly_what')
    expect(text).toContain('payroll.transport_delivery.who 42')
    expect(text).toContain('payroll.transport_delivery.possibly_where')
    expect(text).toContain('payroll.transport_delivery.correlation CORR-1')
    expect(wrapper.find('[data-test="possibly-delivered-databox-42"]').attributes('href'))
      .toBe('/admin/databox?tab=inbox')
  })

  it('asks the parent to load the protocol before anything else', async () => {
    const wrapper = mountNotice()

    await wrapper.find('[data-test="possibly-delivered-import-42"]').trigger('click')

    expect(wrapper.emitted('import-protocol')).toHaveLength(1)
    expect(m.confirmSubmissionRetry).not.toHaveBeenCalled()
  })

  it('links to Dispatch status when it cannot load the protocol itself', () => {
    const wrapper = mountNotice({ importMode: 'link' })

    const link = wrapper.find('[data-test="possibly-delivered-import-link-42"]')
    expect(link.exists()).toBe(true)
    expect(link.attributes('data-to')).toContain('"tab":"transport"')
  })

  it('confirms the retry only with a reason and the explicit tick', async () => {
    m.confirmSubmissionRetry.mockResolvedValue({ confirmed_attempts: [7], submission_guid: 'G' })
    const wrapper = mountNotice()

    await wrapper.find('[data-test="possibly-delivered-confirm-open-42"]').trigger('click')
    const submit = wrapper.find('[data-test="possibly-delivered-confirm-submit-42"]')
    expect(submit.attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="possibly-delivered-reason-42"]').setValue('Protokol nikde není.')
    expect(submit.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="possibly-delivered-searched-42"]').setValue(true)
    expect(submit.attributes('disabled')).toBeUndefined()

    await wrapper.find('[data-test="possibly-delivered-confirm-form-42"]').trigger('submit')
    await flushPromises()

    expect(m.confirmSubmissionRetry).toHaveBeenCalledWith('production', 42, 'Protokol nikde není.')
    expect(wrapper.emitted('confirmed')).toHaveLength(1)
    expect(wrapper.find('[data-test="possibly-delivered-confirmed-42"]').exists()).toBe(true)
  })

  it('shows the server refusal when the original protocol is already loaded', async () => {
    m.confirmSubmissionRetry.mockRejectedValue({
      response: { data: { error: { code: 'conflict', message: 'originál je tedy u ČSSZ' } } },
    })
    const wrapper = mountNotice()

    await wrapper.find('[data-test="possibly-delivered-confirm-open-42"]').trigger('click')
    await wrapper.find('[data-test="possibly-delivered-reason-42"]').setValue('Nevím.')
    await wrapper.find('[data-test="possibly-delivered-searched-42"]').setValue(true)
    await wrapper.find('[data-test="possibly-delivered-confirm-form-42"]').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('originál je tedy u ČSSZ')
    expect(wrapper.emitted('confirmed')).toBeUndefined()
  })

  it('never offers a retry when ČSSZ said the original is already there', () => {
    const wrapper = mountNotice({ errorCode: 'jmhz_original_at_cssz' })

    expect(wrapper.text()).toContain('payroll.transport_delivery.original_title')
    expect(wrapper.text()).toContain('payroll.transport_delivery.original_where')
    expect(wrapper.find('[data-test="possibly-delivered-confirm-open-42"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="possibly-delivered-import-42"]').exists()).toBe(true)
  })

  it('does not let a read-only user confirm', () => {
    const wrapper = mountNotice({ canWrite: false })

    expect(wrapper.find('[data-test="possibly-delivered-confirm-open-42"]').exists()).toBe(false)
  })
})
