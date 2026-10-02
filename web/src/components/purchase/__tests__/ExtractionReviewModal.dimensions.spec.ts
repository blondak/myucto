import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  invoices: {} as Record<number, Record<string, unknown>>,
  getDocument: vi.fn(),
  prefill: vi.fn(),
  saveDocument: vi.fn(),
  setExpenseKinds: vi.fn(),
  toastWarning: vi.fn(),
  requiresApproval: false,
  types: () => [{ id: 1, name: 'Středisko', is_active: true, show_on_documents: true, requires_approval: m.requiresApproval }],
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], template: '<a><slot /></a>' } }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: m.toastWarning }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isClientRole: false }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true },
    canEdit: { value: true },
    documentTypes: { get value() { return m.types() } },
    types: { get value() { return m.types() } },
    values: { value: [] },
    typeById: { value: new Map([[1, { id: 1, name: 'Středisko' }]]) },
    valueLabel: (id: number) => `hodnota-${id}`,
    load: () => Promise.resolve(),
  }),
}))
vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    get: (id: number) => Promise.resolve(m.invoices[id]),
    setExpenseKinds: (...args: unknown[]) => m.setExpenseKinds(...args),
  },
}))
vi.mock('@/api/dimensions', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/dimensions')>()),
  dimensionsApi: {
    getDocument: (...args: unknown[]) => m.getDocument(...args),
    prefill: (...args: unknown[]) => m.prefill(...args),
    saveDocument: (...args: unknown[]) => m.saveDocument(...args),
  },
}))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { template: '<div><slot /><slot name="footer" /></div>' } }))
vi.mock('@/components/purchase/ExtractionWarningText.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/dimensions/DimensionChips.vue', () => ({ default: { props: ['dimensions', 'splits'], template: '<span class="split-chips" />' } }))
vi.mock('@/components/dimensions/ItemDimensionsToggle.vue', () => ({ default: { template: '<button class="item-toggle" />' } }))
vi.mock('@/components/dimensions/DimensionFields.vue', () => ({
  default: { name: 'DimensionFields', props: ['modelValue', 'disabled', 'compact', 'types'], emits: ['update:modelValue'], template: '<div class="fields" />' },
}))

import ExtractionReviewModal from '@/components/purchase/ExtractionReviewModal.vue'

function invoice(id: number, overrides: Record<string, unknown> = {}) {
  return {
    id,
    status: 'draft',
    vendor_id: 30,
    project_id: null,
    vendor_company_name: 'Syntetický dodavatel',
    vendor_invoice_number: `FV-${id}`,
    varsymbol: null,
    issue_date: '2096-06-15',
    total_with_vat: 1210,
    currency: 'CZK',
    extraction_warning: null,
    extraction_review: null,
    review: { reasons: [], details: {} },
    items: [{ id: 100 + id, order_index: 0, description: 'Položka', quantity: 1, unit_price_without_vat: 1000, total_without_vat: 1000, expense_kind: null }],
    locked: null,
    ...overrides,
  }
}

const missingCenter = {
  reasons: ['missing_required_dimension'],
  details: { missing_required_dimension: { missing_dimensions: [{ type_id: 1, type_name: 'Středisko', account_codes: ['518'] }] } },
}

async function mountModal(ids: number[]) {
  const wrapper = mount(ExtractionReviewModal, { props: { invoiceIds: ids } })
  await flushPromises()
  return wrapper
}

function saveButton(wrapper: Awaited<ReturnType<typeof mountModal>>) {
  return wrapper.findAll('button').find(b => b.text().includes('purchase_invoice.extraction_review.save_'))!
}

