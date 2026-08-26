import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  prepare: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    previewEmploymentRegistration: m.preview,
    prepareEmploymentRegistration: m.prepare,
  },
}))

vi.mock('@/api/errors', () => ({
  apiErrorMessage: (exception: unknown, fallback: string) =>
    (exception as { message?: string }).message ?? fallback,
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: { value: 'cs' },
  }),
}))

import EmploymentRegistrationPanel from '@/pages/payroll/EmploymentRegistrationPanel.vue'

const deadline = {
  earliest_registration_on: '2026-08-14',
  due_on: '2026-08-22',
  calendar_basis: 'calendar_days',
  ruleset_id: 'cz-employee-registration-2026-07.v1',
}

const preview = {
  employment_id: 5,
  agenda_code: 'PREZEC26',
  interaction: 'limited_pre_registration',
  action_code: 9,
  xml: '<PREZEC/>',
  xml_sha256: 'a'.repeat(64),
  deadline,
  employer_registration: null,
  official_submission: { supported: false, reason: 'Test.' },
}

function mountPanel(canWrite = true) {
  return mount(EmploymentRegistrationPanel, {
    props: { employmentId: 5, canWrite },
  })
}

describe('EmploymentRegistrationPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.preview.mockResolvedValue(preview)
  })

  it('shows the deadline window and which form will be filed', async () => {
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    const window = wrapper.find('[data-test="registration-deadline"]')
    expect(window.exists()).toBe(true)
    expect(window.text()).toContain('registration.agenda.PREZEC26')
    expect(window.text()).toContain('registration.interaction.limited_pre_registration')
  })

  it('never claims the employee is registered once the filing is prepared', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 12,
      obligation_id: 3,
      part_id: 4,
      artifact_id: 6,
      status: 'ready',
      row_version: 3,
      environment: 'test',
      agenda_code: 'PREZEC26',
      interaction: 'limited_pre_registration',
      artifact_sha256: 'b'.repeat(64),
      created: true,
      deadline,
    })
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="registration-prepare"]').trigger('click')
    await flushPromises()

    const prepared = wrapper.find('[data-test="registration-prepared"]')
    expect(prepared.exists()).toBe(true)
    // „Odesláno != přijato" musí být vidět i v UI.
    expect(prepared.text()).toContain('registration.not_sent_yet')
  })

  it('surfaces the server message naming the missing field', async () => {
    m.preview.mockRejectedValue({
      message: 'Účtárna nemá vyplněný variabilní symbol ČSSZ.',
    })
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="registration-error"]').text())
      .toContain('variabilní symbol')
  })

  it('warns about the employer deadline for the first employee', async () => {
    m.preview.mockResolvedValue({
      ...preview,
      employer_registration: {
        earliest_registration_on: '2026-08-07',
        due_on: '2026-08-20',
        deemed_employer_from: '2026-08-07',
        no_show_notification_due_on: '2026-08-30',
        calendar_basis: 'czech_working_days',
        ruleset_id: 'cz-jmhz-employer-registration-2026-07.v1',
      },
    })
    const wrapper = mountPanel()
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="registration-employer-deadline"]').exists())
      .toBe(true)
  })

  it('keeps the filing action disabled without write permission', async () => {
    const wrapper = mountPanel(false)
    await wrapper.find('[data-test="registration-preview"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.find('[data-test="registration-prepare"]').attributes('disabled'),
    ).toBeDefined()
  })
})
