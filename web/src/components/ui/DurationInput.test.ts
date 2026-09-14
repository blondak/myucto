import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import DurationInput from './DurationInput.vue'
import { evalMath } from '@/directives/vMath'

const mounted: ReturnType<typeof mount>[] = []
function field(hours = 0.33, minutes: number | null = null) {
  const wrapper = mount(DurationInput, {
    attachTo: document.body,
    props: { modelValue: hours, durationMinutes: minutes },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', missingWarn: false, fallbackWarn: false })] },
  })
  mounted.push(wrapper)
  return wrapper
}
afterEach(() => { mounted.splice(0).forEach(wrapper => wrapper.unmount()) })

describe('DurationInput', () => {
  it.each([
    ['90/60', 1.5, 90],
    ['1+0,5', 1.5, 90],
    ['1/3', 0.33, null],
    ['.5', 0.5, 30],
    ['1.', 1, 60],
    ['(1)', 1, 60],
  ] as const)('preserves legacy expression %s', async (expression, hours, minutes) => {
    const wrapper = field()
    const input = wrapper.get('input')
    await input.trigger('focus')
    await input.setValue(expression)
    await input.trigger('blur')
    expect(input.element.validity.valid).toBe(true)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([hours])
    expect(wrapper.emitted('update:durationMinutes')?.at(-1)).toEqual([minutes])
  })
  it('rejects division by zero without changing the stored value', async () => {
    const wrapper = field()
    await wrapper.get('input').setValue('1/0')
    expect(wrapper.get('input').element.validity.valid).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
  it('does not convert or emit legacy hours on open or blur', async () => {
    const wrapper = field()
    expect(wrapper.get('input').element.value).toBe('0.33')
    await wrapper.get('input').trigger('focus')
    await wrapper.get('input').trigger('blur')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.emitted('update:durationMinutes')).toBeUndefined()
  })
  it('keeps the typed text while editing and emits exact minutes', async () => {
    const wrapper = field(1, 60)
    const input = wrapper.get('input')
    await input.trigger('focus')
    await input.setValue('1')
    await wrapper.setProps({ modelValue: 1, durationMinutes: 60 })
    await input.setValue('1:')
    expect(input.element.validity.valid).toBe(false)
    await input.setValue('1:20')
    expect(input.element.validity.valid).toBe(true)
    expect(input.element.value).toBe('1:20')
    expect(wrapper.emitted('update:durationMinutes')?.at(-1)).toEqual([80])
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([80 / 60])
  })
  it('blocks malformed time and negative work duration', async () => {
    const wrapper = field()
    await wrapper.get('input').setValue('1:99')
    expect(wrapper.get('input').element.validity.valid).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await wrapper.get('input').setValue('-1:00')
    expect(wrapper.get('input').element.validity.valid).toBe(false)
  })
  it('keeps default money expressions unchanged while allowing precise rates', () => {
    expect(evalMath('20000/60')).toBe(333.33)
    expect(evalMath('20000/60', 6)).toBe(333.333333)
  })
})
