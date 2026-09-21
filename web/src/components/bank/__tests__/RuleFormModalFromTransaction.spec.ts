import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  dryRunRule: vi.fn(),
  createRule: vi.fn(),
  toast: { success: vi.fn(), info: vi.fn(), warning: vi.fn(), error: vi.fn() },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => m.toast }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => undefined }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: () => Promise.resolve([]) } }))
vi.mock('@/api/bankPosting', () => ({
  bankPostingApi: {
    dryRunRule: (...args: unknown[]) => m.dryRunRule(...args),
    createRule: (...args: unknown[]) => m.createRule(...args),
    updateRule: vi.fn(),
  },
  bankPostingErrorMessage: () => 'err',
}))
vi.mock('../RuleForm.vue', () => ({
  default: { name: 'RuleForm', props: ['modelValue', 'accounts', 'mode', 'baseAmount', 'showDryRun', 'fromTransaction'], template: '<div />' },
}))

import RuleFormModal from '../RuleFormModal.vue'

const prefill = {
  name: 'PHONE CARE', direction: 'outgoing' as const, message_contains: 'PHONE CARE INSURANCE PRAHA',
  amount_min: 269, amount_max: 329, priority: 40,
  debit_account_code: '548', credit_account_code: '221',
}

async function mountModal(props: Record<string, unknown>) {
  const wrapper = mount(RuleFormModal, { props: { prefill, baseAmount: 299, ...props } })
  await flushPromises()
  await vi.advanceTimersByTimeAsync(600)
  await flushPromises()
  return wrapper
}

function saveButton(wrapper: Awaited<ReturnType<typeof mountModal>>) {
  return wrapper.findAll('button').find(b => b.text() === 'common.save')!
}

describe('RuleFormModal — pravidlo z pohybu', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    m.dryRunRule.mockReset().mockResolvedValue({ matched_count: 5, already_posted_count: 1, applicable_count: 3, shadowed_by_own_transfer: false, sample: [] })
    m.createRule.mockReset().mockResolvedValue({
      rule: { id: 7, debit_account_code: '548', credit_account_code: '221' },
      source_result: { status: 'posted', entry_id: 11 },
      applied: 3,
    })
    Object.values(m.toast).forEach(fn => fn.mockReset())
  })
  afterEach(() => { vi.useRealTimers() })

  it('nabídne další shody se zaškrtnutím a po uložení pravidlo hned použije', async () => {
    const wrapper = await mountModal({ sourceTransactionId: 99 })

    expect(m.dryRunRule).toHaveBeenCalledWith(expect.objectContaining({ source_transaction_id: 99, priority: 40 }))
    expect(wrapper.findComponent({ name: 'RuleForm' }).props('fromTransaction')).toBe(true)
    const checkbox = wrapper.find('[data-test="apply-matching"] input')
    expect(wrapper.find('[data-test="apply-matching"]').text()).toContain('"count":3')
    expect((checkbox.element as HTMLInputElement).checked).toBe(true)

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.createRule).toHaveBeenCalledWith(expect.objectContaining({ source_transaction_id: 99, apply_matching: true }))
    expect(m.createRule.mock.calls[0]![0]).not.toHaveProperty('backfill_suggestions')
    expect(m.toast.success).toHaveBeenCalledWith(expect.stringContaining('bank.posting.rule_created_posted'))
    const saved = wrapper.emitted('saved')![0]!
    expect(saved[1]).toEqual(expect.objectContaining({ source_result: { status: 'posted', entry_id: 11 } }))
  })

  it('odškrtnutá volba další pohyby nezaúčtuje', async () => {
    const wrapper = await mountModal({ sourceTransactionId: 99 })
    await wrapper.find('[data-test="apply-matching"] input').setValue(false)
    await saveButton(wrapper).trigger('click')
    await flushPromises()
    expect(m.createRule).toHaveBeenCalledWith(expect.objectContaining({ source_transaction_id: 99, apply_matching: false }))
  })

  it('už zaúčtovaný pohyb s jinou kontací ohlásí nabídku přeúčtování', async () => {
    m.createRule.mockResolvedValue({
      rule: { id: 7, debit_account_code: '548', credit_account_code: '221' },
      source_result: { status: 'already_posted', entry_id: 11, same_accounts: false },
    })
    const wrapper = await mountModal({ sourceTransactionId: 99 })
    await saveButton(wrapper).trigger('click')
    await flushPromises()
    expect(m.toast.info).toHaveBeenCalledWith('bank.posting.rule_created_repost_offer')
  })

  it('bez zdrojového pohybu zůstává původní chování (jen návrhy z historie)', async () => {
    m.createRule.mockResolvedValue({ rule: { id: 8 } })
    const wrapper = await mountModal({})
    expect(m.dryRunRule).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="apply-matching"]').exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'RuleForm' }).props('fromTransaction')).toBe(false)
    await saveButton(wrapper).trigger('click')
    await flushPromises()
    const body = m.createRule.mock.calls[0]![0]
    expect(body).toEqual(expect.objectContaining({ backfill_suggestions: false }))
    expect(body).not.toHaveProperty('source_transaction_id')
  })
})
