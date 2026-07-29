import { api } from './client'

/**
 * Skladová evidence (Epic SKLAD). Money/qty pole jsou DECIMAL uložené na backendu
 * jako string (money-safe vzor, žádné floatování) — v TS proto vždy `string`, ne `number`.
 * Vše pod `X-Supplier-Id` hlavičkou (auto přes api/client.ts interceptor).
 */

export type StockItemType = 'material' | 'goods' | 'product'
export type StockDocType = 'receipt' | 'issue' | 'transfer'
export type StockDocOrigin = 'manual' | 'invoice' | 'credit_note' | 'purchase_invoice' | 'inventory'
export type StockDocStatus = 'draft' | 'posted' | 'reversed'
export type StockTakeStatus = 'draft' | 'counting' | 'closed'
export type LandedCostAllocation = 'by_value' | 'by_qty'

export interface StockItem {
  id: number
  supplier_id: number
  sku: string
  name: string
  item_type: StockItemType
  unit: string
  ean: string | null
  vat_rate_id: number | null
  sale_price_without_vat: string | null
  min_qty: string | null
  is_active: boolean
  note: string | null
  created_at: string
  updated_at: string
}

export interface StockItemPayload {
  sku?: string
  name: string
  item_type: StockItemType
  unit: string
  ean?: string | null
  vat_rate_id?: number | null
  sale_price_without_vat?: string | number | null
  min_qty?: string | number | null
  is_active?: boolean
  note?: string | null
}

export interface StockItemSearchResult {
  id: number
  sku: string
  name: string
  unit: string
  vat_rate_id: number | null
  sale_price_without_vat: string | null
}

export interface StockItemListFilters {
  type?: StockItemType
  active?: boolean
  q?: string
  only_below_min?: boolean
  page?: number
  per_page?: number
}

/** Stránkovací meta blok (jednotný kontrakt, vzor invoicesApi.listGrouped). */
export interface StockPageMeta {
  total: number
  page?: number
  per_page?: number
  pages?: number
}

export interface Warehouse {
  id: number
  supplier_id: number
  code: string
  name: string
  is_default: boolean
  is_active: boolean
  note: string | null
  created_at: string
  updated_at: string
  /** Hodnota skladu — Σ stock_levels.value_total (dopočtená BE). */
  value: string
}

export interface WarehousePayload {
  code: string
  name: string
  is_default?: boolean
  is_active?: boolean
  note?: string | null
}

export interface StockLevelRow {
  warehouse_id: number
  stock_item_id: number
  qty: string
  value_total: string
  avg_unit_cost: string
  sku: string
  name: string
  unit: string
  item_type: StockItemType
  min_qty: string | null
  is_active: boolean
  warehouse_code: string
  warehouse_name: string
}

export interface StockLevelFilters {
  warehouse_id?: number
  item_type?: StockItemType
  below_min?: boolean
  active?: boolean
  q?: string
  /** Omezí na konkrétní karty (merge se stránkou items) — CSV na drátě. */
  item_ids?: number[]
  page?: number
  per_page?: number
}

export interface StockLedgerRow {
  line_id: number
  document_id: number
  doc_number: string | null
  doc_type: StockDocType
  origin: StockDocOrigin
  status: StockDocStatus
  doc_date: string
  line_no: number
  warehouse_id: number
  warehouse_code: string
  qty_signed: string
  qty: string
  unit_cost: string
  value_total: string
  note: string | null
  /** Doplněno FE (running balance dopočtená BE stránku po stránce). */
  balance_after?: string
}

export interface StockItemMovementsResponse {
  items: StockLedgerRow[]
  opening_balance: string
  limit: number
  offset: number
}

export interface StockDocumentLine {
  id?: number
  document_id?: number
  supplier_id?: number
  stock_item_id: number
  doc_date?: string | null
  qty: string
  unit_cost?: string
  value_total?: string
  extra_cost?: string
  invoice_item_id?: number | null
  purchase_invoice_item_id?: number | null
  source_description?: string | null
  source_qty?: string | null
  line_no?: number
  note?: string | null
  // joined (read-only, jen v odpovědi)
  sku?: string
  name?: string
  unit?: string
}

