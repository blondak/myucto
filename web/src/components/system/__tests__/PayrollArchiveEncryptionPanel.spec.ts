import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { DiagnosticCheck } from '@/api/diagnostics'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: ref('cs-CZ'),
    t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key),
    te: () => false,
  }),
}))

const reencrypt = vi.fn()
const rewrap = vi.fn()
vi.mock('@/api/diagnostics', () => ({
  diagnosticsApi: {
    payrollArchiveReencrypt: (p: unknown) => reencrypt(p),
    payrollKeyRewrap: (p: unknown) => rewrap(p),
  },
}))

import PayrollArchiveEncryptionPanel from '@/components/system/PayrollArchiveEncryptionPanel.vue'

const ModalStub = {
  props: ['title'],
  emits: ['close'],
  template: '<div data-testid="modal"><slot /><slot name="footer" /></div>',
}

function check(partial: Partial<DiagnosticCheck> & { id: string; status: DiagnosticCheck['status'] }): DiagnosticCheck {
  return { actual: '', expected: '', manual: '', ...partial }
}

const LEGACY = check({
  id: 'payroll_archive_encryption',
  status: 'warn',
  actual: '3',
  meta: { legacy_files: 3, suppliers: [{ supplier_id: 7, name: 'Syntetická s.r.o.', files: 3 }] },
})

function counts(partial: Record<string, number>): Record<string, number> {
  return { encrypted: 0, would_encrypt: 0, orphan_skipped: 0, erased_skipped: 0, would_purge: 0, erased_purged: 0, integrity_mismatch: 0, failed: 0, gone: 0, ...partial }
}

function mountPanel(checks: DiagnosticCheck[]) {
  return mount(PayrollArchiveEncryptionPanel, {
    props: { checks },
    global: { stubs: { Modal: ModalStub } },
  })
}

describe('PayrollArchiveEncryptionPanel', () => {
  beforeEach(() => {
    reencrypt.mockReset()
    rewrap.mockReset()
  })

  it('bez nálezu se nezobrazí', () => {
    const wrapper = mountPanel([
      check({ id: 'payroll_archive_encryption', status: 'ok', actual: '0', meta: { legacy_files: 0, suppliers: [] } }),
      check({ id: 'payroll_key_rotation', status: 'skip', actual: 'no_rotation' }),
    ])
    expect(wrapper.find('[data-testid="payroll-archive-panel"]').exists()).toBe(false)
  })

  it('ukáže počet po firmách s názvem', () => {
    const wrapper = mountPanel([LEGACY])
    const text = wrapper.find('[data-testid="payroll-archive-legacy"]').text()
    expect(text).toContain('Syntetická s.r.o. (#7)')
    expect(text).toContain('"count":3')
  })

  it('nejdřív náhled, po potvrzení dávkuje až do konce a ohlásí změnu', async () => {
    reencrypt
      .mockResolvedValueOnce({ dry_run: true, processed: 3, remaining: 0, counts: counts({ would_encrypt: 3 }), problems: [] })
      .mockResolvedValueOnce({ dry_run: false, processed: 2, remaining: 1, counts: counts({ encrypted: 2 }), problems: [] })
      .mockResolvedValueOnce({ dry_run: false, processed: 1, remaining: 0, counts: counts({ encrypted: 1 }), problems: [] })
    const wrapper = mountPanel([LEGACY])

    await wrapper.find('[data-testid="payroll-archive-open"]').trigger('click')
    await flushPromises()
    expect(reencrypt).toHaveBeenNthCalledWith(1, { dry_run: true, include_orphans: true, purge_erased: false })
    expect(wrapper.find('[data-testid="payroll-archive-summary"]').text()).toContain('would_encrypt')

    await wrapper.find('[data-testid="payroll-archive-confirm"]').trigger('click')
    await flushPromises()
    expect(reencrypt).toHaveBeenCalledTimes(3)
    expect(reencrypt.mock.calls[1][0]).toMatchObject({ confirm: true, confirm_purge: false })
    expect(wrapper.find('[data-testid="payroll-archive-summary"]').text()).toContain('3')
    expect(wrapper.find('[data-testid="payroll-archive-done"]').exists()).toBe(true)
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('dávka bez pokroku dávkování zastaví', async () => {
    reencrypt
      .mockResolvedValueOnce({ dry_run: true, processed: 1, remaining: 0, counts: counts({ would_encrypt: 1 }), problems: [] })
      .mockResolvedValue({
        dry_run: false,
        processed: 1,
        remaining: 5,
        counts: counts({ failed: 1 }),
        problems: [{ supplier_id: 7, storage_key: 'abc', status: 'failed' }],
      })
    const wrapper = mountPanel([LEGACY])
    await wrapper.find('[data-testid="payroll-archive-open"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="payroll-archive-confirm"]').trigger('click')
    await flushPromises()

    expect(reencrypt).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('abc')
  })

  it('smazání po výmazu nepustí bez druhého potvrzení', async () => {
    reencrypt.mockResolvedValue({ dry_run: true, processed: 0, remaining: 0, counts: counts({}), problems: [] })
    const wrapper = mountPanel([LEGACY])
    await wrapper.find('[data-testid="payroll-archive-open"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="payroll-archive-purge"]').setValue(true)
    await flushPromises()
    const confirm = wrapper.find('[data-testid="payroll-archive-confirm"]')
    expect(confirm.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('diagnostics.payroll_archive.purge_ack_required')

    reencrypt.mockResolvedValueOnce({ dry_run: false, processed: 1, remaining: 0, counts: counts({ erased_purged: 1 }), problems: [] })
    await wrapper.find('[data-testid="payroll-archive-purge-ack"]').setValue(true)
    expect(confirm.attributes('disabled')).toBeUndefined()
    await confirm.trigger('click')
    await flushPromises()
    expect(reencrypt).toHaveBeenLastCalledWith(expect.objectContaining({ purge_erased: true, confirm: true, confirm_purge: true }))
  })

  it('přebalení běží po dávkách a hlásí neznámý klíč', async () => {
    rewrap
      .mockResolvedValueOnce({ dry_run: false, rewrapped: 500, would_rewrap: 0, failed: 0, remaining: 20, unknown: 2 })
      .mockResolvedValueOnce({ dry_run: false, rewrapped: 20, would_rewrap: 0, failed: 0, remaining: 0, unknown: 2 })
    const wrapper = mountPanel([
      check({ id: 'payroll_key_rotation', status: 'fail', actual: '2', meta: { stale_total: 520, unknown_total: 2, targets: [] } }),
    ])
    expect(wrapper.find('[data-testid="payroll-archive-rotation"]').text()).toContain('diagnostics.payroll_archive.unknown_key')

    await wrapper.find('[data-testid="payroll-rewrap-open"]').trigger('click')
    await wrapper.find('[data-testid="payroll-rewrap-confirm"]').trigger('click')
    await flushPromises()

    expect(rewrap).toHaveBeenCalledTimes(2)
    expect(rewrap).toHaveBeenCalledWith({ confirm: true })
    expect(wrapper.find('[data-testid="payroll-rewrap-result"]').text()).toContain('520')
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })
})
