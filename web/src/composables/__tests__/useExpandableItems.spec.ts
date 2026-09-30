import { mount } from '@vue/test-utils'
import { nextTick, ref } from 'vue'
import { expect, it, vi } from 'vitest'
import { useExpandableItems } from '../useExpandableItems'

function pending<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => { resolve = done })
  return { promise, resolve }
}

function setup(fetchItems: (id: number) => Promise<string[]>) {
  const scope = ref(1)
  let state!: ReturnType<typeof useExpandableItems<string>>
  const wrapper = mount({ setup() { state = useExpandableItems(fetchItems, () => scope.value); return () => null } })
  return { state, scope, wrapper }
}

it('loads on expansion and reuses items on reopening', async () => {
  const fetchItems = vi.fn().mockResolvedValue(['synthetic'])
  const { state, wrapper } = setup(fetchItems)
  expect(fetchItems).not.toHaveBeenCalled()
  await state.toggleItems(7)
  expect(state.expandedItems.value).toEqual(['synthetic'])
  await state.toggleItems(7)
  await state.toggleItems(7)
  expect(fetchItems).toHaveBeenCalledTimes(1)
  wrapper.unmount()
})

it('keeps the most recently opened invoice when requests finish out of order', async () => {
  const first = pending<string[]>(), second = pending<string[]>()
  const { state, wrapper } = setup(id => id === 1 ? first.promise : second.promise)
  const a = state.toggleItems(1), b = state.toggleItems(2)
  second.resolve(['second'])
  await b
  first.resolve(['first'])
  await a
  expect(state.expandedId.value).toBe(2)
  expect(state.expandedItems.value).toEqual(['second'])
  expect(state.expandedLoading.value).toBe(false)
  wrapper.unmount()
})

it('does not reopen an invoice collapsed while it was loading', async () => {
  const request = pending<string[]>()
  const { state, wrapper } = setup(() => request.promise)
  const opening = state.toggleItems(1)
  await state.toggleItems(1)
  request.resolve(['synthetic'])
  await opening
  expect(state.expandedId.value).toBeNull()
  expect(state.expandedItems.value).toBeNull()
  expect(state.expandedLoading.value).toBe(false)
  wrapper.unmount()
})

it('clears cached and in-flight items when the supplier changes', async () => {
  const first = pending<string[]>()
  const fetchItems = vi.fn().mockReturnValueOnce(first.promise).mockResolvedValue(['new supplier'])
  const { state, scope, wrapper } = setup(fetchItems)
  const opening = state.toggleItems(1)
  scope.value = 2
  await nextTick()
  first.resolve(['old supplier'])
  await opening
  expect(state.expandedItems.value).toBeNull()
  await state.toggleItems(1)
  expect(fetchItems).toHaveBeenCalledTimes(2)
  expect(state.expandedItems.value).toEqual(['new supplier'])
  state.clearExpandedItems()
  await state.toggleItems(1)
  expect(fetchItems).toHaveBeenCalledTimes(3)
  wrapper.unmount()
})

it('retries failed requests without keeping a rejected promise in the cache', async () => {
  const fetchItems = vi.fn().mockRejectedValueOnce(new Error('synthetic failure')).mockResolvedValue(['retry'])
  const { state, wrapper } = setup(fetchItems)
  await state.toggleItems(1)
  expect(state.expandedId.value).toBeNull()
  await state.toggleItems(1)
  expect(state.expandedItems.value).toEqual(['retry'])
  expect(fetchItems).toHaveBeenCalledTimes(2)
  wrapper.unmount()
})
