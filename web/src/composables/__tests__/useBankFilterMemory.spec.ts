import { mount } from '@vue/test-utils'
import { reactive, ref } from 'vue'
import { beforeEach, expect, it } from 'vitest'
import { useBankFilterMemory } from '../useBankFilterMemory'

beforeEach(() => sessionStorage.clear())

it('restores filters on remount and isolates them by supplier', () => {
  const supplier = ref(1)
  function open() {
    const state = reactive({ search: '', posting: '', sort: '' })
    const wrapper = mount({ setup() {
      useBankFilterMemory(() => `${supplier.value}:all`, () => ({ ...state }), value => {
        Object.assign(state, { search: '', posting: '', sort: '' }, value)
      })
      return () => null
    } })
    return { wrapper, state }
  }
  let page = open()
  Object.assign(page.state, { search: '1 234,50', posting: 'posted', sort: 'amount:asc' })
  page.wrapper.unmount()
  page = open()
  expect(page.state).toEqual({ search: '1 234,50', posting: 'posted', sort: 'amount:asc' })
  supplier.value = 2
  expect(page.state).toEqual({ search: '', posting: '', sort: '' })
  page.state.search = 'supplier two'
  supplier.value = 1
  expect(page.state.search).toBe('1 234,50')
  supplier.value = 2
  expect(page.state.search).toBe('supplier two')
  page.wrapper.unmount()
})

it('recovers from damaged stored filters', () => {
  sessionStorage.setItem('myinvoice.bank.filters.1:all', 'invalid json')
  const state = ref('initial')
  const wrapper = mount({ setup() {
    useBankFilterMemory(() => '1:all', () => ({ search: state.value }), value => { state.value = value.search ?? '' })
    return () => null
  } })
  expect(state.value).toBe('')
  wrapper.unmount()
})
