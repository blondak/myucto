import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { EnforcementTerminationNotice, EnforcementTerminationNoticeOverview } from '@/api/payrollEnforcement'

const m = vi.hoisted(() => ({
  terminationNotices: vi.fn(),
  generateTerminationNotice: vi.fn(),
  markTerminationNoticeSent: vi.fn(),
  enqueueTerminationNotice: vi.fn(),
  downloadTerminationNotice: vi.fn(),
  recipients: vi.fn(),
  error: vi.fn(),
  success: vi.fn(),
}))

vi.mock('@/api/payrollEnforcement', () => ({
  payrollEnforcementApi: {
    terminationNotices: m.terminationNotices,
    generateTerminationNotice: m.generateTerminationNotice,
    markTerminationNoticeSent: m.markTerminationNoticeSent,
    enqueueTerminationNotice: m.enqueueTerminationNotice,
    downloadTerminationNotice: m.downloadTerminationNotice,
  },
}))
vi.mock('@/api/dataBox', () => ({ dataBoxApi: { recipients: m.recipients } }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.error, success: m.success, warning: vi.fn() }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

import EnforcementTerminationNoticePanel from '@/pages/payroll/EnforcementTerminationNoticePanel.vue'

const preview = {
  due_on: '2026-07-07',
  employment: { ended_on: '2026-06-30' },
  authority: { role: 'executor' as const, name: 'Syntetický exekutorský úřad', reference: '999 EX 1/26' },
  totals: { withheld_minor: 381_200, paid_out_minor: 0, held_minor: 0, administrator_minor: 0, remaining_minor: 1_000_000 },
}

function notice(overrides: Partial<EnforcementTerminationNotice> = {}): EnforcementTerminationNotice {
  return {
    id: 5,
    case_id: 11,
    employee_id: 3,
    employment_id: 7,
    revision_no: 1,
    employment_ended_on: '2026-06-30',
    due_on: '2026-07-07',
    new_payer_name: null,
    new_payer_reference: null,
    snapshot_hash: 'a'.repeat(64),
    sent_on: null,
    sent_channel: null,
    outbox_id: null,
    created_at: '2026-07-01 08:00:00',
    ...overrides,
  }
}

function mountPanel() {
  return mount(EnforcementTerminationNoticePanel, {
    props: { caseId: 11, canWrite: true },
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

describe('EnforcementTerminationNoticePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.recipients.mockResolvedValue([{ id: 41, name: 'Syntetický exekutor', isds_box_id: 'abc1234', is_active: true, has_box_id: true }])
  })

  it('vystaví oznámení s novým plátcem a ukáže termín do 7 dnů', async () => {
    m.terminationNotices
      .mockResolvedValueOnce({ notices: [], preview, blocked_reason: null } satisfies EnforcementTerminationNoticeOverview)
      .mockResolvedValueOnce({ notices: [notice()], preview, blocked_reason: null })
    m.generateTerminationNotice.mockResolvedValue(notice())
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="termination-notice-preview"]').text()).toContain('2026-07-07')
    await wrapper.get('[data-test="termination-notice-new-payer"]').setValue('Syntetický nový zaměstnavatel')
    await wrapper.get('[data-test="termination-notice-generate"]').trigger('submit')
    await flushPromises()

    expect(m.generateTerminationNotice).toHaveBeenCalledWith(11, {
      new_payer_name: 'Syntetický nový zaměstnavatel',
      new_payer_reference: null,
    })
    expect(wrapper.findAll('[data-test="termination-notice-row"]')).toHaveLength(1)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('zařadí oznámení do datové schránky a zaznamená odeslání', async () => {
    m.terminationNotices.mockResolvedValue({ notices: [notice()], preview, blocked_reason: null })
    m.enqueueTerminationNotice.mockResolvedValue({ outbox_id: 77, created: true })
    m.markTerminationNoticeSent.mockResolvedValue(notice({ sent_on: '2026-07-03', sent_channel: 'isds' }))
    const wrapper = mountPanel()
    await flushPromises()

    const enqueue = wrapper.get('[data-test="termination-notice-enqueue"]')
    expect(enqueue.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="termination-notice-recipient"]').setValue(41)
    await enqueue.trigger('click')
    await flushPromises()
    expect(m.enqueueTerminationNotice).toHaveBeenCalledWith(5, 41)

    await wrapper.get('[data-test="termination-notice-channel"]').setValue('post')
    await wrapper.get('[data-test="termination-notice-mark-sent"]').trigger('click')
    await flushPromises()
    expect(m.markTerminationNoticeSent).toHaveBeenCalledWith(5, expect.objectContaining({ channel: 'post' }))
  })

  it('řekne, proč oznámení zatím vystavit nejde', async () => {
    m.terminationNotices.mockResolvedValue({ notices: [], preview: null, blocked_reason: 'Poměr trvá.' })
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="termination-notice-blocked"]').text()).toBe('Poměr trvá.')
    expect(wrapper.find('[data-test="termination-notice-generate"]').exists()).toBe(false)
  })
})
