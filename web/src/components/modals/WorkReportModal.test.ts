import { shallowMount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import WorkReportModal from './WorkReportModal.vue'

type InvoiceRow = {
  description: string
  quantity: number
  duration_minutes: number | null
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  order_index: number
  stock_item_id?: number | null
  warehouse_id?: number | null
  small_asset_id?: number | null
  asset_id?: number | null
}

function modalVm() {
  const wrapper = shallowMount(WorkReportModal, {
    props: { modelValue: false, invoiceId: 42 },
    global: {
      plugins: [
        createPinia(),
        createI18n({ legacy: false, locale: 'cs', messages: { cs: {} } }),
      ],
    },
  })
  return wrapper.vm as unknown as {
    wrItems: Array<{ description: string; hours: number; duration_minutes: number | null; rate: number }>
    totalAmount: number
    syncRow: (
      items: InvoiceRow[],
      originalTitle: string,
      newTitle: string,
      total: number,
      vatRateId: number | null,
      present: boolean,
      allowEmptyReuse: boolean,
    ) => void
  }
}

describe('WorkReportModal invoice row sync', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('sums rounded minute rows while keeping legacy aggregation unchanged', () => {
    const vm = modalVm()
    vm.wrItems = Array.from({ length: 2 }, () => ({ description: 'Minute', hours: 0.02, duration_minutes: 1, rate: 0.9 }))
    expect(vm.totalAmount).toBe(0.04)
    const rows: InvoiceRow[] = []
    vm.syncRow(rows, '', 'Práce', vm.totalAmount, 1, true, true)
    expect(rows[0]?.unit_price_without_vat).toBe(0.04)
    vm.wrItems = Array.from({ length: 2 }, () => ({ description: 'Legacy', hours: 0.5, duration_minutes: null, rate: 0.03 }))
    expect(vm.totalAmount).toBe(0.03)
  })

  it.each([
    { stock_item_id: 7, warehouse_id: 3 },
    { small_asset_id: 8 },
    { asset_id: 9 },
  ])('nepřepíše ani nesmaže navázaný řádek %o', (link) => {
    const vm = modalVm()
    const linked: InvoiceRow = {
      description: 'Práce',
      quantity: 2,
      duration_minutes: null,
      unit: 'ks',
      unit_price_without_vat: 500,
      vat_rate_id: 1,
      order_index: 0,
      ...link,
    }
    const original = { ...linked }
    const rows = [linked]

    vm.syncRow(rows, 'Práce', 'Práce', 1200, 1, true, true)
    expect(rows).toHaveLength(2)
    expect(rows[0]).toEqual(original)
    expect(rows[1]).toMatchObject({
      description: 'Práce',
      quantity: 1,
      unit: 'ks',
      unit_price_without_vat: 1200,
    })

    vm.syncRow(rows, 'Práce', 'Práce', 0, 1, false, true)
    expect(rows).toEqual([original])
  })
})
