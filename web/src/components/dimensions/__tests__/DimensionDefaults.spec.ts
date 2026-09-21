import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import { defineComponent, h, reactive } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import type { DimensionOverview } from '@/api/dimensions'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  getDefaults: vi.fn(),
  saveDefaults: vi.fn(),
  prefill: vi.fn(),
  canWrite: vi.fn((_key: string) => true),
  toastError: vi.fn(),
  supplier: { currentSupplierId: 1, currentSupplier: { id: 1, dimensions_enabled: true } as { id: number; dimensions_enabled: boolean } },
}))

vi.mock('@/api/dimensions', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/dimensions')>()),
  dimensionsApi: {
    overview: m.overview,
    getDefaults: m.getDefaults,
    saveDefaults: m.saveDefaults,
    prefill: m.prefill,
  },
}))

vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => m.supplier }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, warning: vi.fn(), success: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import EntityDimensionDefaults from '@/components/dimensions/EntityDimensionDefaults.vue'
import { applyPrefill, useDocumentDimensions } from '@/composables/useDocumentDimensions'
import { useDimensions } from '@/composables/useDimensions'

const PROJECT = 7
const CENTER = 8

function overview(): DimensionOverview {
  const base = { supplier_id: 1, supplier_group_id: null, level: 'company' as const, responsible_user_id: null, responsible_note: null,
    car_id: null, project_id: null, cost_center_id: null, note: null, sort_order: 0, parent_id: null, is_active: true }
  return {
    enabled: true,
    group: null,
    types: [
      { id: PROJECT, supplier_id: 1, supplier_group_id: null, level: 'company', code: 'projekt', name: 'Projekt', kind: 'project', is_active: true, show_on_documents: true, sort_order: 10 },
      { id: CENTER, supplier_id: 1, supplier_group_id: null, level: 'company', code: 'stredisko', name: 'Středisko', kind: 'cost_center', is_active: true, show_on_documents: true, sort_order: 20 },
    ],
    values: [
      { ...base, id: 1, type_id: PROJECT, code: 'P1', name: 'Projekt 1' },
      { ...base, id: 2, type_id: PROJECT, code: 'P2', name: 'Projekt 2' },
      { ...base, id: 3, type_id: CENTER, code: 'S1', name: 'Středisko 1' },
      { ...base, id: 4, type_id: CENTER, code: 'S2', name: 'Středisko 2' },
    ],
  }
}

