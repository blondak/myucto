import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type {
  PayrollRetentionCategory,
  PayrollRetentionAssessmentItem,
} from '@/api/payrollRetention'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  assessment: vi.fn(),
}))

vi.mock('@/api/payrollRetention', () => ({
  payrollRetentionApi: { overview: m.overview, assessment: m.assessment },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: (permission: string) => permission === 'payroll',
    canWrite: () => false,
  }),
}))

// `useTablePrefs` táhne @/i18n, které volá skutečné `createI18n` — továrna
// proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => {
  const { ref } = await import('vue')
  return {
    ...(await importOriginal<typeof import('vue-i18n')>()),
    useI18n: () => ({
      t: (key: string, params?: Record<string, unknown>) =>
        params ? `${key}:${JSON.stringify(params)}` : key,
      locale: ref('cs-CZ'),
    }),
  }
})

// Preference tabulek jdou přes Pinii a API; v testu stačí prázdné výchozí.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollRetention from '@/pages/payroll/PayrollRetention.vue'

function category(overrides: Partial<PayrollRetentionCategory> = {}): PayrollRetentionCategory {
  return {
    category: 'payroll_sheet',
    label: 'Mzdové listy',
    retention_years: 45,
    basis: 'calendar_years_after_record_year',
    alternative_basis: null,
    origin: 'statute',
    statutory: true,
    act: 'zákon č. 582/1991 Sb.',
    section: '§ 35a odst. 4 písm. c) zákona č. 582/1991 Sb.',
    amendment: null,
    source: '§ 35a odst. 4 písm. c) zákona č. 582/1991 Sb.',
    source_status: 'statute_verified',
    verified_on: '2026-08-15',
    accounting_relevant: true,
    closing_agenda: false,
    note: 'Nejdelší lhůta v celé agendě.',
    employee_tables: ['payroll_monthly_records'],
    employment_tables: [],
    effective_years: 45,
    determined: true,
    ...overrides,
  }
}

const HOUSE_POLICY = category({
  category: 'health_insurance',
  label: 'Záznamy pro zdravotní pojištění',
  retention_years: 10,
  origin: 'house_policy',
  statutory: false,
  section: null,
  source: 'dodaná politika aplikace (v předpisu zákon č. 592/1992 Sb. uschovávací lhůta není)',
  source_status: 'statute_silent',
  effective_years: 10,
  employee_tables: ['payroll_person_health_coverage_history'],
})

const NO_PERIOD = category({
  category: 'garnishment',
  label: 'Doklady k exekučním srážkám',
  retention_years: null,
  origin: 'none',
  statutory: false,
  section: null,
  source: 'zákon č. 99/1963 Sb., občanský soudní řád, a zákon č. 120/2001 Sb., exekuční řád',
  source_status: 'statute_silent',
  effective_years: null,
  determined: false,
  employee_tables: ['payroll_enforcement_cases'],
})

function assessed(overrides: Partial<PayrollRetentionAssessmentItem> = {}): PayrollRetentionAssessmentItem {
  return {
    employee_id: 1,
    last_record_year: 2020,
    governing_category: 'payroll_sheet',
    governing_source: '§ 35a odst. 4 písm. c) zákona č. 582/1991 Sb.',
    governing_source_status: 'statute_verified',
    retained_until: '2065-12-31',
    expired: false,
    action: null,
    proposable: false,
    blocked_by: 'within_retention',
    ...overrides,
  }
}

function mountPage() {
  return mount(PayrollRetention, {
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })
}

