import { api } from './client'
import { downloadApiFile } from '@/utils/downloadFile'
import type { ImportReport } from './imports'

export type DocumentsIssuer = 'myucto' | 'shoptet'

export interface ShoptetSettings {
  documents_issuer: DocumentsIssuer
  order_url_set: boolean
  order_url_hint: string | null
  auto_fetch: boolean
  fetch_interval_minutes: number
  fetch_cursor: string | null
  last_fetch_at: string | null
  last_fetch_status: 'ok' | 'error' | null
  last_fetch_message: string | null
  default_warehouse_id: number | null
  confirm_orders: boolean
  feed_enabled: boolean
  feed_token_created_at: string | null
  feed_warehouse_id: number | null
  feed_include_price: boolean
  feed_scope: 'eshop' | 'active'
  feed_changed_at: string | null
  feed_last_served_at: string | null
  intervals: number[]
}

export interface ShoptetWarehouse {
  id: number
  code: string
  name: string
  is_default: boolean
}

export interface ShoptetSettingsPayload {
  settings: ShoptetSettings
  warehouses: ShoptetWarehouse[]
}

export type ShoptetSettingsInput = Partial<Pick<ShoptetSettings,
  'documents_issuer' | 'auto_fetch' | 'fetch_interval_minutes' | 'default_warehouse_id' | 'confirm_orders'
  | 'feed_warehouse_id' | 'feed_include_price' | 'feed_scope'>>

/** Řádek náhledu — co se s objednávkou stane, když import potvrdíte. */
export interface ShoptetPreviewRow {
  code: string
  action: 'create' | 'update' | 'unchanged' | 'conflict' | 'failed'
  date?: string | null
  status?: string | null
  customer?: string
  customer_match?: 'new' | 'existing'
  currency?: string
  total_with_vat?: number
  shoptet_total_with_vat?: number | null
  lines?: number
  unmatched_lines?: number
  review_reasons?: string[]
  messages?: string[]
  order_id?: number | null
}

/** Řádek výsledku zápisu. */
export interface ShoptetResultRow {
  code: string
  status: 'created' | 'updated' | 'unchanged' | 'conflict' | 'failed'
  order_id?: number
  order_number?: string
  review_required?: boolean
  review_reasons?: string[]
  messages?: string[]
}

export interface ShoptetBatch {
  id: number
  kind: 'orders' | 'documents'
  source: 'upload' | 'url'
  status: 'preview' | 'applied' | 'discarded' | 'erased'
  file_name: string | null
  update_time_from: string | null
  fetched_at: string | null
  summary: Record<string, number | string | null>
  report: Array<ShoptetPreviewRow | ShoptetResultRow> | null
  invoice_batch_id: string | null
  created_at: string
  applied_at: string | null
  erased_at: string | null
  created_by: string | null
}

export interface ShoptetReviewItem {
  order_id: number
  code: string
  order_number: string
  client_name: string
  commercial_status: string
  shoptet_status: string | null
  total_with_vat: string
  shoptet_total_with_vat: string | null
  currency_code: string
  review_reasons: string[]
  pending_change: boolean
  updated_at: string
}

export interface ShoptetDocumentReport extends ImportReport {
  summary: ImportReport['summary'] & { linked_orders?: number }
  shoptet_batch_id?: number
}

export interface ShoptetEraseResult {
  deleted: number
  skipped: Array<{ code: string; reason: string }>
}

export const shoptetApi = {
  async settings(): Promise<ShoptetSettingsPayload> {
    return (await api.get<ShoptetSettingsPayload>('/eshop/shoptet/settings')).data
  },
  async saveSettings(input: ShoptetSettingsInput): Promise<ShoptetSettingsPayload> {
    return (await api.put<ShoptetSettingsPayload>('/eshop/shoptet/settings', input)).data
  },
  async setOrderUrl(url: string): Promise<ShoptetSettingsPayload> {
    return (await api.put<ShoptetSettingsPayload>('/eshop/shoptet/order-url', { url })).data
  },
  async clearOrderUrl(): Promise<ShoptetSettingsPayload> {
    return (await api.delete<ShoptetSettingsPayload>('/eshop/shoptet/order-url')).data
  },
  async previewUpload(file: File): Promise<ShoptetBatch> {
    const form = new FormData()
    form.append('file', file)
    return (await api.post<ShoptetBatch>('/eshop/shoptet/orders/preview', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })).data
  },
  async previewUrl(): Promise<ShoptetBatch> {
    return (await api.post<ShoptetBatch>('/eshop/shoptet/orders/preview', { source: 'url' })).data
  },
  async apply(batchId: number): Promise<ShoptetBatch> {
    return (await api.post<ShoptetBatch>(`/eshop/shoptet/orders/batches/${batchId}/apply`, {})).data
  },
  async discard(batchId: number): Promise<void> {
    await api.post(`/eshop/shoptet/orders/batches/${batchId}/discard`, {})
  },
  async erase(batchId: number): Promise<ShoptetEraseResult> {
    return (await api.post<ShoptetEraseResult>(`/eshop/shoptet/orders/batches/${batchId}/erase`, {})).data
  },
  async batches(kind?: 'orders' | 'documents'): Promise<ShoptetBatch[]> {
    return (await api.get<{ items: ShoptetBatch[] }>('/eshop/shoptet/batches', { params: kind ? { kind } : {} })).data.items
  },
  async reviewQueue(): Promise<ShoptetReviewItem[]> {
    return (await api.get<{ items: ShoptetReviewItem[] }>('/eshop/shoptet/orders/review')).data.items
  },
  async markReviewed(orderId: number): Promise<void> {
    await api.post(`/eshop/shoptet/orders/${orderId}/reviewed`, {})
  },
  async importDocuments(files: File[]): Promise<ShoptetDocumentReport> {
    const form = new FormData()
    files.forEach(f => form.append('files[]', f))
    return (await api.post<ShoptetDocumentReport>('/eshop/shoptet/documents/import', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })).data
  },
  async rotateFeed(): Promise<ShoptetSettingsPayload & { token: string; path: string }> {
    return (await api.post<ShoptetSettingsPayload & { token: string; path: string }>('/eshop/shoptet/feed/token', {})).data
  },
  async disableFeed(): Promise<ShoptetSettingsPayload> {
    return (await api.delete<ShoptetSettingsPayload>('/eshop/shoptet/feed/token')).data
  },
  downloadFeed(format: 'xml' | 'csv'): Promise<Record<string, string>> {
    return downloadApiFile(
      `/eshop/shoptet/feed/download?format=${format}`,
      format === 'csv' ? 'shoptet-produkty.csv' : 'shoptet-feed.xml',
    )
  },
}
