import { api } from './client'

/**
 * Drobný majetek (§DM) — typovaný klient pro /api/accounting/small-assets.
 * Tenant-scoped přes X-Supplier-Id (přidává api/client.ts); zápisy vyžadují
 * roli účetní|admin. Chyby chodí jako { error: { code, message } }.
 *
 * Karta NIC neúčtuje — náklad na 501 vzniká už zaúčtováním dokladu podle
 * expense_kind. Tohle je evidence věcí dle §28/5 ZoÚ a ČÚS 013.
 */

export type SmallAssetStatus = 'in_use' | 'disposed' | 'sold'

export interface SmallAsset {
  id: number
  supplier_id: number
  /** Zdroj je volitelný: faktura NEBO pokladní doklad NEBO nic (ruční karta). */
  purchase_invoice_id: number | null
  purchase_invoice_item_id: number | null
  cash_document_id: number | null
  /** Snapshot čísla dokladu — přežije smazání i editaci zdroje. */
  document_ref: string | null
  name: string
  inventory_number: string | null
  vendor_client_id: number | null
  vendor_name: string | null
  acquisition_date: string
  put_into_use_date: string | null
  quantity: number
  unit_price: number
  price: number
  location: string | null
  responsible_person: string | null
  status: SmallAssetStatus
  disposed_at: string | null
  disposal_reason: string | null
  /** Prodej = vazba na vydanou fakturu (status 'sold'). */
  sale_invoice_id: number | null
  sold_at: string | null
  sale_price: number | null
  notes: string | null
  created_by: number | null
  created_at: string
  updated_at: string
  vendor_client_name?: string | null
}

export interface SmallAssetListResponse {
  items: SmallAsset[]
  total: number
  page: number
  per_page: number
  locations: string[]
  years: number[]
}

export interface SmallAssetPayload {
  name: string
  acquisition_date: string
  price: number
  quantity?: number
  unit_price?: number
  inventory_number?: string | null
  vendor_client_id?: number | null
  vendor_name?: string | null
  put_into_use_date?: string | null
  location?: string | null
  responsible_person?: string | null
  notes?: string | null
  document_ref?: string | null
  purchase_invoice_id?: number | null
  purchase_invoice_item_id?: number | null
  cash_document_id?: number | null
}

export interface SmallAssetGenerateResult {
  purchase_invoice_id: number
  created: number
  skipped: number
  cards: SmallAsset[]
}

// ── sestavy ─────────────────────────────────────────────────────────────────

export interface ReportEntity {
  name: string
  ico: string | null
  address: string
  prepared_at: string
}

/** Řádek soupisu — zobrazovací tvar karty ze SmallAssetReportService. */
export interface SmallAssetReportRow {
  id: number
  name: string
  inventory_number: string | null
  document_ref: string | null
  purchase_invoice_id: number | null
  cash_document_id: number | null
  vendor_name: string | null
  acquisition_date: string
  put_into_use_date: string | null
  quantity: number
  price: number
  location: string | null
  responsible_person: string | null
  status: SmallAssetStatus
  disposed_at: string | null
  disposal_reason: string | null
}

export interface SmallAssetInventoryReport {
  as_of: string
  entity: ReportEntity
  groups: { location: string | null; rows: SmallAssetReportRow[]; total: number }[]
  count: number
  total: number
}

export interface SmallAssetMovementsReport {
  from: string
  to: string
  entity: ReportEntity
  additions: SmallAssetReportRow[]
  disposals: SmallAssetReportRow[]
  additions_total: number
  disposals_total: number
  additions_count: number
  disposals_count: number
}

export interface ExpenseBreakdownRow {
  doc_date: string
  purchase_invoice_id: number
  document_ref: string | null
  vendor_name: string | null
  description: string
  quantity: number
  amount: number
}

export interface SmallAssetExpenseBreakdownReport {
  from: string
  to: string
  entity: ReportEntity
  groups: { expense_kind: 'material' | 'small_asset'; rows: ExpenseBreakdownRow[]; total: number; document_count: number }[]
  total: number
}

export const smallAssetsApi = {
  list: (filters?: { status?: SmallAssetStatus | ''; q?: string; location?: string; year?: number | ''; page?: number; per_page?: number }) => {
    const params: Record<string, string | number> = {}
    if (filters?.status) params.status = filters.status
    if (filters?.q) params.q = filters.q
    if (filters?.location) params.location = filters.location
    if (filters?.year) params.year = filters.year
    if (filters?.page) params.page = filters.page
    if (filters?.per_page) params.per_page = filters.per_page
    return api.get<SmallAssetListResponse>('/accounting/small-assets', { params }).then(r => r.data)
  },
  get: (id: number) => api.get<{ card: SmallAsset }>(`/accounting/small-assets/${id}`).then(r => r.data.card),
  create: (payload: SmallAssetPayload) =>
    api.post<{ card: SmallAsset }>('/accounting/small-assets', payload).then(r => r.data.card),
  update: (id: number, payload: Partial<SmallAssetPayload>) =>
    api.put<{ card: SmallAsset }>(`/accounting/small-assets/${id}`, payload).then(r => r.data.card),
  remove: (id: number) => api.delete(`/accounting/small-assets/${id}`).then(r => r.data),
  dispose: (id: number, payload: { disposed_at: string; disposal_reason?: string | null }) =>
    api.post<{ card: SmallAsset }>(`/accounting/small-assets/${id}/dispose`, payload).then(r => r.data.card),
  /** Prodej = propojení karty s vydanou fakturou; z karty se nic neúčtuje (ZC=0). */
  sell: (id: number, payload: { sale_invoice_id: number; sold_at: string; sale_price?: number | null }) =>
    api.post<{ card: SmallAsset }>(`/accounting/small-assets/${id}/sell`, payload).then(r => r.data.card),
  restore: (id: number) =>
    api.post<{ card: SmallAsset }>(`/accounting/small-assets/${id}/restore`, {}).then(r => r.data.card),
  /** Idempotentní — opakované volání nezaloží duplicity. */
  generateFromPurchaseInvoice: (purchaseInvoiceId: number) =>
    api.post<SmallAssetGenerateResult>(`/accounting/purchase-invoices/${purchaseInvoiceId}/small-assets`, {}).then(r => r.data),

  inventory: (asOf: string) =>
    api.get<SmallAssetInventoryReport>('/accounting/reports/small-assets/inventory', { params: { as_of: asOf } }).then(r => r.data),
  movements: (from: string, to: string) =>
    api.get<SmallAssetMovementsReport>('/accounting/reports/small-assets/movements', { params: { from, to } }).then(r => r.data),
  expenseBreakdown: (from: string, to: string) =>
    api.get<SmallAssetExpenseBreakdownReport>('/accounting/reports/small-assets/expense-breakdown', { params: { from, to } }).then(r => r.data),

  /**
   * Export vrací blob. Musí jít přes `api`, ne přes prostý <a href> — tenant scope
   * jede v hlavičce X-Supplier-Id, kterou by odkaz neposlal.
   */
  exportReport: (path: string, params: Record<string, unknown>) =>
    api.get<Blob>(path, { params, responseType: 'blob' }),
}
