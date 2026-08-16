import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { DataBoxCredential, OutboxSubmission } from '@/api/dataBox'

/**
 * UI nesmí slít „doručeno" a „zpracováno" do jednoho stavu.
 *
 * Doručenka z datové schránky dokládá, že zpráva DORAZILA do schránky úřadu.
 * O tom, jestli ji úřad zpracoval a přijal, neříká nic — chyby přijdou až po
 * dnech jako výzva k odstranění vad. Kdyby UI ukázalo jediný zelený štítek
 * „Hotovo", uživatel by v dobré víře přestal podání sledovat.
 *
 * Ten samý požadavek hlídá na backendu `DeliveryIsNotAcceptanceTest` (slovník
 * a tvar) a `SubmissionOutboxInvariantsTest` (databáze). Tenhle test uzavírá
 * poslední vrstvu: co uživatel doopravdy uvidí.
 */

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  unmatchedReceipts: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    credentials: m.credentials,
    recipients: m.recipients,
    outbox: m.outbox,
    inbox: m.inbox,
    unmatchedReceipts: m.unmatchedReceipts,
  },
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

import DataBox from '../DataBox.vue'

const credential: DataBoxCredential = {
  id: 1,
  supplier_id: 1,
  environment: 'production',
  channel: 'isds',
  label: 'Naše schránka',
  box_id: 'abcdefg',
  auth_mode: 'certificate',
  certificate_fingerprint: null,
  certificate_valid_to: null,
  last_verified_at: null,
  inbox_polling_enabled: false,
  inbox_polling_enabled_at: null,
  inbox_polling_enabled_by: null,
}

function submission(overrides: Partial<OutboxSubmission> = {}): OutboxSubmission {
  return {
    id: 10,
    environment: 'production',
    channel: 'isds',
    dispatch_mode: 'channel',
    agenda_code: 'DPHDP3',
    recipient_id: 1,
    recipient_box_id: 'zzzzzzz',
    subject: 'Přiznání k DPH',
    artifact_kind: 'tax_submission',
    artifact_id: 5,
    artifact_filename: 'dphdp3.xml',
    dispatch_state: 'delivered',
    acceptance_state: 'unknown',
    acceptance_evidence_kind: null,
    acceptance_note: null,
    correlation_reference: 'DPHDP3-20260815-ABCDEF',
    external_message_id: 'DM-1',
    artifact_validation_status: 'passed',
    recipient_box_verified_at: '2026-08-15 09:00:00',
    receipt_document_id: null,
    receipt_signature_status: 'unverified',
    receipt_matched_by: null,
    receipt_inbox_message_id: null,
    receipt_attached_at: null,
    confirmed_by: 1,
    confirmed_at: '2026-08-15 09:00:00',
    sent_at: '2026-08-15 09:00:00',
    delivered_at: '2026-08-15 10:00:00',
    accepted_at: null,
    rejected_at: null,
    last_error_code: null,
    last_error_message: null,
    row_version: 4,
    created_at: '2026-08-15 08:00:00',
    ...overrides,
  }
}

async function mountWith(rows: OutboxSubmission[]) {
  m.credentials.mockResolvedValue([credential])
  m.recipients.mockResolvedValue([])
  m.outbox.mockResolvedValue(rows)
  m.inbox.mockResolvedValue({ items: [], state: null })
  m.unmatchedReceipts.mockResolvedValue([])

  const wrapper = mount(DataBox, {
    global: {
      stubs: { EmptyState: true },
    },
  })
  await flushPromises()
  return wrapper
}

describe('DataBox — doručeno vs. zpracováno', () => {
  it('u doručeného podání ukáže obě osy zvlášť a vyřízení nechá na „nevíme“', async () => {
    const wrapper = await mountWith([submission()])
    await wrapper.findAll('nav button')[1].trigger('click')

    const text = wrapper.text()
    // Doprava: doručeno.
    expect(text).toContain('databox.dispatch.delivered')
    // Vyřízení: pořád neznámé — NIKDY ne „accepted“.
    expect(text).toContain('databox.acceptance.unknown')
    expect(text).not.toContain('databox.acceptance.accepted')
  })

  it('u doručeného podání vysvětlí větou, že úřad ještě nerozhodl', async () => {
    const wrapper = await mountWith([submission()])
    await wrapper.findAll('nav button')[1].trigger('click')

    expect(wrapper.text()).toContain('databox.outbox.deliveredNotProcessed')
  })

  it('teprve doložené přijetí ukáže „zpracováno“', async () => {
    const wrapper = await mountWith([
      submission({
        acceptance_state: 'accepted',
        acceptance_evidence_kind: 'agency_protocol_message',
        accepted_at: '2026-08-20 12:00:00',
      }),
    ])
    await wrapper.findAll('nav button')[1].trigger('click')

    expect(wrapper.text()).toContain('databox.acceptance.accepted')
    // Vysvětlující věta o nerozhodnutém podání už tam nemá co dělat.
    expect(wrapper.text()).not.toContain('databox.outbox.deliveredNotProcessed')
  })

  it('u nejistého odeslání nenabídne odeslat znovu, ale dohledat', async () => {
    const wrapper = await mountWith([
      submission({
        dispatch_state: 'send_uncertain',
        external_message_id: null,
        sent_at: null,
        delivered_at: null,
      }),
    ])
    await wrapper.findAll('nav button')[1].trigger('click')

    const text = wrapper.text()
    expect(text).toContain('databox.outbox.resolve')
    expect(text).toContain('databox.outbox.uncertainHint')
    // Opakované odeslání by u úřadu vyrobilo duplicitu.
    expect(text).not.toContain('databox.outbox.confirmSend')
  })

  it('vybírání schránky je ve výchozím stavu vypnuté a ruční vyzvednutí je zablokované', async () => {
    const wrapper = await mountWith([])
    await wrapper.findAll('nav button')[2].trigger('click')

    expect(wrapper.text()).toContain('databox.inbox.pollingOff')
    const pollButton = wrapper.findAll('button').find(b => b.text().includes('databox.inbox.poll'))
    expect(pollButton?.attributes('disabled')).toBeDefined()
  })
})
