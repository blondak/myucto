import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => key }),
}))
const issueFinal = vi.fn()
vi.mock('@/api/invoices', () => ({ invoicesApi: { issueFinal: (...a: unknown[]) => issueFinal(...a) } }))
const push = vi.fn()
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRouter: () => ({ push }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))

import SaldoAdvanceInfo from '@/components/accounting/SaldoAdvanceInfo.vue'
import type { SaldoItem } from '@/api/accounting'

function item(over: Partial<SaldoItem> = {}): SaldoItem {
  return {
    doc_type: 'invoice', doc_id: 30, doc_no: 'Z001', issue_date: '2099-05-01', due_date: '2099-05-08',
    currency_code: 'CZK', amount_foreign: 0, booked_czk: -1210, paid_czk: -210, remaining_czk: -1000,
    days_overdue: 0, kind: 'advance_pending', label: null, advance_payment_czk: 1210, advance_vat_czk: 210,
    tax_document_id: 31, ...over,
  }
}

function mountInfo(side: 'receivable' | 'payable', over: Partial<SaldoItem> = {}) {
  return mount(SaldoAdvanceInfo, {
    props: { item: item(over), side },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
  })
}

describe('SaldoAdvanceInfo', () => {
  it('přijatá záloha: štítek, odkaz na DDKP a akce vystavení faktury', async () => {
    issueFinal.mockResolvedValueOnce({ final_invoice_id: 99, edit_url: '/invoices/99/edit' })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = mountInfo('receivable')
    expect(w.text()).toContain('accounting.saldo.advance_pending_receivable')
    expect(w.find('[data-test="saldo-advance-tax-document"]').attributes('data-to')).toContain('"id":31')
    await w.find('[data-test="saldo-advance-issue-final"]').trigger('click')
    await flushPromises()
    expect(issueFinal).toHaveBeenCalledWith(30)
    expect(push).toHaveBeenCalledWith('/invoices/99/edit')
  })

  it('poskytnutá záloha: vlastní štítek, bez vystavení faktury', () => {
    const w = mountInfo('payable', { doc_type: 'purchase_invoice' })
    expect(w.text()).toContain('accounting.saldo.advance_pending_payable')
    expect(w.find('[data-test="saldo-advance-issue-final"]').exists()).toBe(false)
    expect(w.find('[data-test="saldo-advance-tax-document"]').attributes('data-to')).toContain('purchase-invoice-detail')
  })

  it('bez daňového dokladu k platbě odkaz nevykreslí', () => {
    const w = mountInfo('receivable', { tax_document_id: null })
    expect(w.find('[data-test="saldo-advance-tax-document"]').exists()).toBe(false)
  })
})
