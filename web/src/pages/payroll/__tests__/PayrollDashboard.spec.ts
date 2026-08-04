import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  capabilities: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    capabilities: m.capabilities,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: () => true,
  }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    error: vi.fn(),
    success: vi.fn(),
    warning: vi.fn(),
  }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
  }),
}))

import PayrollDashboard from '@/pages/payroll/PayrollDashboard.vue'

describe('PayrollDashboard monthly workspace', () => {
  beforeEach(() => {
    m.capabilities.mockResolvedValue({
      state: {
        supplier_id: 1,
        status: 'active',
        start_period: '2026-01',
        row_version: 1,
        activated_at: null,
        suspended_at: null,
        created_at: null,
        updated_at: null,
      },
      support_matrix: {
        version: '2026-08',
        supported_years: [2026],
        employment_types: [],
        features: [{
          key: 'monthly_payroll',
          status: 'supported',
          available: true,
          min_epic: 'MZ01',
        }],
      },
    })
  })

  it('puts frequent monthly tasks before collapsible diagnostics', async () => {
    const wrapper = mount(PayrollDashboard, {
      global: {
        stubs: {
          RouterLink: {
            props: ['to'],
            template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
          },
        },
      },
    })
    await flushPromises()

    const workspace = wrapper.get('[data-test="monthly-workspace"]')
    const destinations = workspace.findAll('a').map(link => link.attributes('data-to'))

    expect(destinations).toContain('{"name":"payroll-quick-inputs"}')
    expect(destinations).toContain('{"name":"payroll-runs"}')
    expect(destinations).toContain('{"name":"payroll-people"}')
    expect(destinations).toContain('{"name":"payroll-payments"}')
    expect(destinations).toContain('{"name":"payroll-documents"}')
    expect(wrapper.get('[data-test="support-diagnostics"]').element.tagName).toBe('DETAILS')
    expect(wrapper.get('[data-test="support-diagnostics"]').attributes('open')).toBeUndefined()
  })
})
