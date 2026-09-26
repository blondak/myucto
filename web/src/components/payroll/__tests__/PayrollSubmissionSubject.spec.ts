import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollSubmissionSubject from '@/components/payroll/PayrollSubmissionSubject.vue'

function mountSubject(props: Record<string, unknown>) {
  return mount(PayrollSubmissionSubject, {
    props: { label: null, ...props },
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
}

describe('PayrollSubmissionSubject', () => {
  it('links the person name to the employee card', () => {
    const wrapper = mountSubject({ label: 'Syntetický Zaměstnanec', employeeId: 7 })

    const link = wrapper.find('[data-test="submission-subject-link"]')
    expect(link.text()).toBe('Syntetický Zaměstnanec')
    expect(link.attributes('data-to')).toBe('{"name":"payroll-people","query":{"person":"7"}}')
  })

  it('offers the employee card even without a name', () => {
    const wrapper = mountSubject({ employeeId: 7 })

    expect(wrapper.find('[data-test="submission-subject-link"]').text())
      .toBe('payroll.submissions.subject.open_person')
  })

  it('shows a plain label for an office or insurer', () => {
    const wrapper = mountSubject({ label: 'VZP (111)' })

    expect(wrapper.find('[data-test="submission-subject-link"]').exists()).toBe(false)
    expect(wrapper.text()).toBe('VZP (111)')
  })
})
