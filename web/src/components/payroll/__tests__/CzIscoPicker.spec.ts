import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  searchCzIsco: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { searchCzIsco: m.searchCzIsco },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string) => key,
  }),
}))

import CzIscoPicker from '@/components/payroll/CzIscoPicker.vue'

// SearchableSelect je generická komponenta — hledáme ji podle jména, protože
// getComponent(Component) na generice ztratí typ wrapperu.
function select(wrapper: VueWrapper) {
  return wrapper.findComponent({ name: 'SearchableSelect' })
}

const CODEBOOK = {
  package_key: 'cz-isco-2026-02-01-v1',
  manifest_sha256: 'x',
  classification_version: '2026-02-01',
  effective_from: '2026-02-01',
  legal_basis: 'Sdělení ČSÚ č. 5/2026 Sb.',
  licence: 'CC BY 4.0',
  licence_url: 'https://csu.gov.cz/',
  source_url: 'https://csu.gov.cz/',
  entry_count: 1992,
}

const ACCOUNTANT = {
  code: '43111',
  label: 'Účetní všeobecní',
  level: 5,
  parent_code: '4311',
  parent_label: 'Úředníci v oblasti účetnictví',
}

function result(items: typeof ACCOUNTANT[]) {
  return { items, codebook: CODEBOOK }
}

describe('CzIscoPicker', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.searchCzIsco.mockResolvedValue(result([]))
  })

  it('hledá na serveru a nabídne kód i název', async () => {
    const wrapper = mount(CzIscoPicker, { props: { modelValue: null } })
    await flushPromises()

    m.searchCzIsco.mockResolvedValue(result([ACCOUNTANT]))
    await select(wrapper).vm.$emit('search', 'ucetni')
    await flushPromises()

    expect(m.searchCzIsco).toHaveBeenCalledWith('ucetni', 20)
    const options = select(wrapper).props('options')
    expect(options).toEqual([{
      value: '43111',
      label: '43111 — Účetní všeobecní',
      secondary: 'Úředníci v oblasti účetnictví',
    }])
  })

  it('krátký dotaz vůbec neposílá a vysvětlí proč', async () => {
    const wrapper = mount(CzIscoPicker, { props: { modelValue: null } })
    await flushPromises()
    m.searchCzIsco.mockClear()

    await select(wrapper).vm.$emit('search', 'u')
    await flushPromises()

    expect(m.searchCzIsco).not.toHaveBeenCalled()
    expect(select(wrapper).props('noResultsLabel'))
      .toBe('payroll.people.cz_isco.min_chars')
  })

  it('výběr položky vydá kód nahoru', async () => {
    const wrapper = mount(CzIscoPicker, { props: { modelValue: null } })
    m.searchCzIsco.mockResolvedValue(result([ACCOUNTANT]))
    await select(wrapper).vm.$emit('search', 'ucetni')
    await flushPromises()

    await select(wrapper).vm.$emit('update:modelValue', '43111')
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['43111'])
  })

  it('k uloženému kódu dotáhne popisek', async () => {
    m.searchCzIsco.mockResolvedValue(result([ACCOUNTANT]))
    const wrapper = mount(CzIscoPicker, { props: { modelValue: '43111' } })
    await flushPromises()

    expect(select(wrapper).props('selectedOption')).toEqual({
      value: '43111',
      label: '43111 — Účetní všeobecní',
      secondary: 'Úředníci v oblasti účetnictví',
    })
  })

  it('u kódu mimo číselník hodnotu nezahodí, jen ji označí', async () => {
    m.searchCzIsco.mockResolvedValue(result([]))
    const wrapper = mount(CzIscoPicker, { props: { modelValue: '99999' } })
    await flushPromises()

    expect(select(wrapper).props('selectedOption')).toEqual({
      value: '99999',
      label: '99999',
      secondary: 'payroll.people.cz_isco.unknown_code',
    })
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('prázdný výsledek říká „nic neodpovídá" a netváří se jako chyba', async () => {
    const wrapper = mount(CzIscoPicker, { props: { modelValue: null } })
    await flushPromises()

    m.searchCzIsco.mockResolvedValue(result([]))
    await select(wrapper).vm.$emit('search', 'xyzzy')
    await flushPromises()

    expect(select(wrapper).props('noResultsLabel'))
      .toBe('payroll.people.cz_isco.no_results')
    expect(wrapper.find('[data-testid="cz-isco-search-failed"]').exists()).toBe(false)
  })

  it('selhání dotazu odliší od prázdného výsledku a nabídne ruční zápis', async () => {
    const wrapper = mount(CzIscoPicker, { props: { modelValue: null } })
    await flushPromises()

    m.searchCzIsco.mockRejectedValue(new Error('offline'))
    await select(wrapper).vm.$emit('search', 'ucetni')
    await flushPromises()

    expect(select(wrapper).props('noResultsLabel'))
      .toBe('payroll.people.cz_isco.search_failed')
    const alert = wrapper.get('[data-testid="cz-isco-search-failed"]')
    expect(alert.attributes('role')).toBe('alert')
    expect(alert.text()).toContain('payroll.people.cz_isco.search_failed_hint')

    await alert.get('input').setValue('24111')
    await alert.get('input').trigger('change')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['24111'])
  })

  it('pomalejší starší odpověď nepřepíše novější výsledek', async () => {
    const wrapper = mount(CzIscoPicker, { props: { modelValue: null } })
    await flushPromises()

    const slow: { release: () => void } = { release: () => {} }
    m.searchCzIsco.mockImplementationOnce(() => new Promise(resolve => {
      slow.release = () => resolve(result([ACCOUNTANT]))
    }))
    void select(wrapper).vm.$emit('search', 'ucetni')

    m.searchCzIsco.mockResolvedValue(result([]))
    await select(wrapper).vm.$emit('search', 'zednik')
    await flushPromises()

    slow.release()
    await flushPromises()

    expect(select(wrapper).props('options')).toEqual([])
  })
})
