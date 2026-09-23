import { describe, it, expect, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import type { DimensionType } from '@/api/dimensions'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (n: number) => String(n) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({ typeById: { value: new Map([[5, { id: 5, name: 'Středisko' }]]) } }),
}))

import DimensionSplitEditor from '@/components/dimensions/DimensionSplitEditor.vue'

const ModalStub = defineComponent({
  setup(_, { slots }) {
    return () => h('div', [slots.default?.(), slots.footer?.()])
  },
})
const PickerStub = defineComponent({
  props: ['modelValue'],
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () => h('input', {
      class: 'picker',
      value: props.modelValue ?? '',
      onInput: (e: Event) => emit('update:modelValue', Number((e.target as HTMLInputElement).value) || null),
    })
  },
})

const TYPES = [{ id: 5, name: 'Středisko' }] as DimensionType[]

function mountEditor(props: Record<string, unknown> = {}) {
  return mount(DimensionSplitEditor, {
    props: { types: TYPES, ...props },
    global: { stubs: { Modal: ModalStub, DimensionPicker: PickerStub } },
  })
}

describe('DimensionSplitEditor', () => {
  it('ukládá procenta jako podíl a pustí jen součet 100 %', async () => {
    const w = mountEditor()
    const pickers = w.findAll('input.picker')
    await pickers[0].setValue('11')
    await pickers[1].setValue('12')
    const percents = w.findAll('[data-test="split-percent"]')
    await percents[0].setValue('60')
    await percents[1].setValue('30')
    expect(w.find('[data-test="split-save"]').attributes('disabled')).toBeDefined()
    await percents[1].setValue('40')
    expect(w.find('[data-test="split-save"]').attributes('disabled')).toBeUndefined()
    await w.find('[data-test="split-save"]').trigger('click')
    expect(w.emitted('save')?.[0]).toEqual([5, [{ value_id: 11, share: 0.6 }, { value_id: 12, share: 0.4 }]])
  })

  it('rozpad částkou přepočte na podíl základu řádku', async () => {
    const w = mountEditor({ base: 1000 })
    await w.find('[data-test="split-mode-amount"]').trigger('click')
    const pickers = w.findAll('input.picker')
    await pickers[0].setValue('11')
    await pickers[1].setValue('12')
    const amounts = w.findAll('[data-test="split-amount"]')
    await amounts[0].setValue('333.33')
    await amounts[1].setValue('666.67')
    await w.find('[data-test="split-save"]').trigger('click')
    expect(w.emitted('save')?.[0]).toEqual([5, [{ value_id: 11, share: 0.33333 }, { value_id: 12, share: 0.66667 }]])
  })

  it('odmítne opakovanou hodnotu a umí rozpad zrušit', async () => {
    const w = mountEditor({ current: { 5: [{ value_id: 11, share: 0.5 }, { value_id: 12, share: 0.5 }] } })
    await w.findAll('input.picker')[1].setValue('11')
    expect(w.find('[data-test="split-save"]').attributes('disabled')).toBeDefined()
    await w.find('[data-test="split-clear"]').trigger('click')
    expect(w.emitted('save')?.[0]).toEqual([5, null])
  })
})
