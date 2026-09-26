import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollLateDiscountConfirm from '@/components/payroll/PayrollLateDiscountConfirm.vue'

function mountConfirm() {
  return mount(PayrollLateDiscountConfirm, {
    props: { message: 'Hlášení se podává po lhůtě (2026-08-20).', testId: 'late' },
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
}

describe('PayrollLateDiscountConfirm', () => {
  it('shows the server sentence, where to verify and where to correct the discount', () => {
    const wrapper = mountConfirm()

    expect(wrapper.text()).toContain('payroll.transport_delivery.late_discount_title')
    expect(wrapper.text()).toContain('Hlášení se podává po lhůtě (2026-08-20).')
    expect(wrapper.text()).toContain('payroll.transport_delivery.late_discount_where')
    const targets = wrapper.findAll('a').map(link => link.attributes('data-to'))
    expect(targets.some(target => target?.includes('"tab":"transport"'))).toBe(true)
    expect(targets.some(target => target?.includes('payroll-runs'))).toBe(true)
  })

  it('emits confirm and cancel', async () => {
    const wrapper = mountConfirm()

    await wrapper.find('[data-test="late-yes"]').trigger('click')
    const cancel = wrapper.findAll('button').find(button => button.text().includes('common.cancel'))
    await cancel!.trigger('click')

    expect(wrapper.emitted('confirm')).toHaveLength(1)
    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })
})
