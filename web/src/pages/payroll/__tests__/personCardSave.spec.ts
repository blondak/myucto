import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { createPersonCardSaveRegistry } from '@/pages/payroll/personCardSave'

function section(label: string, result: boolean, dirty = true) {
  const state = ref(dirty)
  return {
    state,
    spec: {
      label: () => label,
      dirty: () => state.value,
      save: vi.fn(async () => {
        if (result) state.value = false
        return result
      }),
      discard: vi.fn(() => { state.value = false }),
      focus: vi.fn(),
    },
  }
}

describe('personCardSave', () => {
  it('ukládá jen rozdělané sekce, postupně, a u první chyby se zastaví', async () => {
    const registry = createPersonCardSaveRegistry()
    const clean = section('clean', true, false)
    const first = section('first', true)
    const broken = section('broken', false)
    const last = section('last', true)
    for (const item of [clean, first, broken, last]) registry.register(item.spec)

    expect(registry.dirtySections.value.map(item => item.label())).toEqual(['first', 'broken', 'last'])
    expect(await registry.saveAll()).toBe(false)

    expect(clean.spec.save).not.toHaveBeenCalled()
    expect(first.spec.save).toHaveBeenCalledTimes(1)
    expect(broken.spec.focus).toHaveBeenCalledTimes(1)
    expect(last.spec.save).not.toHaveBeenCalled()
    expect(registry.failedSection.value?.label()).toBe('broken')
    expect(registry.dirtySections.value.map(item => item.label())).toEqual(['broken', 'last'])
  })

  it('zahodí všechny rozdělané sekce a odhlášená sekce se už nepočítá', () => {
    const registry = createPersonCardSaveRegistry()
    const a = section('a', true)
    const b = section('b', true)
    registry.register({ ...a.spec, key: 'profile' })
    const unregister = registry.register(b.spec)

    expect(registry.isDirty('profile')).toBe(true)
    unregister()
    expect(registry.dirtySections.value).toHaveLength(1)
    registry.discardAll()
    expect(a.spec.discard).toHaveBeenCalledTimes(1)
    expect(b.spec.discard).not.toHaveBeenCalled()
    expect(registry.hasChanges.value).toBe(false)
  })
})
