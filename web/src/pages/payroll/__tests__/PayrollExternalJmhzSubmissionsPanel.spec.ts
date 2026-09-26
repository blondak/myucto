import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  remove: vi.fn(),
  canWrite: true,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzExternalSubmissions: m.list,
    deleteJmhzExternalSubmission: m.remove,
  },
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
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
}

describe('PayrollExternalJmhzSubmissionsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite = true
    vi.spyOn(window, 'confirm').mockReturnValue(true)
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

  it('záznam, který neodpovídá, jde po potvrzení odebrat', async () => {
    m.list.mockResolvedValue({ environment: 'production', items: [row(), row({ id: 2, period: '2026-03' })] })
    m.remove.mockResolvedValue({ deleted: true, id: 1 })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.findAll('[data-test="external-jmhz-remove"]')[0].trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(m.remove).toHaveBeenCalledWith(1, 'production')
    expect(wrapper.findAll('[data-test="external-jmhz-row"]')).toHaveLength(1)
  })

  it('bez potvrzení nic neodebere a bez práva zápisu tlačítko nenabídne', async () => {
    m.list.mockResolvedValue({ environment: 'production', items: [row()] })
    vi.spyOn(window, 'confirm').mockReturnValue(false)

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="external-jmhz-remove"]').trigger('click')
    expect(m.remove).not.toHaveBeenCalled()

    m.canWrite = false
    const readOnly = mountPanel()
    await flushPromises()
    expect(readOnly.find('[data-test="external-jmhz-remove"]').exists()).toBe(false)
  })

  it('chybu načtení ukáže, místo aby panel tiše zmizel', async () => {
    m.list.mockRejectedValue({ response: { data: { error: { message: 'Chyba serveru' } } } })

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="external-jmhz-error"]').text()).toContain('Chyba serveru')
  })
})
