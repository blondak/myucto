import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/api/client', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

import {
  portalPurchaseInvoiceSubmissionsApi,
  purchaseInvoiceSubmissionsApi,
} from '@/api/purchaseInvoiceSubmissions'

describe('URL originálu ve staging frontě', () => {
  afterEach(() => {
    localStorage.removeItem('myinvoice.current_supplier_id')
  })

  it('nese aktivní firmu v query paramu — iframe ani <a> hlavičku X-Supplier-Id neposílá', () => {
    localStorage.setItem('myinvoice.current_supplier_id', '42')

    expect(purchaseInvoiceSubmissionsApi.previewUrl(7))
      .toBe('/api/purchase-invoice-submissions/7/preview?supplier_id=42')
    expect(purchaseInvoiceSubmissionsApi.downloadUrl(7))
      .toBe('/api/purchase-invoice-submissions/7/download?supplier_id=42')
    expect(portalPurchaseInvoiceSubmissionsApi.previewUrl(7))
      .toBe('/api/portal/purchase-invoice-submissions/7/preview?supplier_id=42')
    expect(portalPurchaseInvoiceSubmissionsApi.downloadUrl(7))
      .toBe('/api/portal/purchase-invoice-submissions/7/download?supplier_id=42')
  })

  it('bez uložené firmy nechá rozhodnutí na serverovém fallbacku', () => {
    expect(purchaseInvoiceSubmissionsApi.previewUrl(7))
      .toBe('/api/purchase-invoice-submissions/7/preview')
  })

  it('nedůvěřuje nečíselné hodnotě z localStorage', () => {
    localStorage.setItem('myinvoice.current_supplier_id', '1 OR 1=1')

    expect(purchaseInvoiceSubmissionsApi.downloadUrl(7))
      .toBe('/api/purchase-invoice-submissions/7/download')
  })
})
