import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { PayrollRun } from '@/api/payroll'

const m = vi.hoisted(() => ({
  runs: vi.fn(),
  runDetail: vi.fn(),
  peopleOptions: vi.fn(),
  deleteRun: vi.fn(),
  commandRun: vi.fn(),
  canWrite: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  total: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    // Seznam je nově stránkovaný a `result_snapshot` v něm nese jen `totals`.
    // Adaptér drží stávající testy beze změny: scénáře pořád nastavují prosté
    // pole běhů a obálku dopočítá tenhle wrapper.
    runsPage: (period?: string, page?: { limit?: number, offset?: number }) =>
      m.runs(period, page).then((runs: unknown[]) => ({
        runs,
        total: m.total() ?? runs.length,
        limit: page?.limit ?? 12,
        offset: page?.offset ?? 0,
      })),
    run: m.runDetail,
    peopleOptions: m.peopleOptions,
    deleteRun: m.deleteRun,
    commandRun: m.commandRun,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs-CZ') }),
}))

import PayrollRuns from '@/pages/payroll/PayrollRuns.vue'

function run(overrides: Partial<PayrollRun> = {}): PayrollRun {
  return {
    id: 15,
    supplier_id: 4,
    office_id: null,
    period_start: '2026-08-01',
    payment_date: '2026-09-15',
    status: 'cancelled',
    current_revision_no: 0,
    row_version: 2,
    revision_id: null,
    revision_no: null,
    revision_status: null,
    payment_materialization_supported: false,
    can_delete: true,
    result_snapshot: null,
    available_commands: [],
    validations: [],
    ...overrides,
  }
}

