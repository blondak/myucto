import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

// ── Mockovaný stav (hoisted) ─────────────────────────────────────────────────
const m = vi.hoisted(() => ({
  listRegisters: vi.fn(),
  listRulePresets: vi.fn(),
  searchUnpaid: vi.fn(),
  createDocument: vi.fn(),
  listAccounts: vi.fn(),
  listPostingRules: vi.fn(),
  listTaxConstants: vi.fn(),
  listClients: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/cash', () => ({
  cashApi: {
    listRegisters: m.listRegisters,
    listRulePresets: m.listRulePresets,
    searchUnpaid: m.searchUnpaid,
    createDocument: m.createDocument,
  },
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { listAccounts: m.listAccounts, listPostingRules: m.listPostingRules },
}))

vi.mock('@/api/taxConstants', () => ({
  taxConstantsApi: { list: m.listTaxConstants },
}))

vi.mock('@/api/clients', () => ({
  clientsApi: { list: m.listClients },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.toastError, success: vi.fn() }),
}))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number) => String(v),
  formatDate: (v: string) => v,
}))

vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { save: 'M0 0', back: 'M0 0', plus: 'M0 0', trash: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
}))

vi.mock('@/components/cash/CashVatBreakdown.vue', () => ({
  default: { name: 'CashVatBreakdown', props: ['modelValue'], template: '<div />' },
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry' } }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: {}, params: {} }),
  useRouter: () => ({ push: vi.fn() }),
}))

import CashDocumentEditor from '@/pages/accounting/CashDocumentEditor.vue'

function client(id: number, company_name: string, ic: string | null, dic: string | null) {
  return { id, company_name, ic, dic }
}

function clientPage(data: unknown[]) {
  return { data, meta: { total: data.length, page: 1, per_page: 50, pages: 1 } }
}

async function mountEditor() {
  const wrapper = mount(CashDocumentEditor)
  await flushPromises()
  return wrapper
}

describe('CashDocumentEditor — našeptávač partnera', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    m.listRegisters.mockResolvedValue([{ id: 1, name: 'Hlavní', account_code: '211001', currency_code: 'CZK', is_default: true, is_active: true }])
    m.listAccounts.mockResolvedValue([])
    m.listPostingRules.mockResolvedValue({})
    m.listTaxConstants.mockResolvedValue([])
    m.listRulePresets.mockResolvedValue([])
    m.listClients.mockResolvedValue(clientPage([]))
  })

  it('načítá klienty hledáním na serveru, ne pevným stropem 100', async () => {
    await mountEditor()

    expect(m.listClients).toHaveBeenCalledTimes(1)
    const params = m.listClients.mock.calls[0][0]
    expect(params.per_page).toBe(50)
    expect(params.role).toBe('customers')
    expect(params.q).toBeUndefined()
  })

  it('při psaní pošle dotaz na server s `q` (po debounce)', async () => {
    const wrapper = await mountEditor()
    m.listClients.mockClear()

    const input = wrapper.find('input[list="cash-partners"]')
    await input.setValue('Zeta')

    expect(m.listClients).not.toHaveBeenCalled()
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(m.listClients).toHaveBeenCalledTimes(1)
    expect(m.listClients.mock.calls[0][0].q).toBe('Zeta')
  })

  it('po výběru klienta předvyplní IČO a DIČ', async () => {
    m.listClients.mockResolvedValue(clientPage([client(7, 'Zeta s.r.o.', '12345678', 'CZ12345678')]))
    const wrapper = await mountEditor()

    const input = wrapper.find('input[list="cash-partners"]')
    await input.setValue('Zeta s.r.o.')
    vi.advanceTimersByTime(300)
    await flushPromises()

    const vm = wrapper.vm as unknown as { form: { partner_ic: string; partner_dic: string } }
    expect(vm.form.partner_ic).toBe('12345678')
    expect(vm.form.partner_dic).toBe('CZ12345678')
  })

  it('ruční úpravu IČO nepřepisuje, dokud se nezmění shoda na jiného klienta', async () => {
    m.listClients.mockResolvedValue(clientPage([client(7, 'Zeta s.r.o.', '12345678', 'CZ12345678')]))
    const wrapper = await mountEditor()
    const vm = wrapper.vm as unknown as { form: { partner_ic: string; partner_dic: string } }

    const input = wrapper.find('input[list="cash-partners"]')
    await input.setValue('Zeta s.r.o.')
    vi.advanceTimersByTime(300)
    await flushPromises()

    vm.form.partner_ic = '99999999'
    await input.setValue('Zeta s.r.o.')
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(vm.form.partner_ic).toBe('99999999')
  })

  it('selhání načtení klientů není tiché', async () => {
    m.listClients.mockRejectedValue(new Error('boom'))
    await mountEditor()

    expect(m.toastError).toHaveBeenCalled()
  })
})
