import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  submissionOverview: vi.fn(),
  runs: vi.fn(),
  jmhzPvpojPreview: vi.fn(),
  healthPaymentOverviews: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    submissionOverview: m.submissionOverview,
    runs: m.runs,
    jmhzPvpojPreview: m.jmhzPvpojPreview,
    healthPaymentOverviews: m.healthPaymentOverviews,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))
// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollSubmissionOverviewPanel from '@/pages/payroll/PayrollSubmissionOverviewPanel.vue'

/**
 * Regrese: období se odvozovalo přes `new Date().toISOString().slice(0, 7)`.
 * `toISOString()` je UTC, takže v pásmu s kladným posunem (CET/CEST) vracelo
 * mezi půlnocí a ránem prvního dne v měsíci ještě měsíc PŘEDCHOZÍ — účetní
 * ráno prvního otevřela podání a viděla období, které už uzavřela.
 */
describe('PayrollSubmissionOverviewPanel — odvození období', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.submissionOverview.mockResolvedValue({
      items: [],
      total: 0,
      deadline_summary: {
        not_open: 0,
        open: 0,
        due_soon: 0,
        due_today: 0,
        overdue: 0,
        awaiting_result: 0,
        fulfilled: 0,
        action_required: 0,
        cancelled: 0,
      },
    })
    m.runs.mockResolvedValue([])
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('vrátí ve 00:30 prvního dne v měsíci aktuální měsíc, ne předchozí', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 7, 1, 0, 30))

    const wrapper = mount(PayrollSubmissionOverviewPanel, {
      props: { mode: 'jmhz' },
      // Podřízené panely mají vlastní testy; tady jen zavazí.
      global: { stubs: { PayrollJmhzOrdinaryEvidencePanel: true, PayrollJmhzXmlDryRunPanel: true } },
    })
    await flushPromises()

    const period = wrapper.get('[data-test="submission-overview-period"]')
      .element as HTMLInputElement
    expect(period.value).toBe('2026-08')
    expect(period.value).not.toBe('2026-07')
  })

  it('drží místní datum i o půlnoci na Nový rok', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2027, 0, 1, 0, 5))

    const wrapper = mount(PayrollSubmissionOverviewPanel, {
      props: { mode: 'jmhz' },
      // Podřízené panely mají vlastní testy; tady jen zavazí.
      global: { stubs: { PayrollJmhzOrdinaryEvidencePanel: true, PayrollJmhzXmlDryRunPanel: true } },
    })
    await flushPromises()

    const period = wrapper.get('[data-test="submission-overview-period"]')
      .element as HTMLInputElement
    expect(period.value).toBe('2027-01')
  })
})
