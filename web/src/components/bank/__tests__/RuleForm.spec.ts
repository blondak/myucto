import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { ChartAccount } from '@/api/accounting'
import type { BankPostingRulePayload } from '@/api/bankPosting'
import RuleForm from '../RuleForm.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: String, formatDate: String }))
vi.mock('@/api/settings', () => ({ settingsApi: { listCurrencies: async () => [] } }))

const accounts = ['221.100', '518.100', '321.100'].map((code, index) => ({
  id: index + 1, account_code: code, name: 'Synthetic account', is_active: true, parent_id: null,
})) as ChartAccount[]

describe('RuleForm account selection', () => {
  it.each(['incoming', 'outgoing'] as const)('filters the bank side and excludes saldo on the other side for %s', async direction => {
    const wrapper = mount(RuleForm, {
      props: { modelValue: { name: 'Synthetic rule', direction, debit_account_code: '', credit_account_code: '' } as BankPostingRulePayload, accounts, mode: 'create' },
      global: { stubs: { ChartAccountSelect: { name: 'ChartAccountSelect', props: ['modelValue', 'accounts'], emits: ['update:modelValue'], template: '<div />' } } },
    })
    await flushPromises()
    const pickers = wrapper.findAllComponents({ name: 'ChartAccountSelect' })
    const bankIndex = direction === 'incoming' ? 0 : 1
    expect(pickers[bankIndex]!.props('accounts').map((a: ChartAccount) => a.account_code)).toEqual(['221.100'])
    expect(pickers[1 - bankIndex]!.props('accounts').map((a: ChartAccount) => a.account_code)).not.toContain('321.100')
    pickers[0]!.vm.$emit('update:modelValue', direction === 'incoming' ? '221.100' : '518.100')
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toEqual(expect.objectContaining({ debit_account_code: direction === 'incoming' ? '221.100' : '518.100' }))
    wrapper.unmount()
  })
})

describe('RuleForm režim při editaci', () => {
  const stubs = { ChartAccountSelect: { name: 'ChartAccountSelect', props: ['modelValue', 'accounts'], template: '<div />' } }
  const base = { name: 'Synthetic rule', direction: 'outgoing', debit_account_code: '518.100', credit_account_code: '221.100', is_active: true, mode: 'suggest' } as BankPostingRulePayload

  it('nabídne výběr režimu a u nekandidáta upozorní na vynucené povýšení', async () => {
    const wrapper = mount(RuleForm, { props: { modelValue: base, accounts, mode: 'edit', initialMode: 'suggest', promotionCandidate: false }, global: { stubs } })
    await flushPromises()
    const select = wrapper.find('[data-test="rule-mode-select"]')
    expect(select.exists()).toBe(true)
    expect(wrapper.find('[data-test="rule-mode-forced"]').exists()).toBe(false)
    await select.setValue('auto')
    expect(wrapper.find('[data-test="rule-mode-forced"]').exists()).toBe(true)
    expect(wrapper.emitted('update:modelValue')!.at(-1)![0]).toEqual(expect.objectContaining({ mode: 'auto' }))
    wrapper.unmount()
  })

  it('u kandidáta vynucené povýšení nehlásí a neaktivní pravidlo nejde přepnout na automatiku', async () => {
    const candidate = mount(RuleForm, { props: { modelValue: base, accounts, mode: 'edit', initialMode: 'suggest', promotionCandidate: true }, global: { stubs } })
    await candidate.find('[data-test="rule-mode-select"]').setValue('auto')
    expect(candidate.find('[data-test="rule-mode-forced"]').exists()).toBe(false)
    candidate.unmount()

    const inactive = mount(RuleForm, { props: { modelValue: { ...base, is_active: false }, accounts, mode: 'edit', initialMode: 'suggest' }, global: { stubs } })
    expect((inactive.find('[data-test="rule-mode-select"]').element as HTMLSelectElement).disabled).toBe(true)
    inactive.unmount()
  })
})
