import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { PayrollTakeoverOverview } from '@/api/payrollTakeoverRuns'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  build: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payrollTakeoverRuns', () => ({
  payrollTakeoverRunsApi: {
    overview: m.overview,
    build: m.build,
    detail: vi.fn(),
    discard: vi.fn(),
  },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs-CZ') }),
}))

import PayrollTakeoverRunsPanel from '@/pages/payroll/PayrollTakeoverRunsPanel.vue'

function overview(built: string[]): PayrollTakeoverOverview {
  return {
    year: 2026,
    payroll_start_period: '2026-09',
    sources: ['pamica'],
    periods: ['2026-06', '2026-07', '2026-08'].map(period => ({
      period,
      historical: true,
      presence: 'takeover_only',
      has_takeover_run: built.includes(period),
      run_id: built.includes(period) ? 1 : null,
      row_count: 200,
    })),
  }
}

describe('PayrollTakeoverRunsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('převezme všechny zpracované měsíce jedním tlačítkem, po jednom v pořadí', async () => {
    const built: string[] = []
    m.overview.mockImplementation(async () => overview(built))
    m.build.mockImplementation(async (period: string) => {
      built.push(period)
      return {}
    })

    const wrapper = mount(PayrollTakeoverRunsPanel, { props: { year: 2026, canWrite: true } })
    await flushPromises()
    await wrapper.get('[data-testid="payroll-takeover-build-all"]').trigger('click')
    await flushPromises()

    expect(m.build.mock.calls.map(call => call[0])).toEqual(['2026-06', '2026-07', '2026-08'])
    expect(m.success).toHaveBeenCalledWith('payroll.runs.takeover.built_all')
    expect(wrapper.find('[data-testid="payroll-takeover-build-all"]').exists()).toBe(false)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('zastaví se u prvního neúspěšného měsíce a řekne u kterého', async () => {
    m.overview.mockImplementation(async () => overview([]))
    m.build.mockImplementation(async (period: string) => {
      if (period === '2026-07') throw new Error('boom')
      return {}
    })

    const wrapper = mount(PayrollTakeoverRunsPanel, { props: { year: 2026, canWrite: true } })
    await flushPromises()
    await wrapper.get('[data-testid="payroll-takeover-build-all"]').trigger('click')
    await flushPromises()

    expect(m.build).toHaveBeenCalledTimes(2)
    expect(m.error).toHaveBeenCalledWith('payroll.runs.takeover.build_all_failed')
  })
})
