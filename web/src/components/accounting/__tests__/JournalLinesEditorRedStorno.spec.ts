import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import JournalLinesEditor from '../JournalLinesEditor.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))

describe('rozúčtování červeného storna', () => {
  it('nový řádek čistě červeného zápisu zachová účetní znaménko', async () => {
    const wrapper = mount(JournalLinesEditor, { props: { accounts: [], modelValue: [
      { account_code: '518', side: 'debit', amount: 100, is_red_storno: true },
      { account_code: '321', side: 'credit', amount: 100, is_red_storno: true },
    ] } })
    await wrapper.findAll('button').at(-1)!.trigger('click')
    const rows = wrapper.emitted('update:modelValue')![0]![0] as Array<{ is_red_storno?: boolean }>
    expect(rows.map(row => row.is_red_storno)).toEqual([true, true, true])
    wrapper.unmount()
  })

  it('smíšený zápis přidává běžný řádek', async () => {
    const wrapper = mount(JournalLinesEditor, { props: { accounts: [], modelValue: [
      { account_code: '518', side: 'debit', amount: 100, is_red_storno: true },
      { account_code: '321', side: 'credit', amount: 100 },
    ] } })
    await wrapper.findAll('button').at(-1)!.trigger('click')
    const rows = wrapper.emitted('update:modelValue')![0]![0] as Array<{ is_red_storno?: boolean }>
    expect(rows.at(-1)?.is_red_storno ?? false).toBe(false)
    wrapper.unmount()
  })
})
