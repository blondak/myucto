import { describe, expect, it, vi } from 'vitest'
import {
  formatShortcut,
  normalizeShortcut,
  shortcutFromEvent,
  shortcutProblem,
} from '@/composables/useKeyboardShortcuts'

describe('keyboard shortcut helpers', () => {
  it('normalizes modifier order and cmd alias', () => {
    expect(normalizeShortcut('Shift + Alt + A')).toBe('alt+shift+a')
    expect(normalizeShortcut('Cmd + K')).toBe('ctrl+k')
  })

  it('formats a shortcut for display', () => {
    expect(formatShortcut('alt+shift+a')).toBe('Alt + Shift + A')
  })

  it('requires a safe modifier and rejects browser or fixed app shortcuts', () => {
    expect(shortcutProblem('a')).toBe('modifier_required')
    expect(shortcutProblem('shift+a')).toBe('modifier_required')
    expect(shortcutProblem('ctrl+n')).toBe('reserved')
    expect(shortcutProblem('alt+1')).toBeNull()
  })

  it('uses physical digit keys independently of the Czech keyboard character', () => {
    expect(shortcutFromEvent(new KeyboardEvent('keydown', {
      key: '+',
      code: 'Digit1',
      altKey: true,
    }))).toBe('alt+1')
    expect(shortcutFromEvent(new KeyboardEvent('keydown', {
      key: '1',
      code: 'Digit1',
      altKey: true,
      shiftKey: true,
    }))).toBe('alt+shift+1')
    expect(shortcutFromEvent(new KeyboardEvent('keydown', {
      key: 'ě',
      code: 'Digit2',
      altKey: true,
    }))).toBe('alt+2')
  })

  it('ignores AltGraph character input', () => {
    const event = new KeyboardEvent('keydown', {
      key: '\\',
      code: 'KeyQ',
      ctrlKey: true,
      altKey: true,
    })
    vi.spyOn(event, 'getModifierState').mockImplementation(modifier => modifier === 'AltGraph')
    expect(shortcutFromEvent(event)).toBe('')
  })
})
