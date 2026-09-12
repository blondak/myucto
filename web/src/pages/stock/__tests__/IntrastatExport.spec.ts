import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  exportCsv: vi.fn(),
  success: vi.fn(),
}))

vi.mock('@/api/stock', () => ({
  stockApi: {
    previewIntrastat: m.preview,
    exportIntrastat: m.exportCsv,
  },
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_error: unknown, fallback: string) => fallback }))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import IntrastatExport from '../IntrastatExport.vue'

const row = {
  row_number: 1,
  source_document: { id: 17, number: 'VYD-2026-001', date: '2026-08-05', line_id: 9, type: 'issue' },
  partner_name: 'Example GmbH',
  cn8_code: '27101981',
  country_of_origin: 'DE',
  partner_country: 'DE',
  partner_vat_id: 'DE123456789',
  description: 'Synthetic oil',
  quantity: '3.000',
  net_mass_kg: '12.000',
  supplementary_unit: 'PCE',
  supplementary_quantity: '3.000',
  invoiced_value: 25000,
  issues: [],
}

function response(errorCount = 0) {
  return {
    summary: { row_count: 1, error_count: errorCount, warning_count: 0, total_net_mass_kg: '12.000', total_invoiced_value: 25000 },
    rows: errorCount ? [{ ...row, issues: [{ severity: 'error', code: 'missing_cn8', message: 'Missing CN8' }] }] : [row],
    issues: errorCount ? [{ severity: 'error', code: 'missing_cn8', message: 'Missing CN8', row_number: 1 }] : [],
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((resolver) => {
    resolve = resolver
  })
  return { promise, resolve }
}

describe('Intrastat export', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.preview.mockResolvedValue(response())
    m.exportCsv.mockResolvedValue(undefined)
  })

  const mountPage = () => mount(IntrastatExport, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })

  it('posílá společné parametry do náhledu i CSV exportu a nabízí InstatEvo', async () => {
    const wrapper = mountPage()
    await wrapper.get('[data-test="intrastat-period"]').setValue('2026-08')
    await wrapper.get('[data-test="intrastat-direction"]').setValue('arrival')
    await wrapper.get('[data-test="intrastat-statistical-code"]').setValue('50')

    await wrapper.findAll('button').find(button => button.text() === 'stock.intrastat.preview')!.trigger('click')
    await flushPromises()

    const expected = expect.objectContaining({
      period: '2026-08',
      direction: 'arrival',
      transaction_code: '11',
      transport_mode: '3',
      delivery_terms: 'K',
      record_type: 'ST',
      statistical_code: '50',
    })
    expect(m.preview).toHaveBeenCalledWith(expected)

    await wrapper.findAll('button').find(button => button.text() === 'stock.intrastat.download_csv')!.trigger('click')
    await flushPromises()
    expect(m.exportCsv).toHaveBeenCalledWith(expected)
    expect(m.success).toHaveBeenCalledWith('stock.intrastat.export_ready')

    const instatEvo = wrapper.get('a[href="https://instatevo.celnisprava.gov.cz/"]')
    expect(instatEvo.attributes('target')).toBe('_blank')
    expect(instatEvo.attributes('rel')).toContain('noopener')
  })

  it('při chybách v náhledu zablokuje stažení CSV', async () => {
    m.preview.mockResolvedValue(response(1))
    const wrapper = mountPage()
    await wrapper.findAll('button').find(button => button.text() === 'stock.intrastat.preview')!.trigger('click')
    await flushPromises()

    const download = wrapper.findAll('button').find(button => button.text() === 'stock.intrastat.download_csv')!
    expect(download.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Missing CN8')
    await download.trigger('click')
    expect(m.exportCsv).not.toHaveBeenCalled()
  })

  it('zahodí náhled, pokud se během jeho načítání změní parametry', async () => {
    const pending = deferred<ReturnType<typeof response>>()
    m.preview.mockReturnValueOnce(pending.promise)
    const wrapper = mountPage()

    await wrapper.findAll('button').find(button => button.text() === 'stock.intrastat.preview')!.trigger('click')
    await wrapper.get('[data-test="intrastat-direction"]').setValue('arrival')
    pending.resolve(response())
    await flushPromises()

    expect(wrapper.text()).not.toContain('VYD-2026-001')
    expect(wrapper.findAll('button').some(button => button.text() === 'stock.intrastat.download_csv')).toBe(false)
    expect(m.exportCsv).not.toHaveBeenCalled()
  })
})
