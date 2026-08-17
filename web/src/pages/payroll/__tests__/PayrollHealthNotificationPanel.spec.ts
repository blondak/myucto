import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  capability: vi.fn(),
  duties: vi.fn(),
  prepare: vi.fn(),
  runs: vi.fn(),
  submissionDetail: vi.fn(),
  downloadSubmissionArtifact: vi.fn(),
}))

vi.mock('@/api/payrollHealthNotifications', () => ({
  payrollHealthNotificationApi: {
    capability: m.capability,
    duties: m.duties,
    preparePaymentOverview: m.prepare,
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    runs: m.runs,
    submissionDetail: m.submissionDetail,
    downloadSubmissionArtifact: m.downloadSubmissionArtifact,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (permission: string) => permission === 'payroll.submissions',
  }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters
        ? `${key} ${Object.values(parameters).join(' ')}`
        : key,
    locale: { value: 'cs' },
  }),
}))

// Preference tabulek jdou přes Pinii a API; v testu stačí prázdné výchozí.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollHealthNotificationPanel
  from '@/pages/payroll/PayrollHealthNotificationPanel.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { localPayrollPeriod } from '@/pages/payroll/payrollComponentsUi'

/**
 * Vybere hodnotu v `SearchableSelect` přes jeho model. Rozbalovací seznam se
 * tu neklikáme — testuje se panel, ne komponenta výběru, která má vlastní spec.
 */
function pick(
  wrapper: ReturnType<typeof mount>,
  testId: string,
  value: unknown,
): void {
  const target = wrapper.findAllComponents(SearchableSelect)
    .find(component => component.attributes('data-test') === testId) as
      { vm: { $emit: (event: string, payload: unknown) => void } } | undefined
  if (!target) {
    throw new Error(`SearchableSelect [data-test="${testId}"] nenalezen.`)
  }
  target.vm.$emit('update:modelValue', value)
}

const CHANNEL_111 = {
  insurer_code: '111',
  insurer_name: 'VZP ČR',
  kind: 'own_portal',
  data_box_id: 'i48ae3q',
  portal_url: null,
  accepts_shared_data_message: false,
  automated_dispatch_documented: false,
  undocumented_reason_code: 'zp_shared_data_message_acceptance_unconfirmed',
  note: 'Přijetí jednotné datové věty se nepodařilo doložit.',
}

const RULE = {
  kind: 'employment_start',
  label: 'Nástup zaměstnance do zaměstnání',
  employer_reports: true,
  effective_from: '1997-04-01',
  effective_to: null,
  act: 'zákon č. 48/1997 Sb.',
  section: '§ 10 zákona č. 48/1997 Sb.',
  source: '§ 10 zákona č. 48/1997 Sb.',
  source_status: 'statute_verified',
  verified_on: '2026-08-15',
  note: '',
}

function dutyItem(overrides: Record<string, unknown> = {}) {
  return {
    id: 'payroll_health_notification:9:employment_start:2026-06-03',
    employment_id: 9,
    employee_id: 4,
    full_name: 'Syntetická osoba',
    kind: 'employment_start',
    label: RULE.label,
    insurer_code: '111',
    occurred_on: '2026-06-03',
    reported_by_employer: true,
    rule: RULE,
    deadline: {
      earliest_submission_on: '2026-06-03',
      due_on: '2026-06-11',
      calendar_basis: 'calendar_days',
      ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
      ruleset_hash: 'a'.repeat(64),
      source: '§ 10 zákona č. 48/1997 Sb.',
      source_status: 'statute_verified',
    },
    change_code: { documented: true, code: 'P', reason: null },
    channel: CHANNEL_111,
    dispatch: {
      supported: false,
      reason_code: 'zp_shared_data_message_acceptance_unconfirmed',
      reason: 'Automatické odeslání pojišťovně 111 není doložené.',
      channel: CHANNEL_111,
    },
    ...overrides,
  }
}

function dutyPage(overrides: Record<string, unknown> = {}) {
  return {
    period: '2026-06',
    environment: 'production',
    items: [dutyItem()],
    total: 1,
    limit: 50,
    offset: 0,
    summary: {
      total: 1,
      reported_by_employer: 1,
      reported_by_insured: 0,
      code_documented: 1,
      code_undocumented: 0,
      overdue: 0,
    },
    unresolved_employments: [],
    ...overrides,
  }
}

