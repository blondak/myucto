import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  routerPush: vi.fn(),
  routerReplace: vi.fn(),
  listAccounts: vi.fn(),
  supplier: { accounting_mode: 'tax_evidence' },
}))

vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: m.listAccounts } }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))

vi.mock('@/api/otherItems', () => ({
  otherItemsApi: { get: m.get, update: m.update, create: vi.fn() },
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: m.supplier }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), success: vi.fn() }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '42' }, query: {} }),
  useRouter: () => ({ push: m.routerPush, replace: m.routerReplace }),
  RouterLink: { template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import OtherItemEditor from '../OtherItemEditor.vue'

describe('OtherItemEditor partner', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.supplier.accounting_mode = 'tax_evidence'
    m.get.mockResolvedValue({
      id: 42,
      status: 'draft',
      side: 'payable',
      kind: 'rent',
      title: 'Syntetický nájem',
      partner_id: 17,
      partner_name: 'Syntetický dodavatel',
      issued_on: '2099-01-01',
      accounting_on: '2099-01-01',
      due_on: '2099-01-20',
      currency: 'CZK',
      exchange_rate: null,
      amount: 1200,
      variable_symbol: null,
      account_code: null,
      counter_account_code: null,
      note: null,
    })
    m.update.mockResolvedValue({ id: 42 })
    m.routerPush.mockResolvedValue(undefined)
  })

  it('při uložení konceptu zachová vazbu na načteného partnera', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('input[maxlength="255"]').setValue('Upravený nájem')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: 17,
      partner_name: 'Syntetický dodavatel',
      title: 'Upravený nájem',
    }))
  })

  it('při výslovném vymazání protistrany odstraní i vazbu', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('input[maxlength="190"]').setValue('')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: null,
      partner_name: null,
    }))
  })

  it('při ruční změně názvu protistrany odstraní vazbu na původního klienta', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('input[maxlength="190"]').setValue('Jiná protistrana')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: null,
      partner_name: 'Jiná protistrana',
    }))
  })

  it('výběr protistrany z adresáře uloží její identifikátor i název', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const picker = wrapper.getComponent({ name: 'ClientSearchSelect' })
    picker.vm.$emit('update:modelValue', 23)
    picker.vm.$emit('selected', { id: 23, company_name: 'Syntetický klient' })
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: 23,
      partner_name: 'Syntetický klient',
    }))
  })

  it('při změně směru přepne význam druhu a vrátí jej na obecnou položku', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const kind = wrapper.findAll('select')[1]!
    expect(kind.find('option[value="loan"]').text()).toBe('other_items.kind_by_side.payable.loan')
    await wrapper.findAll('select')[0]!.setValue('receivable')
    expect(kind.find('option[value="loan"]').text()).toBe('other_items.kind_by_side.receivable.loan')
    expect((kind.element as HTMLSelectElement).value).toBe('other')
  })

  it('v podvojném účetnictví nabízí našeptávač zvlášť pro rozvahový účet a protiúčet', async () => {
    m.supplier.accounting_mode = 'double_entry'
    m.listAccounts.mockResolvedValue([
      { id: 1, account_code: '311.100', name: 'Pohledávka', account_type: 'asset', is_active: true },
      { id: 2, account_code: '325.100', name: 'Závazek', account_type: 'liability', is_active: true },
      { id: 3, account_code: '648', name: 'Výnos', account_type: 'revenue', is_active: true },
    ])
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const pickers = wrapper.findAllComponents({ name: 'ChartAccountSelect' })
    expect(pickers).toHaveLength(2)
    expect(pickers[0]!.props('accounts').map((account: { account_code: string }) => account.account_code)).toEqual(['325.100'])
    expect(pickers[1]!.props('accounts')).toHaveLength(3)
  })

  it('při úpravě zachová rozdělenou kontaci a odešle oba protiřádky', async () => {
    m.supplier.accounting_mode = 'double_entry'
    m.listAccounts.mockResolvedValue([
      { id: 1, account_code: '325', name: 'Závazky', account_type: 'liability', is_active: true },
      { id: 2, account_code: '518', name: 'Náklady', account_type: 'expense', is_active: true },
      { id: 3, account_code: '378', name: 'Jiné pohledávky', account_type: 'asset', is_active: true },
    ])
    m.get.mockResolvedValue({
      id: 42, status: 'draft', side: 'payable', kind: 'rent', title: 'Syntetický nájem',
      partner_id: null, partner_name: null, issued_on: '2099-01-01', accounting_on: '2099-01-01',
      due_on: '2099-01-20', currency: 'CZK', amount: 1200, variable_symbol: null,
      account_code: '325', counter_account_code: null, note: null,
      posting_lines: [{ account_code: '518', amount: 700 }, { account_code: '378', amount: 500 }],
    })
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const lines = wrapper.getComponent({ name: 'OtherItemPostingLines' })
    expect(lines.props('modelValue')).toEqual([
      { account_code: '518', amount: 700 }, { account_code: '378', amount: 500 },
    ])
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      counter_account_code: null,
      posting_lines: [{ account_code: '518', amount: 700 }, { account_code: '378', amount: 500 }],
    }))
  })
})
