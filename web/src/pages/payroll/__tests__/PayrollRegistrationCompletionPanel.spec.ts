import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  candidates: vi.fn(),
  complete: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    registrationCompletionCandidates: m.candidates,
    completeRegistrationProfiles: m.complete,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: { value: 'cs' },
  }),
}))

import PayrollRegistrationCompletionPanel from '@/pages/payroll/PayrollRegistrationCompletionPanel.vue'

function candidate(overrides: Record<string, unknown> = {}) {
  return {
    employment_id: 5,
    employee_id: 9,
    employee_name: 'Syntetická Osoba',
    code: 'OS-1',
    relation_type: 'employment',
    status: 'active',
    start_date: '2026-02-15',
    end_date: null,
    profile_status: 'verified',
    profile_effective_on: '2026-02-15',
    completion_event_id: null,
    completion_effective_on: null,
    completion_submission_id: null,
    completion_submission_status: null,
    ...overrides,
  }
}

function mountPanel() {
  return mount(PayrollRegistrationCompletionPanel, {
    props: { environment: 'test' },
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  })
}

describe('PayrollRegistrationCompletionPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.candidates.mockResolvedValue({
      today: '2026-09-26',
      items: [
        candidate(),
        candidate({
          employment_id: 6,
          employee_name: 'Bez Profilu',
          profile_status: null,
          end_date: '2026-06-30',
        }),
      ],
    })
  })

  it('lets only relationships with a verified A1 profile be selected and links the rest to the profile', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(m.candidates).toHaveBeenCalledWith('test')
    const ready = wrapper.get('[data-test="registration-completion-select-5"]').element as HTMLInputElement
    const missing = wrapper.get('[data-test="registration-completion-select-6"]').element as HTMLInputElement
    expect(ready.disabled).toBe(false)
    expect(missing.disabled).toBe(true)
    expect(wrapper.get('[data-test="registration-completion-profile-6"]').text())
      .toContain('payroll.registrationCompletion.profile.missing')
    expect(JSON.parse(wrapper.get('[data-test="registration-completion-open-6"]').attributes('data-to') ?? '{}'))
      .toEqual({
        name: 'payroll-people',
        query: { person: '9', employment: '6', panel: 'registration' },
      })
  })

  it('submits the selected relationships with the chosen scope and shows per-row failures', async () => {
    m.complete.mockResolvedValue({
      effective_on: '2026-09-26',
      completion: 'minimal',
      results: [{
        employment_id: 5,
        status: 'failed',
        event_id: null,
        submission_id: null,
        created: false,
        code: 'registration_a3_completion_profile_incomplete',
        message: 'Profil registrace (A1) není úplný.',
      }],
    })
    const wrapper = mountPanel()
    await flushPromises()

    const submit = wrapper.get('[data-test="registration-completion-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="registration-completion-select-5"]').setValue(true)
    await wrapper.get('[data-test="registration-completion-mode"]').setValue('minimal')
    await submit.trigger('click')
    await flushPromises()

    expect(m.complete).toHaveBeenCalledWith({
      environment: 'test',
      employment_ids: [5],
      completion: 'minimal',
      effective_on: '2026-09-26',
    })
    expect(wrapper.get('[data-test="registration-completion-failure-5"]').text())
      .toContain('Profil registrace (A1) není úplný.')
    expect(wrapper.get('[data-test="registration-completion-success"]').text())
      .toContain('"failed":1')
  })

  it('převzatá firma: dohlášení vyřízená předchozím programem skryje, na přepnutí je ukáže s odkazem na podání', async () => {
    m.candidates.mockResolvedValue({
      today: '2026-09-26',
      items: [
        candidate({
          employment_id: 7,
          profile_status: null,
          predecessor_reason: 'registration',
          predecessor_submission_id: 122,
          predecessor_submitted_at: '2026-04-29 07:41:28',
          predecessor_action: 'A3',
          predecessor_program: 'PAMICA',
        }),
        candidate({ employment_id: 8, profile_status: null, predecessor_reason: 'deadline' }),
        candidate({ employment_id: 9, profile_status: null }),
      ],
    })
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="registration-completion-row-7"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="registration-completion-row-8"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="registration-completion-row-9"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="registration-completion-filter"]').text()).toContain('"pending":1')

    await wrapper.get('[data-test="registration-completion-show-all"]').setValue(true)
    expect(wrapper.get('[data-test="registration-completion-predecessor-7"]').text())
      .toContain('payroll.registrationCompletion.predecessor.badge')
    expect(JSON.parse(wrapper.get('[data-test="registration-completion-predecessor-link-7"]').attributes('data-to') ?? '{}'))
      .toEqual({
        name: 'payroll-submissions-tab',
        params: { tab: 'jmhz' },
        query: { external: '122' },
        hash: '#external-submissions',
      })
    expect(wrapper.get('[data-test="registration-completion-predecessor-8"]').text())
      .toContain('payroll.registrationCompletion.predecessor.deadline')
    expect(wrapper.find('[data-test="registration-completion-predecessor-9"]').exists()).toBe(false)
  })

  it('když nic nechybí, řekne to a nechá ukázat všechny vztahy', async () => {
    m.candidates.mockResolvedValue({
      today: '2026-09-26',
      items: [candidate({ predecessor_reason: 'deadline' })],
    })
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="registration-completion-nothing-pending"]').exists()).toBe(true)
    await wrapper.get('[data-test="registration-completion-show-all"]').setValue(true)
    const select = wrapper.get('[data-test="registration-completion-select-5"]').element as HTMLInputElement
    expect(select.disabled).toBe(false)
  })
})
