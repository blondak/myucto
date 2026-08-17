import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import type { EnforcementCaseDetail, EnforcementCaseSummary } from '@/api/payrollEnforcement'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  casesPage: vi.fn(),
  detail: vi.fn(),
  peopleOptions: vi.fn(),
  institutionAccounts: vi.fn(),
  canRead: vi.fn(),
  canWrite: vi.fn(),
  error: vi.fn(),
}))

// Stránka čte předvýběr z adresy (odkaz z karty zaměstnance), takže potřebuje
// router. Originál se rozprostře, ať zůstanou i ostatní exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payrollEnforcement', () => ({
  pensionEvidenceValues: ['unknown', 'none', 'verified'],
  payrollEnforcementApi: {
    casesPage: m.casesPage,
    detail: m.detail,
    create: vi.fn(),
    addClaim: vi.fn(),
    updateEvidence: vi.fn(),
    transition: vi.fn(),
    monthEvidence: vi.fn(),
    saveMonthEvidence: vi.fn(),
    dependants: vi.fn(),
    addDependant: vi.fn(),
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peopleOptions: m.peopleOptions,
    institutionAccounts: m.institutionAccounts,
  },
}))

vi.mock('@/api/documents', () => ({
  documentsApi: { search: vi.fn() },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: m.canRead, canWrite: m.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.error, success: vi.fn(), warning: vi.fn() }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }),
}))

import EnforcementCases from '@/pages/payroll/EnforcementCases.vue'

function summary(overrides: Partial<EnforcementCaseSummary> = {}): EnforcementCaseSummary {
  return {
    id: 11,
    employee_id: 3,
    full_name: 'Syntetický Povinný',
    case_kind: 'enforcement',
    status: 'received',
    effective_from: '2026-05-01',
    effective_to: null,
    evidence_complete: false,
    recipient_verified: false,
    row_version: 1,
    claim_count: 1,
    outstanding_minor_units: 250_000,
    created_at: '2026-05-01 08:00:00',
    updated_at: '2026-05-01 08:00:00',
    ...overrides,
  }
}

function page(cases: EnforcementCaseSummary[], total = cases.length, offset = 0) {
  return { cases, total, limit: 20, offset }
}

function detailOf(item: EnforcementCaseSummary): EnforcementCaseDetail {
  return {
    ...item,
    recipient_institution_id: null,
    claims: [],
    events: [],
    ledger: [],
    settlement: {
      claims: [],
      withheld_minor: 0,
      held_minor: 0,
      liability_minor: 0,
      settled_minor: 0,
      outstanding_minor: 0,
      remaining_minor: 0,
    },
  }
}

function mountPage() {
  return mount(EnforcementCases, { global: { stubs: { RouterLink: true } } })
}

describe('EnforcementCases', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canRead.mockReturnValue(true)
    m.canWrite.mockReturnValue(true)
    m.casesPage.mockResolvedValue(page([summary()]))
    m.detail.mockImplementation(async () => detailOf(summary()))
    m.peopleOptions.mockResolvedValue([{ id: 3, full_name: 'Syntetický Povinný' }])
    m.institutionAccounts.mockResolvedValue([])
  })

  /*
   * Server strop drží tvrdě. Kdyby si stránka řekla o „všechno", dostala by
   * prvních padesát případů a o zbytku by mlčela — firma se šedesáti exekucemi
   * by se o deseti z nich nedozvěděla.
   */
  it('asks the server for one bounded page instead of everything', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(m.casesPage).toHaveBeenCalledTimes(1)
    expect(m.casesPage.mock.calls[0][0]).toEqual({ limit: 20, offset: 0 })
    wrapper.unmount()
  })

  it('pages through the list and re-asks the server with the new offset', async () => {
    m.casesPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mountPage()
    await flushPromises()

    const pager = wrapper.findComponent({ name: 'PaginationBar' })
    expect(pager.exists()).toBe(true)
    expect(pager.props('total')).toBe(45)

    pager.vm.$emit('update:page', 2)
    await flushPromises()

    expect(m.casesPage).toHaveBeenCalledTimes(2)
    expect(m.casesPage.mock.calls[1][0]).toEqual({ limit: 20, offset: 20 })
    wrapper.unmount()
  })

  it('hides the pager when a single page holds everything', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="enforcement-pagination"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('returns to the first page when the status filter narrows the list', async () => {
    m.casesPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mountPage()
    await flushPromises()

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 2)
    await flushPromises()
    expect(m.casesPage.mock.calls[1][0]).toEqual({ limit: 20, offset: 20 })

    await wrapper.get('[data-test="enforcement-status-filter"]').setValue('paid')
    await flushPromises()

    expect(m.casesPage.mock.calls[2][0]).toEqual({ status: 'paid', limit: 20, offset: 0 })
    wrapper.unmount()
  })

  /*
   * Rozbalený detail patří k řádku seznamu. Na druhé stránce ten řádek není,
   * takže by panel visel u případu, který na obrazovce nikde není.
   */
  it('collapses the expanded case when the user leaves its page', async () => {
    m.casesPage.mockResolvedValue(page(
      Array.from({ length: 20 }, (_, index) => summary({ id: index + 1 })),
      45,
    ))

    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="enforcement-detail-1"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="enforcement-detail-panel"]').exists()).toBe(true)

    wrapper.findComponent({ name: 'PaginationBar' }).vm.$emit('update:page', 2)
    await flushPromises()

    expect(wrapper.find('[data-test="enforcement-detail-panel"]').exists()).toBe(false)
    wrapper.unmount()
  })

  /*
   * Prázdný seznam a nenačtený seznam vedou uživatele k opačnému jednání
   * (založ případ vs. zkus to znovu), takže je nesmí kreslit stejně.
   */
  it('offers a retry instead of an empty state when the page fails to load', async () => {
    m.casesPage.mockRejectedValue(new Error('network'))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('payroll.enforcement.empty_title')
    expect(m.error).toHaveBeenCalledWith('payroll.enforcement.load_failed')
    wrapper.unmount()
  })

  // Lidé jsou doplněk formuláře, ne podmínka výpisu — jejich výpadek nesmí
  // potopit stránkovaný seznam, jen se o něm musí vědět.
  it('keeps the paged list when only the people lookup fails', async () => {
    m.peopleOptions.mockRejectedValue(new Error('network'))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Syntetický Povinný')

    await wrapper.get('[aria-expanded]').trigger('click')
    expect(wrapper.find('[data-test="support-failed"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
