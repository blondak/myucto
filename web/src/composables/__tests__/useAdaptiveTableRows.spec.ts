import { mount } from '@vue/test-utils'
import { nextTick, ref } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import { useAdaptiveTableRows } from '../useAdaptiveTableRows'
import { adaptiveTableWidths } from '@/utils/adaptiveTableLayout'

afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); vi.unstubAllGlobals() })

it('fills balanced rows with equally wide columns', () => {
  const widths = adaptiveTableWidths([100, 150, 100, 150, 100, 150], 600)
  const rows: number[][] = [[]]
  for (const width of widths) {
    const current = rows[rows.length - 1]!
    if (current.reduce((sum, value) => sum + value, 0) + width > 600) rows.push([width])
    else current.push(width)
  }
  expect(rows.map(row => row.length)).toEqual([3, 3])
  expect(rows.every(row => row.reduce((sum, width) => sum + width, 0) > 599)).toBe(true)
  expect(widths.every(width => width === widths[0])).toBe(true)
})

it('distributes an odd number of columns without leaving a partially filled last row', () => {
  const widths = adaptiveTableWidths([200, 100, 300, 150, 200], 600)
  expect(widths).toHaveLength(5)
  expect(widths.slice(0, 3).every(width => width === widths[0])).toBe(true)
  expect(widths.slice(3).every(width => width === widths[3])).toBe(true)
  expect(widths.slice(0, 3).reduce((sum, width) => sum + width, 0)).toBeCloseTo(599.7)
  expect(widths.slice(3).reduce((sum, width) => sum + width, 0)).toBeCloseTo(599.8)
})

it('gives the description twice the width while keeping both rows filled', () => {
  const widths = adaptiveTableWidths(Array(11).fill(200), 1500, [1, 1, 2, 1, 1, 1])
  expect(widths[2]).toBeCloseTo(widths[0]! * 2)
  expect(widths.slice(0, 6).reduce((sum, width) => sum + width, 0)).toBeCloseTo(1499.4)
  expect(widths.slice(6).reduce((sum, width) => sum + width, 0)).toBeCloseTo(1499.5)
})

it.each(['', 'list-stacked-controls'])('adapts tables loaded after mounting and recalculates for a wider panel (%s)', async (controlsClass) => {
  vi.useFakeTimers()
  vi.stubGlobal('innerWidth', 1200)
  const box = document.createElement('div')
  let width = 450
  Object.defineProperty(box, 'clientWidth', { get: () => width })
  box.innerHTML = `<table class="${controlsClass}"><thead><tr><th></th><th>Number</th><th>Partner</th><th>Amount</th><th></th></tr></thead><tbody><tr><td></td><td>001</td><td>Synthetic partner</td><td>100</td><td></td></tr></tbody></table>`
  Object.defineProperty(box.querySelectorAll('tbody td')[3], 'offsetTop', { value: 63 })
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: 180 } as DOMRect)
  const targets = ref<HTMLElement[]>([])
  const wrapper = mount({ setup() { useAdaptiveTableRows(targets); return () => null } })
  targets.value.push(box)
  await nextTick()
  await vi.runOnlyPendingTimersAsync()
  const table = box.querySelector('table')!
  expect(table.classList.contains('adaptive-table')).toBe(true)
  expect(table.querySelectorAll('thead th')[1]!.getAttribute('style')).toBe(table.querySelectorAll('tbody td')[1]!.getAttribute('style'))
  expect(table.querySelectorAll('tbody td')[3]!.hasAttribute('data-adaptive-secondary')).toBe(true)
  const gutter = parseFloat(table.style.getPropertyValue('--adaptive-gutter'))
  const cells = Array.from(table.tBodies[0]!.rows[0]!.cells).slice(1, -1)
  expect(parseFloat(cells[0]!.style.flexBasis) * 2 + gutter).toBeLessThanOrEqual(width)
  if (controlsClass) expect(table.tBodies[0]!.rows[0]!.style.getPropertyValue('--adaptive-expand-top')).toBe('63px')
  width = 900
  window.dispatchEvent(new Event('resize'))
  await vi.runOnlyPendingTimersAsync()
  expect(table.classList.contains('adaptive-table')).toBe(false)
  expect((table.querySelectorAll('tbody td')[1] as HTMLElement).style.flexBasis).toBe('')
  expect(table.querySelector('[data-adaptive-secondary]')).toBeNull()
  expect(table.tBodies[0]!.rows[0]!.style.getPropertyValue('--adaptive-expand-top')).toBe('')
  expect(document.querySelector('table[aria-hidden="true"]')).toBeNull()
  wrapper.unmount()
})
