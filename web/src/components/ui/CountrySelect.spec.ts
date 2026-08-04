import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  loadCountries: vi.fn(),
}))

vi.mock('@/composables/useCountries', () => ({
  loadCountries: m.loadCountries,
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string) => key,
  }),
}))

import CountrySelect from '@/components/ui/CountrySelect.vue'

describe('CountrySelect', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('použije společný vyhledávací číselník států', async () => {
    m.loadCountries.mockResolvedValue([{
      id: 1,
      iso2: 'CZ',
      iso3: 'CZE',
      name_cs: 'Česko',
      name_en: 'Czechia',
      is_eu: true,
    }])
    const wrapper = mount(CountrySelect, {
      props: { modelValue: 'CZ', accent: 'payroll', required: true },
    })
    await flushPromises()

    const input = wrapper.get<HTMLInputElement>('input[role="combobox"]')
    expect(input.element.value).toBe('Česko')
    expect(input.classes()).toContain('focus:border-payroll-500')
    expect(input.attributes('required')).toBeDefined()
  })

  it('při výpadku číselníku dovolí ručně zadat ISO kód', async () => {
    m.loadCountries.mockRejectedValue(new Error('offline'))
    const wrapper = mount(CountrySelect, {
      props: { modelValue: '' },
    })
    await flushPromises()

    expect(wrapper.find('input[role="combobox"]').exists()).toBe(false)
    const input = wrapper.get('input[maxlength="2"]')
    await input.setValue('sk')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['SK'])
    expect(wrapper.get('[role="alert"]').text()).toContain(
      'common.country_manual_fallback',
    )
  })
})
