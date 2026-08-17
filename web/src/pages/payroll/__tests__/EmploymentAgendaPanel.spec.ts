import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { PayrollAgendaSummaryItem, PayrollEmploymentAgendaSummary } from '@/api/payroll'

const m = vi.hoisted(() => ({
  employmentAgendaSummary: vi.fn(),
  canRead: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { employmentAgendaSummary: m.employmentAgendaSummary },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: m.canRead, canWrite: () => true }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: ref('cs-CZ'),
  }),
}))

import EmploymentAgendaPanel from '@/pages/payroll/EmploymentAgendaPanel.vue'
import { payrollAgendas } from '@/pages/payroll/payrollAgendaLinks'

function agenda(overrides: Partial<PayrollAgendaSummaryItem> = {}): PayrollAgendaSummaryItem {
  return {
    key: 'time',
    count: 0,
    last_on: null,
    amount_minor: null,
    ...overrides,
  }
}

function summary(agendas: PayrollAgendaSummaryItem[]): PayrollEmploymentAgendaSummary {
  return { employment_id: 12, employee_id: 5, agendas }
}

function mountPanel() {
  return mount(EmploymentAgendaPanel, {
    props: { employmentId: 12, employeeId: 5 },
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
        ActionBar: {
          props: ['actions'],
          template: '<div data-test="action-bar" :data-keys="actions.map(a => a.key).join(\',\')" />',
        },
      },
    },
  })
}

describe('EmploymentAgendaPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canRead.mockReturnValue(true)
    m.employmentAgendaSummary.mockResolvedValue(summary(
      payrollAgendas.map(item => agenda({ key: item.key })),
    ))
  })

  it('nabídne tlačítko na každou agendu, i na tu prázdnou', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    const keys = wrapper.get('[data-test="action-bar"]').attributes('data-keys')
    for (const item of payrollAgendas) {
      expect(keys).toContain(`agenda-${item.key}`)
    }
  })

  it('vypíše jen agendy, ve kterých něco je; prázdné jmenuje jednou větou', async () => {
    m.employmentAgendaSummary.mockResolvedValue(summary([
      agenda({ key: 'absences', count: 3, last_on: '2026-08-14' }),
      agenda({ key: 'travel', count: 0 }),
      agenda({ key: 'enforcement', count: 0 }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="employment-agenda-absences"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="employment-agenda-travel"]').exists()).toBe(false)
    const empty = wrapper.get('[data-test="employment-agendas-empty"]').text()
    expect(empty).toContain('payroll.agendas.items.travel')
    expect(empty).toContain('payroll.agendas.items.enforcement')
    // Agenda, o které server nic neřekl (chybí oprávnění), se nesmí objevit ani
    // v seznamu, ani mezi prázdnými — jinak by karta tvrdila „zatím nic“.
    expect(empty).not.toContain('payroll.agendas.items.documents')
  })

  it('u agendy se záznamy ukáže počet, datum i částku', async () => {
    m.employmentAgendaSummary.mockResolvedValue(summary([
      agenda({ key: 'travel', count: 2, last_on: '2026-08-03', amount_minor: 123_400 }),
    ]))

    const wrapper = mountPanel()
    await flushPromises()

    const row = wrapper.get('[data-test="employment-agenda-travel"]').text()
    expect(row).toContain('payroll.agendas.count')
    expect(row).toContain('payroll.agendas.last_on')
    // Částka jde přes sdílené `formatMoneyMinor`, tedy s nezlomitelnými mezerami —
    // porovnává se proto normalizovaně, ne na přesný řetězec locale.
    expect(row.replace(/\s/gu, '')).toContain('1234,00Kč')
  })

  it('skryje tlačítko agendy, na kterou uživatel nemá oprávnění', async () => {
    m.canRead.mockImplementation((permission: string) => permission !== 'payroll.enforcement')
    const wrapper = mountPanel()
    await flushPromises()

    const keys = wrapper.get('[data-test="action-bar"]').attributes('data-keys')
    expect(keys).not.toContain('agenda-enforcement')
    expect(keys).toContain('agenda-absences')
  })

  it('výpadek souhrnu nesmí shodit kartu — tlačítka zůstanou', async () => {
    m.employmentAgendaSummary.mockRejectedValue(new Error('403'))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="employment-agendas-failed"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="action-bar"]').attributes('data-keys'))
      .toContain('agenda-time')
  })

  it('souhrn se načte jedním požadavkem, ne jedním na agendu', async () => {
    mountPanel()
    await flushPromises()

    expect(m.employmentAgendaSummary).toHaveBeenCalledTimes(1)
    expect(m.employmentAgendaSummary).toHaveBeenCalledWith(12)
  })
})