describe('PayrollRetention', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.overview.mockResolvedValue({
      categories: [category(), HOUSE_POLICY, NO_PERIOD],
      policies: [],
    })
    m.assessment.mockResolvedValue({ as_of: '2026-08-17', items: [assessed()], proposable: 0 })
  })

  it('ukáže lhůtu, den ověření i konkrétní ustanovení, ne jen číslo zákona', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-test="retention-years-payroll_sheet"]').text())
      .toContain('payroll.retention.years_count:{"years":45}')
    expect(wrapper.get('[data-test="retention-source-payroll_sheet"]').text())
      .toContain('§ 35a odst. 4 písm. c)')
    expect(wrapper.text()).toContain('payroll.retention.verified_stamp')
  })

  it('odliší dodanou politiku od zákona už v přehledu, ne až v detailu řádku', async () => {
    const wrapper = mountPage()
    await flushPromises()

    // Dlaždice nad tabulkou počítají kategorie podle původu.
    expect(wrapper.get('[data-test="origin-tile-statute"]').text()).toContain('1')
    expect(wrapper.get('[data-test="origin-tile-house_policy"]').text()).toContain('1')
    expect(wrapper.get('[data-test="origin-tile-none"]').text()).toContain('1')

    // A původ je i sloupcem tabulky — u zdravotního pojištění „dodaná politika".
    expect(wrapper.get('[data-test="retention-origin-health_insurance"]').text())
      .toContain('payroll.retention.origin.house_policy')
    expect(wrapper.get('[data-test="retention-origin-garnishment"]').text())
      .toContain('payroll.retention.origin.none')
    expect(wrapper.get('[data-test="retention-source-health_insurance"]').text())
      .toContain('dodaná politika aplikace')
  })

  it('neurčená lhůta se nevydává za nulu a hlásí, že se osoba nikdy nenavrhne', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-test="retention-years-garnishment"]').text())
      .toContain('payroll.retention.years_undetermined')
    expect(wrapper.get('[data-test="retention-erasure-garnishment"]').text())
      .toContain('payroll.retention.erasure_never')
    expect(wrapper.get('[data-test="retention-erasure-payroll_sheet"]').text())
      .toContain('payroll.retention.erasure_proposed')
  })

  it('filtr běží na klientovi nad celým katalogem a nestránkuje se', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="origin-tile-house_policy"]').trigger('click')
    expect(wrapper.find('[data-test="retention-row-health_insurance"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="retention-row-payroll_sheet"]').exists()).toBe(false)

    // Zúžení výběru nesmí sáhnout na server — jinak by se půlka dat filtrovala
    // jinde než druhá a řádky by mizely bez vysvětlení.
    expect(m.overview).toHaveBeenCalledTimes(1)
  })

  it('prázdný výsledek filtru se nehlásí jako prázdný katalog', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('#retention-q').setValue('naprosto neexistující kategorie')
    expect(wrapper.text()).toContain('payroll.retention.no_match')
    expect(wrapper.text()).not.toContain('payroll.retention.load_failed')
  })

  it('selhání načtení se NIKDY nezobrazí jako prázdný katalog', async () => {
    m.overview.mockRejectedValue(new Error('boom'))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.retention.load_failed')
    expect(wrapper.text()).not.toContain('payroll.retention.no_match')
  })

  it('u výmazu vypíše i důvod, proč se osoba nenavrhla', async () => {
    m.assessment.mockResolvedValue({
      as_of: '2026-08-17',
      items: [
        assessed(),
        assessed({ employee_id: 2, blocked_by: 'legal_hold' }),
        assessed({ employee_id: 3, blocked_by: 'undetermined_retention' }),
        assessed({ employee_id: 4, blocked_by: null, proposable: true, expired: true, action: 'anonymize' }),
      ],
      proposable: 1,
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-test="retention-block-count-within_retention"]').text()).toBe('1')
    expect(wrapper.get('[data-test="retention-block-count-legal_hold"]').text()).toBe('1')
    expect(wrapper.get('[data-test="retention-block-count-undetermined_retention"]').text()).toBe('1')
    expect(wrapper.text()).toContain('payroll.retention.proposable_of:{"total":4}')
  })

  it('posudek se přepočítá k zadanému dni, katalog se nenačítá znovu', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(m.assessment).toHaveBeenCalledTimes(1)

    const input = wrapper.get('input[type="date"]')
    await input.setValue('2030-01-01')
    await input.trigger('change')
    await flushPromises()

    expect(m.assessment).toHaveBeenLastCalledWith('2030-01-01')
    expect(m.overview).toHaveBeenCalledTimes(1)
  })
})
