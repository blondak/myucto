import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  targets: vi.fn(),
  batches: vi.fn(),
  batch: vi.fn(),
}))

vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) => (params ? `${key} ${JSON.stringify(params)}` : key),
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}))

vi.mock('@/api/scanAttach', () => ({
  scanAttachApi: { targets: m.targets, batches: m.batches, batch: m.batch },
}))

import ScanAttach from '../ScanAttach.vue'

const STUBS = {
  AttachmentDiscrepancyTable: true,
  AttachmentDiscrepanciesPanel: true,
  AttachmentCheckReview: true,
}

function batchDetail(id: number) {
  return {
    id, status: 'completed', total_items: 0, processed: 0, attached_count: 0, proposed_count: 0,
    failed_count: 0, current_step: null, last_error: null, cancel_requested: false,
    created_at: '2026-01-01 10:00:00', counts: {}, overview: null, log_text: null,
  }
}

async function mountPage() {
  const w = mount(ScanAttach, { global: { stubs: STUBS }, attachTo: document.body })
  await flushPromises()
  return w
}

function newBatchButton(w: Awaited<ReturnType<typeof mountPage>>) {
  const btn = w.findAll('button').find(b => b.text().includes('scan_attach.action_new'))
  if (!btn) throw new Error('Tlačítko pro nahrání skenů chybí')
  return btn
}

describe('ScanAttach výběr souborů', () => {
  let pickerOpened: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    vi.clearAllMocks()
    m.targets.mockResolvedValue({
      targets: [{ type: 'purchase_invoice', available: true, allowed: true }],
      default: ['purchase_invoice'],
    })
    m.batches.mockResolvedValue([])
    pickerOpened = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})
  })

  afterEach(() => {
    pickerOpened.mockRestore()
    document.body.innerHTML = ''
  })

  it('tlačítko Nahrát skeny formulář bez dávek nezavře a otevře výběr souborů', async () => {
    const w = await mountPage()
    expect(w.find('#scan-files').exists()).toBe(true)

    await newBatchButton(w).trigger('click')
    await flushPromises()

    expect(w.find('#scan-files').exists()).toBe(true)
    expect(pickerOpened).toHaveBeenCalledTimes(1)
    expect((pickerOpened.mock.contexts[0] as HTMLInputElement).id).toBe('scan-files')
  })

  it('s existující dávkou tlačítko formulář otevře a rovnou nabídne výběr souborů', async () => {
    m.batches.mockResolvedValue([batchDetail(7)])
    m.batch.mockResolvedValue(batchDetail(7))
    const w = await mountPage()
    expect(w.find('#scan-files').exists()).toBe(false)

    await newBatchButton(w).trigger('click')
    await flushPromises()

    expect(w.find('#scan-files').exists()).toBe(true)
    expect(pickerOpened).toHaveBeenCalledTimes(1)
  })

  it('soubory přetažené do zóny se vyberou a klik do zóny otevře výběr', async () => {
    const w = await mountPage()
    const zone = w.find('[data-testid="scan-dropzone"]')
    expect(zone.exists()).toBe(true)

    await zone.trigger('click')
    expect(pickerOpened).toHaveBeenCalledTimes(1)

    const files = [
      new File(['%PDF-1.4'], 'sken-1.pdf', { type: 'application/pdf' }),
      new File(['x'], 'sken-2.jpg', { type: 'image/jpeg' }),
    ]
    await zone.trigger('drop', { dataTransfer: { files } })

    expect(w.text()).toContain('scan_attach.form_selected {"n":2')
    const submit = w.findAll('button').find(b => b.text().includes('scan_attach.form_submit'))
    expect(submit?.attributes('disabled')).toBeUndefined()
  })
})
