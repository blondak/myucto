import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { ChartAccount } from '@/api/accounting'
import type { BankTransaction } from '@/api/bank'

const m = vi.hoisted(() => ({
  listAccounts: vi.fn(),
  aiAvailability: vi.fn(),
  postTransaction: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { listAccounts: m.listAccounts },
}))

vi.mock('@/api/bankPosting', () => ({
  bankPostingApi: {
    aiAvailability: m.aiAvailability,
    postTransaction: m.postTransaction,
  },
  bankPostingErrorMessage: (_e: unknown, t: (k: string) => string) => t('err'),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => {} }))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number) => String(v),
  formatDate: (v: string) => v,
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import PostTransactionModal from '@/components/bank/PostTransactionModal.vue'

function account(id: number, code: string, name: string, parentId: number | null): ChartAccount {
  return {
    id,
    supplier_id: 1,
    account_code: code,
    name,
    account_type: 'asset',
    normal_side: 'debit',
    is_synthetic: parentId === null,
    parent_id: parentId,
    is_active: true,
    created_at: '2026-01-01 00:00:00',
  }
}

/** Osnova firmy převedené na analytiky (reálný tvar: 221.x, 311.100, 518). */
const CHART: ChartAccount[] = [
  account(63, '221', 'Peněžní prostředky na účtech', null),
  account(200762, '221.100', 'Peněžní prostředky na účtech ČSOB', 63),
  account(3138611, '221.400', 'Peněžní prostředky na účtech CREDITAS', 63),
  account(73, '311', 'Pohledávky z obchodních vztahů (odběratelé)', null),
  account(3138629, '311.100', 'Pohledávky z obchodních vztahů', 73),
  account(79, '321', 'Závazky z obchodních vztahů (dodavatelé)', null),
  account(3138632, '321.100', 'Závazky z obchodních vztahů', 79),
  account(500, '518', 'Ostatní služby', null),
]

function tx(amount: number): BankTransaction {
  return {
    id: 1,
    statement_id: 1,
    posted_at: '2026-07-27',
    amount,
    currency: 'CZK',
    variable_symbol: null,
    constant_symbol: null,
    specific_symbol: null,
    counterparty_account: null,
    counterparty_bank: null,
    counterparty_name: 'GOPAY',
    description: 'platba',
    bank_ref: null,
    matched_invoice_id: null,
    match_status: 'unmatched',
    matched_at: null,
  } as BankTransaction
}

async function open(amount: number) {
  const wrapper = mount(PostTransactionModal, {
    props: { tx: tx(amount), currency: 'CZK' },
    global: { stubs: { AutomationBadge: true, ConfidenceLabel: true, RuleForm: true, RuleTemplatesModal: true } },
  })
  await flushPromises()
  return wrapper
}

const optionValues = (wrapper: ReturnType<typeof mount>, id: string): string[] =>
  wrapper.findAll(`#${id} option`).map(o => o.attributes('value') ?? '')

describe('PostTransactionModal — našeptávač účtů', () => {
  beforeEach(() => {
    m.listAccounts.mockResolvedValue(CHART)
    m.aiAvailability.mockResolvedValue({ available: false })
    m.postTransaction.mockReset()
  })

  it('protiúčet příchozí platby nabízí saldokonto i s analytikou', async () => {
    // Regrese: nabídka filtrovala celé 311/321/…, takže po napsání „311" nepřišlo NIC —
    // ani syntetika, ani 311.100 — a firma převedená na analytiky neměla co vybrat.
    const wrapper = await open(10)
    const credit = optionValues(wrapper, 'ptm-coa-credit')
    expect(credit).toContain('311.100')
    expect(credit).toContain('311')
    expect(credit).toContain('321.100')
  })

  it('analytika je v nabídce před svou syntetikou', async () => {
    const wrapper = await open(10)
    const credit = optionValues(wrapper, 'ptm-coa-credit')
    expect(credit.indexOf('311.100')).toBeLessThan(credit.indexOf('311'))
  })

  it('analytiku popisuje jejím názvem', async () => {
    const wrapper = await open(10)
    const label = wrapper.findAll('#ptm-coa-credit option')
      .find(o => o.attributes('value') === '311.100')?.text()
    expect(label).toContain('311.100')
    expect(label).toContain('Pohledávky z obchodních vztahů')
  })

  it('bankovní strana nabízí jen 221 a jeho analytiky', async () => {
    const wrapper = await open(10) // příchozí → banka je MD
    expect(optionValues(wrapper, 'ptm-coa-debit')).toEqual(['221.100', '221.400', '221'])
  })

  it('u odchozí platby jsou strany prohozené', async () => {
    const wrapper = await open(-10) // odchozí → banka je D
    expect(optionValues(wrapper, 'ptm-coa-credit')).toEqual(['221.100', '221.400', '221'])
    expect(optionValues(wrapper, 'ptm-coa-debit')).toContain('321.100')
  })

  it('rozúčtování nabízí plnou osnovu', async () => {
    const wrapper = await open(10)
    const split = optionValues(wrapper, 'ptm-coa-split')
    expect(split).toContain('311.100')
    expect(split).toContain('518')
  })

  it('saldokontní protiúčet lze odeslat, ale pravidlo z něj založit nejde', async () => {
    const wrapper = await open(10)
    await wrapper.find('#ptm-coa-credit')
    const inputs = wrapper.findAll('input[type="text"]')
    await inputs[1].setValue('311.100')
    await flushPromises()

    const checkbox = wrapper.find('input[type="checkbox"]')
    expect(checkbox.attributes('disabled')).toBeDefined()

    const submit = wrapper.findAll('button').find(b => b.text().includes('action_post'))!
    expect(submit.attributes('disabled')).toBeUndefined()
    await submit.trigger('click')
    await flushPromises()
    expect(m.postTransaction).toHaveBeenCalledWith(1, expect.objectContaining({
      debit_account_code: '221',
      credit_account_code: '311.100',
    }))
  })

  it('nebankovní účet na bankovní straně neprojde už na klientovi', async () => {
    const wrapper = await open(10)
    const inputs = wrapper.findAll('input[type="text"]')
    await inputs[0].setValue('518')   // MD = banka u příchozí platby
    await inputs[1].setValue('311.100')
    await flushPromises()

    const submit = wrapper.findAll('button').find(b => b.text().includes('action_post'))!
    expect(submit.attributes('disabled')).toBeDefined()
  })
})