describe('ExtractionReviewModal — dimenze', () => {
  beforeEach(() => {
    m.invoices = {}
    m.getDocument.mockReset().mockResolvedValue({ header: {}, items: {} })
    m.prefill.mockReset().mockResolvedValue({ header: { 1: 7 }, sources: { 1: 'history' } })
    m.saveDocument.mockReset().mockResolvedValue({ header: { 1: 7 }, items: {}, restamp: { lines: 0, needs_repost: false } })
    m.setExpenseKinds.mockReset()
    m.toastWarning.mockReset()
    m.requiresApproval = false
  })

  it('koncept bez hlášení se otevře, když mu chybí dimenze typu se schvalováním (F5/F6)', async () => {
    m.requiresApproval = true
    m.prefill.mockResolvedValue({ header: {}, sources: {} })
    m.getDocument.mockImplementation((_doc: string, id: number) =>
      Promise.resolve(id === 2 ? { header: { 1: 5 }, items: {} } : { header: {}, items: {} }))
    m.invoices = { 1: invoice(1), 2: invoice(2) }
    const wrapper = await mountModal([1, 2])

    expect(wrapper.text()).toContain('FV-1')
    expect(wrapper.text()).not.toContain('FV-2')
    expect(wrapper.get('[data-test="review-approval-missing"]').text()).toContain('purchase_approval.review.missing_dimension')
  })

  it('bez typu se schvalováním se doklad bez hlášení nepřidává a dimenze se neprobírají', async () => {
    m.invoices = { 1: invoice(1) }
    const wrapper = await mountModal([1])

    expect(wrapper.find('[data-test="review-approval-missing"]').exists()).toBe(false)
    expect(m.getDocument).not.toHaveBeenCalled()
  })

  it('otevře doklad bez hlášení vytěžení, kterému chybí povinná dimenze, a přeskočí doklad v pořádku', async () => {
    m.invoices = { 1: invoice(1), 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([1, 2])

    expect(wrapper.text()).toContain('purchase_invoice.extraction_review.counter')
    expect(m.getDocument).toHaveBeenCalledTimes(1)
    expect(m.getDocument).toHaveBeenCalledWith('purchase-invoices', 2)
    expect(wrapper.find('[data-test="review-dimension-missing"]').exists()).toBe(false)
  })

  it('předvyplní hlavičku z historie dodavatele a návrh vyznačí', async () => {
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])

    expect(m.prefill).toHaveBeenCalledWith({ client_id: 30, project_id: null, history: 1, exclude_purchase_invoice_id: 2 })
    expect(wrapper.findComponent({ name: 'DimensionFields' }).props('modelValue')).toEqual({ 1: 7 })
    const suggestion = wrapper.get('[data-test="review-dimension-suggestion"]')
    expect(suggestion.text()).toContain('purchase_invoice.extraction_review.dimensions.suggestion')
    expect(suggestion.text()).toContain('hodnota-7')
    expect(suggestion.text()).toContain('purchase_invoice.extraction_review.dimensions.source.history')
  })

  it('ruční změna návrh zruší a chybějící povinnou dimenzi ukáže', async () => {
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])

    wrapper.findComponent({ name: 'DimensionFields' }).vm.$emit('update:modelValue', { 1: null })
    await flushPromises()
    expect(wrapper.find('[data-test="review-dimension-suggestion"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="review-dimension-missing"]').text()).toContain('purchase_invoice.extraction_review.dimensions.required')
  })

  it('„Uložit" zapíše dimenze dokladu explicitně (hlavička, položky beze změny neposílá)', async () => {
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.saveDocument).toHaveBeenCalledWith('purchase-invoices', 2, { header: { 1: 7 } })
    expect(m.setExpenseKinds).not.toHaveBeenCalled()
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('typ s rozpadem v hlavičce nepředvyplní, nenabídne a uložení rozpad nepošle k přepsání', async () => {
    m.getDocument.mockResolvedValue({ header: {}, items: {}, splits: { 0: { 1: [{ value_id: 3, share: 0.5 }, { value_id: 4, share: 0.5 }] } } })
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])

    const fields = wrapper.findComponent({ name: 'DimensionFields' })
    expect(fields.props('modelValue')).toEqual({})
    expect(fields.props('types')).toEqual([])
    expect(wrapper.find('[data-test="review-dimension-suggestion"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-dimension-missing"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-dimension-splits"]').exists()).toBe(true)

    await saveButton(wrapper).trigger('click')
    await flushPromises()
    expect(m.saveDocument).not.toHaveBeenCalled()
  })

  it('po uložení předá rodiči doklad s přepočteným review', async () => {
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])
    m.invoices[2] = invoice(2, { review: { reasons: [], details: {} } })

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    const updated = wrapper.emitted('updated')?.[0]?.[0] as { review: { reasons: string[] } }
    expect(updated.review.reasons).toEqual([])
  })

  it('výchozí hodnota z dokladu se nepřepíše a beze změny se nic neukládá', async () => {
    m.getDocument.mockResolvedValue({ header: { 1: 5 }, items: {} })
    m.invoices = { 2: invoice(2, { extraction_warning: 'Rozdíl součtů', review: { reasons: ['extraction_warning'], details: {} } }) }
    const wrapper = await mountModal([2])

    expect(wrapper.findComponent({ name: 'DimensionFields' }).props('modelValue')).toEqual({ 1: 5 })
    expect(wrapper.find('[data-test="review-dimension-suggestion"]').exists()).toBe(false)

    await saveButton(wrapper).trigger('click')
    await flushPromises()
    expect(m.saveDocument).not.toHaveBeenCalled()
  })
})