describe('PayrollRuns', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.runs.mockResolvedValue([run()])
    m.total.mockReturnValue(undefined)
    m.runDetail.mockResolvedValue(run())
    m.peopleOptions.mockResolvedValue([])
    m.deleteRun.mockResolvedValue(undefined)
    m.commandRun.mockResolvedValue({ outcome: null })
  })

  /*
   * Prázdný seznam běhů a nenačtený seznam běhů vedou uživatele k opačnému
   * jednání (založ běh vs. zkus to znovu), takže je nesmí kreslit stejně.
   */
  it('offers a retry instead of an empty state when the runs fail to load', async () => {
    m.runs.mockRejectedValue(new Error('network'))

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll.runs.load_failed_hint')
    expect(wrapper.text()).not.toContain('payroll.runs.empty_hint')

    m.runs.mockResolvedValue([])
    await wrapper.get('[data-test="load-failed"] [data-test="empty-state-cta"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
  })

  it('shows the empty state when the period genuinely has no run', async () => {
    m.runs.mockResolvedValue([])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.runs.empty_hint')
    expect(wrapper.text()).not.toContain('payroll.runs.load_failed_hint')
  })

  it('names the missing field when a run cannot be created', async () => {
    const wrapper = mount(PayrollRuns)
    await flushPromises()

    // Uživatel datum výplaty vymaže — tlačítko zšedne a musí říct proč.
    await wrapper.get('input[type="date"]').setValue('')

    const button = wrapper.get('[data-test="run-create"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('title')).toBe('payroll.runs.create_blocked_payment_date')
    expect(wrapper.get('[data-test="run-create-blocked"]').text())
      .toBe('payroll.runs.create_blocked_payment_date')
  })

  it.each([
    ['approved', 'post'],
    ['posted', 'prepare_payments'],
    ['payment_ready', 'mark_paid'],
    ['paid', 'close'],
  ] as const)(
    'nabízí ve stavu %s jedinou plnou akci %s',
    async (status, primary) => {
      m.runs.mockResolvedValue([run({
        status,
        can_delete: false,
        available_commands: [primary, 'request_correction'],
      })])

      const wrapper = mount(PayrollRuns)
      await flushPromises()

      const primaryButton = wrapper.get(`[data-testid="payroll-run-15-${primary}"]`)
      const secondary = wrapper.get('[data-testid="payroll-run-15-request_correction"]')
      expect(primaryButton.classes().join(' ')).toContain('bg-')
      expect(secondary.classes().join(' ')).toContain('border')
      expect(secondary.classes().join(' ')).not.toContain('bg-primary-600')
    },
  )

  it('drží blokující důvod plateb u běhu místo mizejícího toastu', async () => {
    m.runs.mockResolvedValue([run({
      status: 'posted',
      can_delete: false,
      available_commands: ['prepare_payments'],
    })])
    m.commandRun.mockRejectedValue({
      response: {
        status: 422,
        data: {
          error: {
            message: 'Platby nelze připravit: Jan Syntetický nemá nastavené výplatní pravidlo.',
          },
        },
      },
    })

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-prepare_payments"]').trigger('click')
    await flushPromises()

    expect(m.error).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="payroll-run-15-blocker"]').text())
      .toContain('nemá nastavené výplatní pravidlo')
  })

  it('řekne nahlas, že se u daňové evidence nic nezaúčtovalo', async () => {
    m.runs.mockResolvedValue([run({
      status: 'approved',
      can_delete: false,
      available_commands: ['post'],
    })])
    m.commandRun.mockResolvedValue({
      outcome: { outcome: 'posting_not_applicable', details: { reason: 'tax_evidence' } },
    })

    const wrapper = mount(PayrollRuns)
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-15-post"]').trigger('click')
    await flushPromises()

    expect(m.success).toHaveBeenCalledWith(
      'payroll.runs.outcome.posting_not_applicable',
    )
  })

  it('offers destructive deletion only for a run explicitly marked empty by API', async () => {
    const wrapper = mount(PayrollRuns)
    await flushPromises()

    await wrapper.get('[data-testid="delete-payroll-run-15"]').trigger('click')
    expect(m.deleteRun).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('payroll.runs.delete_confirm')
    const confirm = document.body.querySelector<HTMLButtonElement>('[data-test="confirm-delete-run"]')
    expect(confirm).not.toBeNull()
    confirm?.click()
    await flushPromises()

    expect(m.deleteRun).toHaveBeenCalledWith(15, 2)
    expect(m.success).toHaveBeenCalledWith('payroll.runs.deleted')
    expect(m.runs).toHaveBeenCalledTimes(2)
  })

  it('does not expose deletion when the API found any retained evidence', async () => {
    m.runs.mockResolvedValue([run({ can_delete: false })])

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(wrapper.find('[data-testid="delete-payroll-run-15"]').exists()).toBe(false)
    expect(m.deleteRun).not.toHaveBeenCalled()
  })

  // Seznam běhů posílal celý výsledkový snapshot každého běhu včetně osobního
  // rozpadu — u firmy se stovkou zaměstnanců to server nedokázal ani načíst.
  // Rozpad se proto dotahuje až na vyžádání, pro jeden konkrétní běh.
  it('loads the per-employee breakdown only when the user asks for it', async () => {
    m.runs.mockResolvedValue([run({
      revision_id: 9,
      revision_status: 'approved',
      result_snapshot: { totals: { cash_payable_minor: 100_000 } },
    })])
    m.runDetail.mockResolvedValue(run({
      result_snapshot: { totals: { cash_payable_minor: 100_000 }, people: [] },
    }))

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    expect(m.runDetail).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="payroll-run-15-breakdown-toggle"]').trigger('click')
    await flushPromises()

    expect(m.runDetail).toHaveBeenCalledWith(15)
  })

  it('paginates instead of loading every run the company ever had', async () => {
    m.total.mockReturnValue(40)

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    // Období je aktuální měsíc — na jeho hodnotě testu nezáleží, jde o strop.
    expect(m.runs).toHaveBeenCalledWith(expect.any(String), { limit: 12, offset: 0 })
    expect(wrapper.find('[data-testid="payroll-runs-pagination"]').exists()).toBe(true)
  })
})