function setup() {
  m.capability.mockResolvedValue({
    schema_reference: 'payroll-health-submission-capability.v1',
    shared_data_message_since: '2026-01-01',
    documents: {},
    channels: { 111: CHANNEL_111 },
    automated_dispatch: {
      supported: false,
      reason_code: 'zp_transport_envelope_undocumented',
    },
    change_codes: {
      total: 25,
      narrowing_effective_from: '2026-01-01',
      mapping_from_duty_documented: [
        'employment_start',
        'employment_end',
        'maternity_leave_start',
        'parental_leave_start',
        'maternity_or_parental_leave_end',
      ],
    },
    duties: [RULE],
    verification_reference: 'private/Mzdy/21-ZP-PODANI-RESERSE.md',
  })
  m.duties.mockResolvedValue(dutyPage())
  m.runs.mockResolvedValue([{
    id: 3,
    period_start: '2026-06-01',
    revision_id: 12,
    revision_no: 1,
    revision_status: 'approved',
  }])
}

describe('PayrollHealthNotificationPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setup()
  })

  it('vypíše povinnost i s lhůtou a doloženým kódem změny', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    const rows = wrapper.findAll('[data-test="health-notification-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('Syntetická osoba')
    expect(rows[0].text()).toContain('P')
  })

  /**
   * Jádro zadání: omezení musí být vidět DŘÍV, než na ně uživatel narazí.
   * Panel s omezeními se proto vykresluje vždy, ne až po chybě.
   */
  it('řekne, co modul neumí, ještě než se na cokoli klikne', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    const limits = wrapper.find('[data-test="health-notifications-limits"]')
    expect(limits.exists()).toBe(true)
    expect(limits.text()).toContain('payroll.health_notifications.limits.no_transport')
    expect(limits.text()).toContain('payroll.health_notifications.limits.manual_delivery')
    // Tři nedoložené druhy povinnosti se vyjmenují, ne shrnou do „některé".
    expect(limits.text()).toContain('payroll.health_notifications.kind.insurer_change')
    expect(limits.text()).toContain('payroll.health_notifications.kind.employee_data_change')
    expect(limits.text()).toContain('payroll.health_notifications.kind.state_category_other')
  })

  it('u nedoloženého kódu ukáže konkrétní důvod, ne obecnou hlášku', async () => {
    m.duties.mockResolvedValue(dutyPage({
      items: [dutyItem({
        kind: 'insurer_change',
        change_code: {
          documented: false,
          code: null,
          reason: 'Přestup se hlásí každé pojišťovně jinak, bez směru kód neplyne.',
        },
      })],
    }))
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    const badge = wrapper.find('[data-test="health-notification-code-undocumented"]')
    expect(badge.exists()).toBe(true)
    expect(badge.attributes('title')).toContain('bez směru kód neplyne')
  })

  /**
   * Selhání načtení nesmí vypadat jako prázdná agenda — u osmidenní lhůty je
   * to nejdražší možná lež.
   */
  it('na selhání načtení ukáže failed stav, ne prázdný', async () => {
    m.duties.mockRejectedValue(new Error('boom'))
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    expect(wrapper.find('[data-test="health-notifications-failed"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="health-notifications-empty"]').exists()).toBe(false)
  })

  it('při selhání dalšího načtení nevynuluje už zobrazená data', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    expect(wrapper.findAll('[data-test="health-notification-row"]')).toHaveLength(1)

    m.duties.mockRejectedValue(new Error('boom'))
    await wrapper.find('[data-test="health-notifications-period"]').setValue('2026-07')
    await flushPromises()

    expect(wrapper.findAll('[data-test="health-notification-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="health-notifications-stale"]').exists()).toBe(true)
  })

  it('filtr posílá na server a stránkuje se tam taky', async () => {
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    await wrapper.find('[data-test="health-notifications-undocumented"]').setValue(true)
    await flushPromises()

    const lastCall = m.duties.mock.calls.at(-1)
    expect(lastCall?.[0]).toBe(localPayrollPeriod())
    expect(lastCall?.[1]).toMatchObject({
      undocumented_code_only: true,
      limit: 50,
      offset: 0,
    })
  })

  it('vztah bez pojišťovny pojmenuje místo vypuštění', async () => {
    m.duties.mockResolvedValue(dutyPage({
      unresolved_employments: [{
        employment_id: 11,
        full_name: 'Osoba bez pojišťovny',
        reason_code: 'zp_insurer_code_missing',
        reason: 'Zaměstnanec nemá evidovanou zdravotní pojišťovnu.',
      }],
    }))
    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    const box = wrapper.find('[data-test="health-notifications-unresolved"]')
    expect(box.exists()).toBe(true)
    expect(box.text()).toContain('Osoba bez pojišťovny')
  })

  /**
   * Soubor vzniká i u zablokovaného podání — a právě tam ho účetní potřebuje
   * vidět. Stahování se proto za `schema_validated` neschovává.
   */
  it('nabídne stažení XML i u blokující výhrady a ukáže její důvod', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 55,
      obligation_id: 7,
      part_id: 8,
      artifact_id: 9,
      status: 'draft',
      row_version: 3,
      insurer_code: '111',
      period: '2026-06',
      agenda_code: 'PPZ_2026',
      artifact_sha256: 'b'.repeat(64),
      created: true,
      deadline: {
        earliest_submission_on: '2026-06-30',
        due_on: '2026-07-20',
        calendar_basis: 'calendar_days',
        ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
        ruleset_hash: 'a'.repeat(64),
        source: '§ 25 odst. 3 zákona č. 592/1992 Sb.',
        source_status: 'statute_verified',
      },
      schema_validated: false,
      dispatch: {
        supported: false,
        reason_code: 'zp_shared_data_message_acceptance_unconfirmed',
        reason: 'Odeslání pojišťovně 111 není doložené.',
        channel: CHANNEL_111,
      },
    })
    m.submissionDetail.mockResolvedValue({
      submission: { id: 55 },
      parts: [],
      artifacts: [{ id: 9, mime_type: 'application/xml', byte_size: 512 }],
      issues: [],
      receipts: [],
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()

    // Revizi a pojišťovnu vybere uživatel; SearchableSelect je v testu
    // adresovaný přes svůj model, ne přes rozbalovací seznam.
    pick(wrapper, 'health-prepare-revision', 12)
    pick(wrapper, 'health-prepare-insurer', '111')
    await flushPromises()

    const prepareButton = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.health_notifications.prepare.action'))
    expect(prepareButton).toBeDefined()
    await prepareButton!.trigger('click')
    await flushPromises()

    expect(m.prepare).toHaveBeenCalledWith(12, '111')

    const result = wrapper.find('[data-test="health-prepare-result"]')
    expect(result.exists()).toBe(true)
    expect(result.text()).toContain('payroll.health_notifications.prepare.blocked')
    // Důvod nedostupnosti odeslání se ukazuje i u výsledku, ne jen nahoře.
    expect(result.text()).toContain('Odeslání pojišťovně 111 není doložené.')

    // Stažení JE k dispozici, přestože podání zůstalo v konceptu.
    const download = wrapper.find('[data-test="health-prepare-download"]')
    expect(download.exists()).toBe(true)
    await download.trigger('click')
    await flushPromises()

    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(
      55,
      expect.objectContaining({ id: 9 }),
    )
  })

  it('u platné věty ohlásí platnost a lhůtu', async () => {
    m.prepare.mockResolvedValue({
      submission_id: 56,
      obligation_id: 7,
      part_id: 8,
      artifact_id: 10,
      status: 'ready',
      row_version: 4,
      insurer_code: '111',
      period: '2026-06',
      agenda_code: 'PPZ_2026',
      artifact_sha256: 'c'.repeat(64),
      created: true,
      deadline: {
        earliest_submission_on: '2026-06-30',
        due_on: '2026-07-20',
        calendar_basis: 'calendar_days',
        ruleset_id: 'cz-health-insurance-notification-deadlines.v1',
        ruleset_hash: 'a'.repeat(64),
        source: '§ 25 odst. 3 zákona č. 592/1992 Sb.',
        source_status: 'statute_verified',
      },
      schema_validated: true,
      dispatch: {
        supported: false,
        reason_code: 'zp_shared_data_message_acceptance_unconfirmed',
        reason: 'Odeslání pojišťovně 111 není doložené.',
        channel: CHANNEL_111,
      },
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-revision', 12)
    pick(wrapper, 'health-prepare-insurer', '111')
    await flushPromises()

    const prepareButton = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.health_notifications.prepare.action'))
    await prepareButton!.trigger('click')
    await flushPromises()

    const result = wrapper.find('[data-test="health-prepare-result"]')
    expect(result.text()).toContain('payroll.health_notifications.prepare.valid')
    // Platnost NEZNAMENÁ, že se odešle — přiznání zůstává i u zelené věty.
    expect(result.text()).toContain('Odeslání pojišťovně 111 není doložené.')
  })

  it('konkrétní důvod selhání sestavení se propíše na obrazovku', async () => {
    m.prepare.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Součet pojistného obsahuje haléře, ale datová věta má celé koruny.',
          },
        },
      },
    })

    const wrapper = mount(PayrollHealthNotificationPanel)
    await flushPromises()
    pick(wrapper, 'health-prepare-revision', 12)
    pick(wrapper, 'health-prepare-insurer', '111')
    await flushPromises()

    const prepareButton = wrapper.findAll('button')
      .find(button => button.text()
        .includes('payroll.health_notifications.prepare.action'))
    await prepareButton!.trigger('click')
    await flushPromises()

    const box = wrapper.find('[data-test="health-prepare-error"]')
    expect(box.exists()).toBe(true)
    expect(box.text()).toContain('haléře')
    expect(box.text()).not.toContain('payroll.health_notifications.prepare.failed')
  })
})