export interface StockDocument {
  id: number
  supplier_id: number
  doc_type: StockDocType
  origin: StockDocOrigin
  warehouse_id: number
  warehouse_to_id: number | null
  doc_number: string | null
  doc_date: string
  description: string
  partner_name: string | null
  invoice_id: number | null
  purchase_invoice_id: number | null
  stock_take_id: number | null
  journal_entry_id: number | null
  reversal_document_id: number | null
  status: StockDocStatus
  booked_at: string | null
  booked_by: number | null
  created_by: number | null
  created_at: string
  updated_at: string
  warehouse_code?: string | null
  warehouse_name?: string | null
  warehouse_to_code?: string | null
  warehouse_to_name?: string | null
  lines?: StockDocumentLine[]
  warnings?: string[]
}

export interface StockDocumentPayload {
  doc_type: StockDocType
  origin?: StockDocOrigin
  doc_date: string
  description: string
  warehouse_id: number
  warehouse_to_id?: number | null
  partner_name?: string | null
  invoice_id?: number
  purchase_invoice_id?: number
  stock_take_id?: number
  lines: Array<{
    stock_item_id: number
    qty: string | number
    unit_cost?: string | number
    extra_cost?: string | number
    invoice_item_id?: number
    purchase_invoice_item_id?: number
    source_description?: string
    source_qty?: string | number
    note?: string | null
  }>
}

export interface StockDocumentListFilters {
  doc_type?: StockDocType
  status?: StockDocStatus
  origin?: StockDocOrigin
  q?: string
  from?: string
  to?: string
  warehouse_id?: number
  limit?: number
  offset?: number
}

export interface StockDocumentListResponse {
  items: StockDocument[]
  total: number
  limit: number
  offset: number
}

export interface StockTakeLine {
  id: number
  stock_take_id: number
  supplier_id: number
  stock_item_id: number
  expected_qty: string
  expected_value: string
  counted_qty: string | null
  surplus_unit_cost: string | null
  item_sku: string
  item_name: string
  item_unit: string
  diff_qty: string | null
}

export interface StockTake {
  id: number
  supplier_id: number
  warehouse_id: number
  take_date: string
  status: StockTakeStatus
  note: string | null
  counting_method: 'physical_count' | 'measurement' | 'weighing' | 'other'
  responsible_count_name: string
  responsible_inventory_name: string
  started_at: string | null
  receipt_document_id: number | null
  issue_document_id: number | null
  created_by: number | null
  closed_by: number | null
  closed_at: string | null
  created_at: string
  updated_at: string
  lines?: StockTakeLine[]
  receipt_document?: StockDocument | null
  issue_document?: StockDocument | null
}

export interface StockStatusReport {
  items: StockLevelRow[]
  totals: { value_total: string; count: number }
}

export interface StockValuationRow {
  warehouse_id: number
  warehouse_code: string
  warehouse_name: string
  stock_item_id: number
  sku: string
  name: string
  unit: string
  qty: string
  value_total: string
}

export interface StockValuationReport {
  date: string
  items: StockValuationRow[]
  totals: { value_total: string; count: number }
}

export interface StockReceiptProposal {
  purchase_invoice: {
    id: number
    varsymbol: string | null
    vendor_invoice_number: string
    vendor_name: string | null
    currency_code: string
    exchange_rate: string | null
  }
  lines: Array<{
    purchase_invoice_item_id: number
    stock_item_id: number | null
    description: string
    quantity: string
    already_received: string
    remaining_qty: string
    unit_cost: string
  }>
  cost_candidates: Array<{
    purchase_invoice_item_id: number
    description: string
    amount: string
  }>
  pf_changed_after_receipt: boolean
}

export interface StockReceiptLandedCost {
  purchase_invoice_id?: number
  purchase_invoice_item_id?: number
  description?: string
  amount: number
  allocation?: LandedCostAllocation
}

