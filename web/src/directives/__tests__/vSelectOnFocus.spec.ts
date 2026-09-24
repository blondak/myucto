import { describe, expect, it } from 'vitest'
import { selectOnFocus } from '../vSelectOnFocus'

function field(value: string): HTMLInputElement {
  const el = document.createElement('input')
  el.value = value
  document.body.appendChild(el)
  selectOnFocus(el)
  return el
}

describe('selectOnFocus', () => {
  it('označí celou hodnotu při vstupu do pole', () => {
    const el = field('1')
    el.focus()
    expect([el.selectionStart, el.selectionEnd]).toEqual([0, 1])
  })

  it('klik, který pole aktivuje, výběr nezruší', () => {
    const el = field('125')
    el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    el.focus()
    const up = new MouseEvent('mouseup', { bubbles: true, cancelable: true })
    el.dispatchEvent(up)
    expect(up.defaultPrevented).toBe(true)
  })

  it('klik do už aktivního pole kurzor posadí normálně', () => {
    const el = field('125')
    el.focus()
    el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))
    const up = new MouseEvent('mouseup', { bubbles: true, cancelable: true })
    el.dispatchEvent(up)
    expect(up.defaultPrevented).toBe(false)
  })
})
