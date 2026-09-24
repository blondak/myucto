import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { DimensionOverview } from '@/api/dimensions'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  supplier: { currentSupplierId: 1, currentSupplier: { id: 1, dimensions_enabled: true } as { id: number; dimensions_enabled: boolean } },
}))

vi.mock('@/api/dimensions', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/dimensions')>()),
  dimensionsApi: { overview: m.overview },
}))

vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => m.supplier }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true, hasCommercialFeatures: true }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import DimensionPicker from '@/components/dimensions/DimensionPicker.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'
import { useDimensions } from '@/composables/useDimensions'

function overview(): DimensionOverview {
  const base = { supplier_id: 1, supplier_group_id: null, level: 'company' as const, responsible_user_id: null, responsible_note: null,
    car_id: null, project_id: null, cost_center_id: null, note: null, sort_order: 0 }
  return {
    enabled: true,
    group: null,
    types: [
      { id: 7, supplier_id: 1, supplier_group_id: null, level: 'company', code: 'lokalita', name: 'Lokalita', kind: 'location', is_active: true, show_on_documents: true, sort_order: 10 },
      { id: 8, supplier_id: 1, supplier_group_id: null, level: 'company', code: 'interni', name: 'Interní', kind: 'custom', is_active: true, show_on_documents: false, sort_order: 20 },
    ],
    values: [
      { ...base, id: 3, type_id: 7, parent_id: 1, code: 'BRNO', name: 'Brno', is_active: true },
      { ...base, id: 1, type_id: 7, parent_id: null, code: 'CZ', name: 'Česko', is_active: true },
      { ...base, id: 2, type_id: 7, parent_id: 1, code: 'OLD', name: 'Zrušená pobočka', is_active: false },
      { ...base, id: 4, type_id: 7, parent_id: null, code: 'SK', name: 'Slovensko', is_active: true },
    ],
  }
}

describe('DimensionPicker', () => {
  beforeEach(async () => {
    m.overview.mockReset()
    m.overview.mockResolvedValue(overview())
    m.supplier.currentSupplier.dimensions_enabled = true
    await useDimensions().reload()
  })

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('nabízí hodnoty ve stromovém pořadí s cestou nadřízených a bez uzavřených', async () => {
    const wrapper = mount(DimensionPicker, { props: { typeId: 7, modelValue: null }, attachTo: document.body })
    await wrapper.get('input[role="combobox"]').trigger('focus')
    const options = wrapper.findAll('[role="option"]').map(o => o.text())
    expect(options).toEqual(['CZ – Česko', 'BRNO – BrnoČesko', 'SK – Slovensko'])
    wrapper.unmount()
  })

  it('uzavřenou hodnotu ukáže jen tehdy, když už je vybraná', async () => {
    const wrapper = mount(DimensionPicker, { props: { typeId: 7, modelValue: 2 }, attachTo: document.body })
    expect((wrapper.get('input[role="combobox"]').element as HTMLInputElement).value).toBe('OLD – Zrušená pobočka (dimensions.closed)')
    wrapper.unmount()
  })

  it('hledání podle nadřízené najde i podřízenou hodnotu a výběr vrací id', async () => {
    const wrapper = mount(DimensionPicker, { props: { typeId: 7, modelValue: null }, attachTo: document.body })
    const input = wrapper.get('input[role="combobox"]')
    await input.trigger('focus')
    await input.setValue('česko')
    const options = wrapper.findAll('[role="option"]')
    expect(options.map(o => o.text())).toEqual(['CZ – Česko', 'BRNO – BrnoČesko'])
    await options[1].trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([3])
    wrapper.unmount()
  })

  it('DimensionFields ukáže jen typy nabízené na dokladech a mění mapu typ → hodnota', async () => {
    const wrapper = mount(DimensionFields, { props: { modelValue: { 7: null } }, attachTo: document.body })
    await flushPromises()
    expect(wrapper.findAll('[data-test="dimension-picker"]')).toHaveLength(1)
    const input = wrapper.get('input[role="combobox"]')
    await input.trigger('focus')
    await wrapper.findAll('[role="option"]')[2].trigger('click')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([{ 7: 4 }])
    wrapper.unmount()
  })

  it('při vypnutých dimenzích se nevykreslí nic a číselník se nenačítá', async () => {
    m.supplier.currentSupplier.dimensions_enabled = false
    m.overview.mockClear()
    const wrapper = mount(DimensionFields, { props: { modelValue: {} } })
    await flushPromises()
    expect(wrapper.find('[data-test="dimension-fields"]').exists()).toBe(false)
    expect(m.overview).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
