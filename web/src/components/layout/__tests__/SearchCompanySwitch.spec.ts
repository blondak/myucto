import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

// Hledání (Alt+Q) i paleta příkazů (Ctrl+K) nabízí při víc firmách i přepnutí firmy.

const m = vi.hoisted(() => ({
  switchTo: vi.fn(),
  hasMultiple: true,
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/search', () => ({ searchApi: { query: vi.fn(async (q: string) => ({ q, clients: [], invoices: [], purchase_invoices: [] })) } }))
vi.mock('@/composables/useWorkspaceNavigation', () => ({ useWorkspaceNavigation: () => ({ navigate: vi.fn(), openExternal: vi.fn() }) }))
vi.mock('@/composables/useTips', () => ({ markTipUsed: vi.fn() }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: vi.fn() }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    currentSupplierId: 1,
    get hasMultiple() { return m.hasMultiple },
    availableSuppliers: [
      { id: 1, company_name: 'Alfa Stavby s.r.o.', ic: '11111111' },
      { id: 2, company_name: 'Beta Účetní s.r.o.', ic: '22222222' },
    ],
  }),
}))
vi.mock('@/composables/useSupplierSwitch', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/composables/useSupplierSwitch')>()),
  useSupplierSwitch: () => ({ switching: { value: false }, switchTo: m.switchTo }),
}))

import GlobalSearch from '../GlobalSearch.vue'
import CommandPalette from '../CommandPalette.vue'

beforeEach(() => {
  vi.clearAllMocks()
  m.hasMultiple = true
})

describe('Alt+Q — hledání', () => {
  it('nabídne firmu k přepnutí a volbou ji přepne', async () => {
    const w = mount(GlobalSearch, { props: { menuItems: [] } })
    await w.find('input').setValue('ucetni')
    await flushPromises()
    expect(w.text()).toContain('search.group_companies')
    const option = w.findAll('button').find(b => b.text().includes('Beta Účetní s.r.o.'))
    expect(option).toBeTruthy()
    await option!.trigger('mousedown')
    expect(m.switchTo).toHaveBeenCalledWith(2)
  })

  it('s jedinou firmou firmy nenabízí', async () => {
    m.hasMultiple = false
    const w = mount(GlobalSearch, { props: { menuItems: [] } })
    await w.find('input').setValue('beta')
    await flushPromises()
    expect(w.text()).not.toContain('search.group_companies')
  })
})

describe('Ctrl+K — paleta příkazů', () => {
  it('nabídne firmu k přepnutí podle IČ a volbou ji přepne', async () => {
    const w = mount(CommandPalette, { props: { navItems: [], quickActions: [] }, attachTo: document.body })
    await (w.vm as unknown as { show: () => Promise<void> }).show()
    const input = document.body.querySelector('input') as HTMLInputElement
    input.value = '2222'
    input.dispatchEvent(new Event('input'))
    await flushPromises()
    expect(document.body.textContent).toContain('search.group_companies')
    const option = [...document.body.querySelectorAll('button')].find(b => b.textContent?.includes('Beta Účetní s.r.o.'))
    expect(option).toBeTruthy()
    option!.click()
    expect(m.switchTo).toHaveBeenCalledWith(2)
    w.unmount()
  })
})
