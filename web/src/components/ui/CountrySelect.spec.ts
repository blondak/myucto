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

  /**
   * UI-8: číselník má u Česka z první instalace „Česká republika", jinde
   * v aplikaci je „Česko". Nabídka ukazuje krátký název a hledá bez
   * diakritiky podle názvu, formálního názvu i kódu.
   */
  it('najde Česko pod krátkým i formálním názvem, bez diakritiky i podle kódu', async () => {
    m.loadCountries.mockResolvedValue([
      { id: 1, iso2: 'CZ', iso3: 'CZE', name_cs: 'Česká republika', name_en: 'Czech Republic', is_eu: true },
      { id: 2, iso2: 'SK', iso3: 'SVK', name_cs: 'Slovensko', name_en: 'Slovakia', is_eu: true },
      { id: 3, iso2: 'DE', iso3: 'DEU', name_cs: 'Německo', name_en: 'Germany', is_eu: true },
    ])
    const wrapper = mount(CountrySelect, { props: { modelValue: '' } })
    await flushPromises()
    const input = wrapper.get<HTMLInputElement>('input[role="combobox"]')
    const optionTexts = () => wrapper.findAll('[role="option"]').map(option => option.text())

    for (const query of ['Česko', 'cesko', 'republika', 'CZE']) {
      await input.setValue(query)
      await input.trigger('focus')
      expect(optionTexts().some(text => text.includes('Česko')), query).toBe(true)
      expect(optionTexts().some(text => text.includes('Německo')), query).toBe(false)
    }

    await input.setValue('nemecko')
    expect(optionTexts().some(text => text.includes('Německo'))).toBe(true)
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
