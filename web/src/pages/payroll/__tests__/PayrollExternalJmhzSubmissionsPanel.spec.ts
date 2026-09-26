import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  detail: vi.fn(),
  remove: vi.fn(),
  canWrite: true,
  query: {} as Record<string, string>,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzExternalSubmissions: m.list,
    jmhzExternalSubmission: m.detail,
    deleteJmhzExternalSubmission: m.remove,
  },
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.query }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => m.canWrite }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import PayrollExternalJmhzSubmissionsPanel from '@/pages/payroll/PayrollExternalJmhzSubmissionsPanel.vue'

function row(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    source: 'pamica',
    document_kind: 'monthly',
    period: '2026-02',
    submission_type: 'R',
    submission_guid: '22222222-2222-4222-8222-222222222222',
    status: 'sent',
    filled_at: '2026-03-16 09:00:00',
    submitted_at: '2026-03-16 09:00:00',
    accepted_at: '2026-03-16 09:05:00',
    form_count: 2,
    matched_forms: 2,
    program: 'PAMICA',
    file_name: null,
    updated_at: '2026-09-26 10:00:00',
    ...overrides,
  }
}

function mountPanel() {
  return mount(PayrollExternalJmhzSubmissionsPanel, {
    props: { environment: 'production' },
    attachTo: document.body,
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
        teleport: true,
      },
    },
  })
}

function registration(overrides: Record<string, unknown> = {}) {
  return row({
    id: 20,
    document_kind: 'registration',
    period: null,
    submission_type: null,
    submission_guid: null,
    submitted_at: '2026-04-29 07:41:28',
    filled_at: '2026-04-29 07:41:28',
    accepted_at: null,
    form_count: 2,
    matched_forms: 2,
    actions: { A3: 2 },
    people: [
      { employee_id: 3, employment_id: 30, name: 'Syntetická První', code: 'S1', action: 'A3', effective_on: '2026-03-27' },
      { employee_id: 4, employment_id: 40, name: 'Syntetická Druhá', code: 'S2', action: 'A3', effective_on: '2026-03-27' },
    ],
    effective_from: '2026-03-27',
    effective_to: '2026-03-27',
    ...overrides,
  })
}

async function openMenuAndRemove(wrapper: ReturnType<typeof mountPanel>, index = 0) {
  await wrapper.findAll('[data-test="external-jmhz-row"]')[index].get('button[aria-haspopup="menu"]').trigger('click')
  const item = [...document.querySelectorAll<HTMLElement>('[data-menu-item]')].find(el => el.textContent?.includes('payroll.external_jmhz.remove'))
  item?.click()
  await flushPromises()
}

describe('PayrollExternalJmhzSubmissionsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite = true
    m.query = {}
    document.body.innerHTML = ''
  })

  it('registrace seskupí po měsíci a řekne, kdo v nich byl a jakou akcí', async () => {
    m.list.mockResolvedValue({ environment: 'production', items: [row(), registration()] })

    const wrapper = mountPanel()
    await flushPromises()

    const group = wrapper.get('[data-test="external-jmhz-group-registration-2026-04"]')
    expect(group.text()).toContain('Syntetická První')
    expect(group.text()).toContain('Syntetická Druhá')
    expect(group.get('[data-test="external-jmhz-action"]').text()).toContain('payroll.external_jmhz.action.A3')
    expect(group.text()).toContain('payroll.external_jmhz.effective')
    expect(wrapper.find('[data-test="external-jmhz-group-monthly-2026-02"]').exists()).toBe(true)
  })

  it('detail ukáže všechny formuláře s osobou, akcí a účinností; odkaz z dohlášení ho otevře rovnou', async () => {
    m.query = { external: '20' }
    m.list.mockResolvedValue({ environment: 'production', items: [registration()] })
    m.detail.mockResolvedValue({
      ...registration(),
      corrects: null,
      forms: [
        { position: 1, employee_id: 3, employment_id: 30, name: 'Syntetická První', code: 'S1', action: 'A3', form_type: 'existing', effective_on: '2026-03-27', source_relation_ref: '1', unreadable: false },
        { position: 2, employee_id: null, employment_id: null, name: null, code: null, action: 'A2', form_type: 'end', effective_on: '2026-04-30', source_relation_ref: '77', unreadable: false },
      ],
    })

    const wrapper = mountPanel()
    await flushPromises()

    expect(m.detail).toHaveBeenCalledWith(20, 'production')
    const forms = wrapper.findAll('[data-test="external-jmhz-detail-form"]')
    expect(forms).toHaveLength(2)
    expect(forms[0].text()).toContain('Syntetická První')
    expect(forms[0].text()).toContain('payroll.external_jmhz.action.A3')
    expect(forms[1].text()).toContain('payroll.external_jmhz.unmatched_form 77')
  })

  it('firma bez převodu z jiného programu panel nevidí', async () => {
    m.list.mockResolvedValue({ environment: 'production', items: [] })

    const wrapper = mountPanel()
    await flushPromises()

    expect(m.list).toHaveBeenCalledWith('production')
    expect(wrapper.find('[data-test="external-jmhz-panel"]').exists()).toBe(false)
  })

  it('vypíše převzatá podání a na neodeslaný měsíc upozorní s odkazem na převod', async () => {
    m.list.mockResolvedValue({
      environment: 'production',
      items: [
        row(),
        row({ id: 2, submission_type: 'O', matched_forms: 1, form_count: 1 }),
        row({ id: 3, period: '2026-01', status: 'not_sent', submitted_at: null, accepted_at: null }),
        row({ id: 4, document_kind: 'registration', period: null, submission_type: null }),
      ],
    })

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('#external-submissions').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="external-jmhz-row"]')).toHaveLength(4)
    const text = wrapper.text()
    expect(text).toContain('payroll.external_jmhz.type.R')
    expect(text).toContain('payroll.external_jmhz.type.O')
    expect(text).toContain('payroll.external_jmhz.type.registration')
    expect(text).toContain('payroll.external_jmhz.status.not_sent')
    const unsent = wrapper.findAll('[data-test="external-jmhz-unsent"]')
    expect(unsent).toHaveLength(1)
    expect(unsent[0].text()).toContain('payroll.external_jmhz.unsent_title')
    expect(unsent[0].get('a').attributes('data-to')).toContain('imports-pamica')
  })

  it('neodeslaný měsíc, za který jiné podání odešlo, neupozorňuje', async () => {
    m.list.mockResolvedValue({
      environment: 'production',
      items: [row(), row({ id: 3, status: 'not_sent', submitted_at: null })],
    })

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.findAll('[data-test="external-jmhz-unsent"]')).toHaveLength(0)
  })

  it('odebrání je schované v „…“ a po vysvětlení v dialogu aplikace odebere záznam', async () => {
    m.list.mockResolvedValue({ environment: 'production', items: [row(), row({ id: 2, period: '2026-03' })] })
    m.remove.mockResolvedValue({ deleted: true, id: 2 })

    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.findAll('[data-test="external-jmhz-row"]')[0].text()).not.toContain('payroll.external_jmhz.remove')
    await openMenuAndRemove(wrapper, 0)

    const dialog = wrapper.get('[data-test="external-jmhz-remove-dialog"]')
    expect(dialog.text()).toContain('payroll.external_jmhz.remove_dialog.when')
    expect(dialog.text()).toContain('payroll.external_jmhz.remove_dialog.monthly_sent')
    expect(m.remove).not.toHaveBeenCalled()
    await wrapper.get('[data-test="external-jmhz-remove-confirm"]').trigger('click')
    await flushPromises()

    expect(m.remove).toHaveBeenCalledWith(2, 'production')
    expect(wrapper.findAll('[data-test="external-jmhz-row"]')).toHaveLength(1)
  })

  it('bez práva zápisu odebrání nenabídne', async () => {
    m.canWrite = false
    m.list.mockResolvedValue({ environment: 'production', items: [row()] })

    const wrapper = mountPanel()
    await flushPromises()
    expect(wrapper.find('[data-test="external-jmhz-row"] button[aria-haspopup="menu"]').exists()).toBe(false)
  })

  it('chybu načtení ukáže, místo aby panel tiše zmizel', async () => {
    m.list.mockRejectedValue({ response: { data: { error: { message: 'Chyba serveru' } } } })

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="external-jmhz-error"]').text()).toContain('Chyba serveru')
  })
})
