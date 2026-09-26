import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'

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

  /**
   * Q15-32: „Česko" + Enter nad už vybraným Českem vybralo první zemi
   * nabídky (Afghánistán), protože přepsaný text ukáže celou nabídku a
   * zvýraznění skočilo na první řádek. Enter bez shody nesmí odeslat
   * okolní formulář.
   */
  describe('Enter', () => {
    const countries = [
      { id: 9, iso2: 'AF', iso3: 'AFG', name_cs: 'Afghánistán', name_en: 'Afghanistan', is_eu: false },
      { id: 1, iso2: 'CZ', iso3: 'CZE', name_cs: 'Česká republika', name_en: 'Czech Republic', is_eu: true },
      { id: 2, iso2: 'SK', iso3: 'SVK', name_cs: 'Slovensko', name_en: 'Slovakia', is_eu: true },
    ]

    it('po přepsání na název vybrané země ponechá vybranou zemi', async () => {
      m.loadCountries.mockResolvedValue(countries)
      const wrapper = mount(CountrySelect, { props: { modelValue: 'CZ' }, attachTo: document.body })
      await flushPromises()
      const input = wrapper.get<HTMLInputElement>('input[role="combobox"]')

      await input.trigger('focus')
      await input.setValue('Česko')
      await input.trigger('keydown', { key: 'Enter' })

      expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['CZ'])
      expect(input.element.value).toBe('Česko')
      wrapper.unmount()
    })

    it('vybere jedinou nalezenou zemi', async () => {
      m.loadCountries.mockResolvedValue(countries)
      const wrapper = mount(CountrySelect, { props: { modelValue: '' }, attachTo: document.body })
      await flushPromises()
      const input = wrapper.get<HTMLInputElement>('input[role="combobox"]')

      await input.trigger('focus')
      await input.setValue('cesko')
      await input.trigger('keydown', { key: 'Enter' })

      expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['CZ'])
      expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('bez shody nic nevybere, nabídku zavře a formulář neodešle', async () => {
      m.loadCountries.mockResolvedValue(countries)
      const submitted = vi.fn()
      const Host = defineComponent(() => {
        const code = ref('')
        return () => h('form', { onSubmit: (e: Event) => { e.preventDefault(); submitted() } }, [
          h(CountrySelect, { 'modelValue': code.value, 'onUpdate:modelValue': (v: string) => { code.value = v } }),
          h('button', { type: 'submit' }, 'ok'),
        ])
      })
      const wrapper = mount(Host, { attachTo: document.body })
      await flushPromises()
      const input = wrapper.get<HTMLInputElement>('input[role="combobox"]')

      await input.trigger('focus')
      await input.setValue('xyz')
      const event = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true, bubbles: true })
      input.element.dispatchEvent(event)
      await flushPromises()

      expect(event.defaultPrevented).toBe(true)
      expect(submitted).not.toHaveBeenCalled()
      expect(wrapper.find('[role="listbox"]').exists()).toBe(false)
      expect(input.element.value).toBe('')
      wrapper.unmount()
    })
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