export interface StockReceiptPayload {
  warehouse_id: number
  doc_date: string
  description?: string
  lines: Array<{
    purchase_invoice_item_id: number
    stock_item_id?: number
    quantity: string | number
    unit_cost?: string | number
  }>
  landed_costs?: StockReceiptLandedCost[]
}

function toParams<T extends object>(f: T = {} as T): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(f)) {
    if (v === undefined || v === null || v === '') continue
    out[k] = typeof v === 'boolean' ? (v ? 1 : 0) : (v as string | number)
  }
  return out
}

function downloadUrl(path: string): string {
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  const params = new URLSearchParams()
  if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
  const qs = params.toString()
  return `/api${path}${qs ? (path.includes('?') ? '&' : '?') + qs : ''}`
}

export const stockApi = {
  // ── Sklady ──────────────────────────────────────────────────────────────
  listWarehouses: (activeOnly = false) =>
    api.get<Warehouse[]>('/stock/warehouses', { params: activeOnly ? { active: 1 } : undefined }).then(r => r.data),
  getWarehouse: (id: number) => api.get<Warehouse>(`/stock/warehouses/${id}`).then(r => r.data),
  createWarehouse: (payload: WarehousePayload) => api.post<Warehouse>('/stock/warehouses', payload).then(r => r.data),
  updateWarehouse: (id: number, payload: WarehousePayload) => api.put<Warehouse>(`/stock/warehouses/${id}`, payload).then(r => r.data),
  deleteWarehouse: (id: number) => api.delete<{ deleted: true }>(`/stock/warehouses/${id}`).then(r => r.data),

  // ── Skladové karty ──────────────────────────────────────────────────────
  listItems: (filters: StockItemListFilters = {}) =>
    api.get<{ data: StockItem[]; meta: StockPageMeta }>('/stock/items', { params: toParams(filters) }).then(r => r.data),
  searchItems: (q: string, limit = 50) =>
    api.get<StockItemSearchResult[]>('/stock/items/search', { params: { q, limit } }).then(r => r.data),
  getItem: (id: number) => api.get<StockItem>(`/stock/items/${id}`).then(r => r.data),
  createItem: (payload: StockItemPayload) => api.post<StockItem>('/stock/items', payload).then(r => r.data),
  updateItem: (id: number, payload: StockItemPayload) => api.put<StockItem>(`/stock/items/${id}`, payload).then(r => r.data),
  deleteItem: (id: number) => api.delete<{ deleted: true }>(`/stock/items/${id}`).then(r => r.data),
  itemMovements: (id: number, opts: { warehouse_id?: number; from?: string; to?: string; limit?: number; offset?: number } = {}) =>
    api.get<StockItemMovementsResponse>(`/stock/items/${id}/movements`, { params: toParams(opts) }).then(r => r.data),
  itemMovementsExportUrl: (id: number, format: 'pdf' | 'xlsx', opts: { warehouse_id?: number; from?: string; to?: string } = {}) => {
    const params = new URLSearchParams(toParams({ ...opts, format }) as Record<string, string>)
    return downloadUrl(`/stock/items/${id}/movements/export?${params.toString()}`)
  },

  // ── Stavy zásob ─────────────────────────────────────────────────────────
  levels: (filters: StockLevelFilters = {}) => {
    const { item_ids, ...rest } = filters
    return api.get<{ data: StockLevelRow[]; meta: StockPageMeta }>('/stock/levels', {
      params: toParams({ ...rest, item_ids: item_ids?.length ? item_ids.join(',') : undefined }),
    }).then(r => r.data)
  },
  /** stock_item_id (jako string klíč) => dostupné množství (DECIMAL string). Chybějící = žádný stav. */
  availability: (itemIds: number[], warehouseId?: number) =>
    api.get<Record<string, string>>('/stock/availability', {
      params: toParams({ item_ids: itemIds.join(','), warehouse_id: warehouseId }),
    }).then(r => r.data),

  // ── Skladové doklady ────────────────────────────────────────────────────
  listDocuments: (filters: StockDocumentListFilters = {}) =>
    api.get<StockDocumentListResponse>('/stock/documents', { params: toParams(filters) }).then(r => r.data),
  getDocument: (id: number) => api.get<StockDocument>(`/stock/documents/${id}`).then(r => r.data),
  createDocument: (payload: StockDocumentPayload) => api.post<StockDocument>('/stock/documents', payload).then(r => r.data),
  updateDocument: (id: number, payload: StockDocumentPayload) => api.put<StockDocument>(`/stock/documents/${id}`, payload).then(r => r.data),
  deleteDocument: (id: number) => api.delete<{ deleted: true }>(`/stock/documents/${id}`).then(r => r.data),
  postDocument: (id: number) => api.post<StockDocument>(`/stock/documents/${id}/post`).then(r => r.data),
  reverseDocument: (id: number, reason?: string) =>
    api.post<{ original: StockDocument; reversal: StockDocument }>(`/stock/documents/${id}/reverse`, { reason }).then(r => r.data),
  documentPdfUrl: (id: number) => downloadUrl(`/stock/documents/${id}/pdf`),
  documentsForInvoice: (invoiceId: number) =>
    api.get<StockDocument[]>(`/invoices/${invoiceId}/stock-documents`).then(r => r.data),

  // ── Inventury ───────────────────────────────────────────────────────────
  listTakes: (filters: { warehouse_id?: number; status?: StockTakeStatus } = {}) =>
    api.get<StockTake[]>('/stock/takes', { params: toParams(filters) }).then(r => r.data),
  getTake: (id: number) => api.get<StockTake>(`/stock/takes/${id}`).then(r => r.data),
  createTake: (payload: { warehouse_id: number; take_date: string; note?: string | null; counting_method: string; responsible_count_name: string; responsible_inventory_name: string }) =>
    api.post<StockTake>('/stock/takes', payload).then(r => r.data),
  updateTake: (id: number, lines: Array<{ id: number; counted_qty: string | number | null; surplus_unit_cost?: string | number | null }>) =>
    api.put<StockTake>(`/stock/takes/${id}`, { lines }).then(r => r.data),
  startTake: (id: number) => api.post<StockTake>(`/stock/takes/${id}/start`).then(r => r.data),
  closeTake: (id: number) =>
    api.post<StockTake & { receipt_document: StockDocument | null; issue_document: StockDocument | null }>(`/stock/takes/${id}/close`).then(r => r.data),
  takePdfUrl: (id: number) => downloadUrl(`/stock/takes/${id}/pdf`),

  // ── Sestavy ─────────────────────────────────────────────────────────────
  reportStatus: (filters: StockLevelFilters = {}) =>
    api.get<StockStatusReport>('/stock/reports/status', { params: toParams(filters) }).then(r => r.data),
  reportValuation: (filters: { date?: string; warehouse_id?: number } = {}) =>
    api.get<StockValuationReport>('/stock/reports/valuation', { params: toParams(filters) }).then(r => r.data),
  reportExportUrl: (name: 'status' | 'valuation', format: 'pdf' | 'xlsx', filters: Record<string, unknown> = {}) => {
    const params = new URLSearchParams(toParams({ ...filters, format }) as Record<string, string>)
    return downloadUrl(`/stock/reports/${name}/export?${params.toString()}`)
  },

  // ── Příjem na sklad z přijaté faktury ───────────────────────────────────
  receiptPropose: (purchaseInvoiceId: number) =>
    api.get<StockReceiptProposal>(`/purchase-invoices/${purchaseInvoiceId}/stock-receipt`).then(r => r.data),
  receiptCreate: (purchaseInvoiceId: number, payload: StockReceiptPayload) =>
    api.post<StockDocument>(`/purchase-invoices/${purchaseInvoiceId}/stock-receipt`, payload).then(r => r.data),
  receiptList: (purchaseInvoiceId: number) =>
    api.get<StockDocument[]>(`/purchase-invoices/${purchaseInvoiceId}/stock-receipts`).then(r => r.data),
}
