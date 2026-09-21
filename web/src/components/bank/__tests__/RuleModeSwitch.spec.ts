import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  updateRule: vi.fn(),
  promoteRule: vi.fn(),
  demoteRule: vi.fn(),
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
    dryRunRule: vi.fn(),
    createRule: vi.fn(),
    updateRule: (...args: unknown[]) => m.updateRule(...args),
    promoteRule: (...args: unknown[]) => m.promoteRule(...args),
    demoteRule: (...args: unknown[]) => m.demoteRule(...args),
  },
  bankPostingErrorMessage: () => 'err',
}))
vi.mock('../RuleForm.vue', () => ({
  default: {
    name: 'RuleForm',
    props: ['modelValue', 'accounts', 'mode', 'baseAmount', 'showDryRun', 'fromTransaction', 'initialMode', 'promotionCandidate'],
    emits: ['update:modelValue'],
    template: '<div />',
  },
}))

import RuleFormModal from '../RuleFormModal.vue'
import type { BankPostingRule } from '@/api/bankPosting'

function rule(over: Partial<BankPostingRule> = {}): BankPostingRule {
  return {
    id: 5, supplier_id: 1, name: 'Synthetic rule', is_active: true, direction: 'outgoing',
    counterparty_account: '123', counterparty_bank: null, variable_symbol: null, message_contains: null,
    amount_min: null, amount_max: null, priority: 100, operation_type: null, system_template_key: null,
    auto_amount_cap: null, applies_currency: 'CZK', counterparty_prefix: null, approved_streak: 1,
    promotion_candidate: false, debit_account_code: '518', credit_account_code: '221', description: null,
    mode: 'suggest', hit_count: 1, rejected_streak: 0, last_hit_at: null, created_at: '', updated_at: '',
    ...over,
  }
}

async function mountEdit(r: BankPostingRule) {
  const wrapper = mount(RuleFormModal, { props: { rule: r } })
  await flushPromises()
  return wrapper
}

async function switchMode(wrapper: Awaited<ReturnType<typeof mountEdit>>, mode: 'suggest' | 'auto') {
  const form = wrapper.findComponent({ name: 'RuleForm' })
  form.vm.$emit('update:modelValue', { ...form.props('modelValue'), mode })
  await flushPromises()
  await wrapper.findAll('button').find(b => b.text() === 'common.save')!.trigger('click')
  await flushPromises()
}

describe('RuleFormModal: změna režimu', () => {
  const confirmSpy = vi.fn()
  beforeEach(() => {
    m.updateRule.mockReset().mockImplementation(async (id: number) => rule({ id }))
    m.promoteRule.mockReset().mockImplementation(async (id: number) => rule({ id, mode: 'auto' }))
    m.demoteRule.mockReset().mockImplementation(async (id: number) => rule({ id, mode: 'suggest' }))
    Object.values(m.toast).forEach(fn => fn.mockReset())
    confirmSpy.mockReset().mockReturnValue(true)
    vi.stubGlobal('confirm', confirmSpy)
  })
  afterEach(() => { vi.unstubAllGlobals() })

  it('předá formuláři uložený režim a stav kandidáta', async () => {
    const wrapper = await mountEdit(rule({ promotion_candidate: true }))
    const form = wrapper.findComponent({ name: 'RuleForm' })
    expect(form.props('initialMode')).toBe('suggest')
    expect(form.props('promotionCandidate')).toBe(true)
  })

  it('vynucené povýšení nekandidáta potvrdí, uloží pravidlo a pak zavolá promote', async () => {
    const wrapper = await mountEdit(rule())
    await switchMode(wrapper, 'auto')

    expect(confirmSpy).toHaveBeenCalledTimes(1)
    const message = String(confirmSpy.mock.calls[0]![0])
    expect(message).toContain('automation.rules.promote_forced_confirm')
    expect(message).toContain('automation.rules.promote_no_band_note')
    expect(m.updateRule).toHaveBeenCalledTimes(1)
    expect(m.updateRule.mock.calls[0]![1]).not.toHaveProperty('mode')
    expect(m.promoteRule).toHaveBeenCalledWith(5)
    expect(m.updateRule.mock.invocationCallOrder[0]!).toBeLessThan(m.promoteRule.mock.invocationCallOrder[0]!)
    expect(m.toast.success).toHaveBeenCalledWith('automation.rules.promoted')
    expect(wrapper.emitted('saved')![0]![0]).toEqual(expect.objectContaining({ mode: 'auto' }))
  })

  it('kandidát s rozsahem částky dostane běžné potvrzení', async () => {
    const wrapper = await mountEdit(rule({ promotion_candidate: true, amount_min: 100, amount_max: 200 }))
    await switchMode(wrapper, 'auto')
    const message = String(confirmSpy.mock.calls[0]![0])
    expect(message).toContain('automation.rules.promote_confirm')
    expect(message).not.toContain('promote_forced_confirm')
    expect(message).not.toContain('promote_no_band_note')
    expect(m.promoteRule).toHaveBeenCalledWith(5)
  })

  it('zrušené potvrzení nic neuloží', async () => {
    confirmSpy.mockReturnValue(false)
    const wrapper = await mountEdit(rule())
    await switchMode(wrapper, 'auto')
    expect(m.updateRule).not.toHaveBeenCalled()
    expect(m.promoteRule).not.toHaveBeenCalled()
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('přepnutí automatiky na návrh zavolá demote bez potvrzení', async () => {
    const wrapper = await mountEdit(rule({ mode: 'auto' }))
    await switchMode(wrapper, 'suggest')
    expect(confirmSpy).not.toHaveBeenCalled()
    expect(m.demoteRule).toHaveBeenCalledWith(5)
    expect(m.promoteRule).not.toHaveBeenCalled()
    expect(m.toast.success).toHaveBeenCalledWith('automation.rules.demoted')
  })

  it('beze změny režimu jen uloží pravidlo', async () => {
    const wrapper = await mountEdit(rule())
    await switchMode(wrapper, 'suggest')
    expect(m.updateRule).toHaveBeenCalledTimes(1)
    expect(m.promoteRule).not.toHaveBeenCalled()
    expect(m.demoteRule).not.toHaveBeenCalled()
    expect(m.toast.success).toHaveBeenCalledWith('common.saved')
  })
})