beforeEach(async () => {
  for (const fn of [m.overview, m.getDefaults, m.saveDefaults, m.prefill, m.toastError]) fn.mockReset()
  m.canWrite.mockImplementation(() => true)
  m.overview.mockResolvedValue(overview())
  m.supplier.currentSupplier.dimensions_enabled = true
  await useDimensions().reload()
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('applyPrefill', () => {
  it('doplní jen prázdné typy a zapamatuje si, co doplnilo', () => {
    const result = applyPrefill({ [PROJECT]: 2 }, {}, { [PROJECT]: 1, [CENTER]: 3 })
    expect(result.header).toEqual({ [PROJECT]: 2, [CENTER]: 3 })
    expect(result.autoFilled).toEqual({ [CENTER]: 3 })
  })

  it('při změně klienta mění jen automaticky doplněné hodnoty, volbu uživatele nechá', () => {
    // Středisko doplnil minulý klient, projekt uživatel mezitím přepsal.
    const result = applyPrefill({ [PROJECT]: 2, [CENTER]: 3 }, { [PROJECT]: 1, [CENTER]: 3 }, { [PROJECT]: 1, [CENTER]: 4 })
    expect(result.header).toEqual({ [PROJECT]: 2, [CENTER]: 4 })
    expect(result.autoFilled).toEqual({ [CENTER]: 4 })
  })

  it('klient bez výchozích uvolní dřív doplněné typy', () => {
    const result = applyPrefill({ [CENTER]: 3 }, { [CENTER]: 3 }, {})
    expect(result.header).toEqual({ [CENTER]: null })
    expect(result.autoFilled).toEqual({})
  })
})

describe('useDocumentDimensions — předvyplnění v editoru', () => {
  function harness() {
    const form = reactive({ client_id: null as number | null, project_id: null as number | null, loaded: false })
    let docDims!: ReturnType<typeof useDocumentDimensions>
    const wrapper = mount(defineComponent({
      setup() {
        docDims = useDocumentDimensions('invoices')
        docDims.watchDefaults(() => ({ client_id: form.client_id, project_id: form.project_id }), () => form.loaded, () => true)
        return () => h('div')
      },
    }))
    return { form, docDims: () => docDims, wrapper }
  }

  it('předvyplní hlavičku po výběru klienta a při změně zakázky nepřepíše volbu uživatele', async () => {
    const { form, docDims, wrapper } = harness()
    m.prefill.mockResolvedValueOnce({ header: { [PROJECT]: 1, [CENTER]: 3 }, sources: { [PROJECT]: 'client', [CENTER]: 'client' } })
    form.loaded = true
    form.client_id = 10
    await flushPromises()
    expect(m.prefill).toHaveBeenLastCalledWith({ client_id: 10, project_id: null })
    expect(docDims().header.value).toEqual({ [PROJECT]: 1, [CENTER]: 3 })
    expect(docDims().hasAutoFilled.value).toBe(true)

    // Uživatel změní středisko ručně; zakázka pak přinese jiný projekt i středisko.
    docDims().header.value = { ...docDims().header.value, [CENTER]: 4 }
    m.prefill.mockResolvedValueOnce({ header: { [PROJECT]: 2, [CENTER]: 3 }, sources: { [PROJECT]: 'project', [CENTER]: 'client' } })
    form.project_id = 20
    await flushPromises()
    expect(docDims().header.value).toEqual({ [PROJECT]: 2, [CENTER]: 4 })
    wrapper.unmount()
  })

  it('bez práva zapisovat dimenze nic nepředvyplňuje', async () => {
    m.canWrite.mockImplementation(() => false)
    const { form, docDims, wrapper } = harness()
    form.loaded = true
    form.client_id = 10
    await flushPromises()
    expect(m.prefill).not.toHaveBeenCalled()
    expect(docDims().header.value).toEqual({})
    wrapper.unmount()
  })
})

describe('EntityDimensionDefaults', () => {
  it('ve formuláři načte výchozí dimenze karty a uloží změnu až přes save(id)', async () => {
    m.getDefaults.mockResolvedValue({ [PROJECT]: 1 })
    m.saveDefaults.mockResolvedValue({ [PROJECT]: 1, [CENTER]: 3 })
    const wrapper = mount(EntityDimensionDefaults, { props: { entity: 'clients', entityId: 5 }, attachTo: document.body })
    await flushPromises()
    expect(m.getDefaults).toHaveBeenCalledWith('clients', 5)
    expect(wrapper.find('[data-test="entity-dimension-defaults"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('dimensions.defaults.hint_client')

    const vm = wrapper.vm as unknown as { save: (id: number) => Promise<void>; dirty: boolean }
    await vm.save(5)
    expect(m.saveDefaults).not.toHaveBeenCalled()

    const inputs = wrapper.findAll('input[role="combobox"]')
    await inputs[1].trigger('focus')
    await wrapper.findAll('[role="option"]')[0].trigger('click')
    await vm.save(5)
    expect(m.saveDefaults).toHaveBeenCalledWith('clients', 5, { [PROJECT]: 1, [CENTER]: 3 })
    wrapper.unmount()
  })

  it('nová karta nic nenačítá a bez práva na úpravu jsou výběry zamčené', async () => {
    m.canWrite.mockImplementation((key: string) => key !== 'projects')
    const wrapper = mount(EntityDimensionDefaults, { props: { entity: 'projects', entityId: null }, attachTo: document.body })
    await flushPromises()
    expect(m.getDefaults).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('dimensions.defaults.hint_project')
    expect(wrapper.findAll('input[role="combobox"]').every(i => (i.element as HTMLInputElement).disabled)).toBe(true)
    wrapper.unmount()
  })

  it('na detailu ukáže štítky jen tehdy, když karta výchozí dimenze má', async () => {
    m.getDefaults.mockResolvedValueOnce({ [CENTER]: 4 })
    const withValues = mount(EntityDimensionDefaults, { props: { entity: 'projects', entityId: 9, mode: 'summary' } })
    await flushPromises()
    expect(withValues.get('[data-test="entity-dimension-defaults-summary"]').text()).toContain('S2 – Středisko 2')
    withValues.unmount()

    m.getDefaults.mockResolvedValueOnce({})
    const empty = mount(EntityDimensionDefaults, { props: { entity: 'projects', entityId: 9, mode: 'summary' } })
    await flushPromises()
    expect(empty.find('[data-test="entity-dimension-defaults-summary"]').exists()).toBe(false)
    empty.unmount()
  })

  it('při vypnutých dimenzích se nevykreslí a nic nenačítá', async () => {
    m.supplier.currentSupplier.dimensions_enabled = false
    const wrapper = mount(EntityDimensionDefaults, { props: { entity: 'clients', entityId: 5 } })
    await flushPromises()
    expect(wrapper.find('[data-test="entity-dimension-defaults"]').exists()).toBe(false)
    expect(m.getDefaults).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
