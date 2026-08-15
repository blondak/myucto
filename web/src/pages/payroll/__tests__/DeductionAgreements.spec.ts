import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import type { DeductionAgreementDetail, DeductionAgreementSummary } from '@/api/payrollDeductions'

const m = vi.hoisted(() => ({
  agreementsPage: vi.fn(),
  agreement: vi.fn(),
  peopleOptions: vi.fn(),
  canWrite: vi.fn(),
}))

vi.mock('@/api/payrollDeductions', () => ({
  deductionAgreementKinds: ['advance', 'meal', 'contribution', 'damage', 'other'],
  deductionPriorityFloor: 10,
  deductionPriorityCeiling: 9999,
  payrollDeductionsApi: {
    agreementsPage: m.agreementsPage,
    agreement: m.agreement,
    create: vi.fn(),
    update: vi.fn(),
    transition: vi.fn(),
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { peopleOptions: m.peopleOptions },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }),
}))

import DeductionAgreements from '@/pages/payroll/DeductionAgreements.vue'

function summary(overrides: Partial<DeductionAgreementSummary> = {}): DeductionAgreementSummary {
  return {
    id: 21,
    employee_id: 3,
    full_name: 'Syntetická Srážková',
    agreement_reference: 'SRZ-1',
    title: 'Stravenky',
    deduction_kind: 'meal',
    status: 'active',
    priority_no: 100,
    requested_minor: 50_000,
    basis_points: null,
    basis_amount_minor: null,
    total_limit_minor: null,
    withheld_total_minor: 0,
    remaining_limit_minor: null,
    valid_from: '2026-01-01',
    valid_to: null,
    recipient_reference: null,
    note: null,
    row_version: 1,
    version_no: 1,
    enters_payroll_run: true,
    created_at: '2026-01-01 08:00:00',
    updated_at: '2026-01-01 08:00:00',
    ...overrides,
  }
}

function page(agreements: DeductionAgreementSummary[], total = agreements.length, offset = 0) {
  return { agreements, total, limit: 20, offset }
}

function detailOf(item: DeductionAgreementSummary): DeductionAgreementDetail {
  return { ...item, versions: [], ledger: [] }
}

describe('DeductionAgreements', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.agreementsPage.mockResolvedValue(page([summary()]))
    m.agreement.mockImplementation(async () => detailOf(summary()))
    m.peopleOptions.mockResolvedValue([{ id: 3, full_name: 'Syntetická Srážková' }])
  })

  /*
   * Server strop drží tvrdě. Kdyby si stránka řekla o „všechno", dostala by
   * prvních padesát dohod a o zbytku by mlčela — zaměstnanci z šesté desítky by
   * srážka podle výpisu vůbec nevznikla.
   */
  it('asks the server for one bounded page instead of everything', async () => {
    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    expect(m.agreementsPage).toHaveBeenCalledTimes(1)
    expect(m.agreementsPage.mock.calls[0][0]).toEqual({ limit: 20, offset: 0 })
    wrapper.unmount()
  })

  it('pages through the list and re-asks the server with the new offset', async () => {
    m.agreementsPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    const pager = wrapper.findComponent({ name: 'PaginationBar' })
    expect(pager.exists()).toBe(true)
    expect(pager.props('total')).toBe(45)

    pager.vm.$emit('update:page', 2)
    await flushPromises()

    expect(m.agreementsPage).toHaveBeenCalledTimes(2)
    expect(m.agreementsPage.mock.calls[1][0]).toEqual({ limit: 20, offset: 20 })
    wrapper.unmount()
  })

  it('hides the pager when a single page holds everything', async () => {
    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    expect(wrapper.find('[data-test="deduction-pagination"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('returns to the first page when the status filter narrows the list', async () => {
    m.agreementsPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 2)
    await flushPromises()
    expect(m.agreementsPage.mock.calls[1][0]).toEqual({ limit: 20, offset: 20 })

    await wrapper.get('[data-test="deduction-status-filter"]').setValue('paused')
    await flushPromises()

    expect(m.agreementsPage.mock.calls[2][0]).toEqual({ status: 'paused', limit: 20, offset: 0 })
    wrapper.unmount()
  })

  /*
   * Rozbalený detail patří k řádku seznamu. Na druhé stránce ten řádek není,
   * takže by panel visel u dohody, kterou na obrazovce nikdo nevidí.
   */
  it('collapses the expanded agreement when the user leaves its page', async () => {
    m.agreementsPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))
    m.agreement.mockImplementation(async (id: number) => detailOf(summary({ id })))

    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    await wrapper.get('[data-test="deduction-detail-1"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="deduction-detail-panel"]').exists()).toBe(true)

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 2)
    await flushPromises()

    expect(wrapper.find('[data-test="deduction-detail-panel"]').exists()).toBe(false)
    wrapper.unmount()
  })

  // Chyba načtení musí zůstat na obrazovce; toast by zmizel a uživatel by
  // prázdný výpis četl jako „žádné srážky nejsou".
  it('keeps the server message visible when the page fails to load', async () => {
    m.agreementsPage.mockRejectedValue({
      response: { data: { error: { message: 'Seznam se nepodařilo načíst.' } } },
    })

    const wrapper = mount(DeductionAgreements)
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Seznam se nepodařilo načíst.')
    wrapper.unmount()
  })
})
