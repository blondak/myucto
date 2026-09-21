import { api } from './client'

export type DimensionKind = 'cost_center' | 'project' | 'vehicle' | 'location' | 'deal' | 'custom'
export type DimensionLevel = 'global' | 'company'

export interface DimensionType {
  id: number
  supplier_id: number | null
  supplier_group_id: number | null
  level: DimensionLevel
  code: string
  name: string
  kind: DimensionKind
  is_active: boolean
  show_on_documents: boolean
  sort_order: number
}

export interface DimensionValue {
  id: number
  type_id: number
  supplier_id: number | null
  supplier_group_id: number | null
  level: DimensionLevel
  parent_id: number | null
  code: string
  name: string
  is_active: boolean
  responsible_user_id: number | null
  responsible_user_name?: string | null
  responsible_note: string | null
  car_id: number | null
  car_registration?: string | null
  project_id: number | null
  project_name?: string | null
  cost_center_id: number | null
  cost_center_code?: string | null
  note: string | null
  sort_order: number
}

export interface SupplierGroupMember {
  id: number
  company_name: string
  ic: string | null
}

export interface SupplierGroup {
  id: number
  name: string
  members: SupplierGroupMember[]
}

export interface DimensionOverview {
  enabled: boolean
  group: SupplierGroup | null
  types: DimensionType[]
  values: DimensionValue[]
}

export interface DimensionGroupInfo {
  group: SupplierGroup | null
  candidates: { id: number; name: string }[]
}

/** typ → hodnota; `null` = typ bez hodnoty (při ukládání se vypustí). */
export type DimensionMap = Record<number, number | null>

export interface DocumentDimensions {
  header: DimensionMap
  /** pořadí položky od 1 → mapa typ → hodnota */
  items: Record<number, DimensionMap>
}

export interface DocumentDimensionsSaveResult extends DocumentDimensions {
  restamp: { lines: number; needs_repost: boolean }
}

export type DimensionDocType = 'purchase-invoices' | 'invoices' | 'cash-documents' | 'bank-transactions' | 'journal-templates'

export interface DimensionTypePayload {
  code?: string
  name?: string
  kind?: DimensionKind
  level?: DimensionLevel
  is_active?: boolean
  show_on_documents?: boolean
  sort_order?: number
}

export interface DimensionValuePayload {
  code?: string
  name?: string
  parent_id?: number | null
  is_active?: boolean
  responsible_user_id?: number | null
  responsible_note?: string | null
  car_id?: number | null
  project_id?: number | null
  cost_center_id?: number | null
  note?: string | null
  sort_order?: number
}

export interface DimensionProfitAmounts {
  revenue: number
  cost: number
  result: number
}

export interface DimensionProfitRow {
  value_id: number
  parent_id: number | null
  code: string
  name: string
  is_active: boolean
  depth: number
  has_children: boolean
  own: DimensionProfitAmounts
  total: DimensionProfitAmounts
}

export interface DimensionProfitReport {
  type: DimensionType
  from: string
  to: string
  supplier_ids: number[]
  hidden_companies: number
  rows: DimensionProfitRow[]
  unassigned: DimensionProfitAmounts
  totals: DimensionProfitAmounts
}

/** Vyhodí z mapy prázdné typy — server bere jen vyplněné dvojice. */
export function compactDimensions(map: DimensionMap | null | undefined): Record<number, number> {
  const out: Record<number, number> = {}
  for (const [typeId, valueId] of Object.entries(map ?? {})) {
    if (valueId) out[Number(typeId)] = valueId
  }
  return out
}

export const dimensionsApi = {
  overview: () => api.get<DimensionOverview>('/accounting/dimensions').then(r => r.data),
  setEnabled: (enabled: boolean, createDefaults = false) =>
    api.put<DimensionOverview>('/accounting/dimensions/settings', { enabled, create_defaults: createDefaults }).then(r => r.data),
  createDefaults: () => api.post<DimensionOverview>('/accounting/dimensions/defaults').then(r => r.data),

  createType: (payload: DimensionTypePayload) =>
    api.post<DimensionType>('/accounting/dimensions/types', payload).then(r => r.data),
  updateType: (id: number, payload: DimensionTypePayload) =>
    api.patch<DimensionType>(`/accounting/dimensions/types/${id}`, payload).then(r => r.data),
  deleteType: (id: number) =>
    api.delete<{ deleted: boolean }>(`/accounting/dimensions/types/${id}`).then(r => r.data),

  createValue: (typeId: number, payload: DimensionValuePayload) =>
    api.post<DimensionValue>(`/accounting/dimensions/types/${typeId}/values`, payload).then(r => r.data),
  updateValue: (id: number, payload: DimensionValuePayload) =>
    api.patch<DimensionValue>(`/accounting/dimensions/values/${id}`, payload).then(r => r.data),
  deleteValue: (id: number) =>
    api.delete<{ deleted: boolean }>(`/accounting/dimensions/values/${id}`).then(r => r.data),
  responsibleCandidates: () =>
    api.get<{ id: number; name: string }[]>('/accounting/dimensions/responsible-candidates').then(r => r.data),

  getDocument: (doc: DimensionDocType, id: number) =>
    api.get<DocumentDimensions>(`/accounting/dimensions/documents/${doc}/${id}`).then(r => r.data),
  saveDocument: (doc: DimensionDocType, id: number, payload: { header: DimensionMap; items?: Record<number, DimensionMap> }) =>
    api.put<DocumentDimensionsSaveResult>(`/accounting/dimensions/documents/${doc}/${id}`, {
      header: compactDimensions(payload.header),
      items: Object.fromEntries(Object.entries(payload.items ?? {}).map(([no, map]) => [no, compactDimensions(map)])),
    }).then(r => r.data),

  getJournal: (entryId: number) =>
    api.get<Record<number, Record<number, number>>>(`/accounting/dimensions/journal/${entryId}`).then(r => r.data),
  saveJournal: (entryId: number, lines: Record<number, DimensionMap>) =>
    api.put<{ changed: number; lines: Record<number, Record<number, number>> }>(`/accounting/dimensions/journal/${entryId}`, {
      lines: Object.fromEntries(Object.entries(lines).map(([id, map]) => [id, compactDimensions(map)])),
    }).then(r => r.data),

  group: () => api.get<DimensionGroupInfo>('/accounting/dimensions/group').then(r => r.data),
  createGroup: (name: string) => api.post<DimensionGroupInfo>('/accounting/dimensions/group', { name }).then(r => r.data),
  joinGroup: (groupId: number) => api.put<DimensionGroupInfo>('/accounting/dimensions/group', { group_id: groupId }).then(r => r.data),
  renameGroup: (name: string) => api.put<DimensionGroupInfo>('/accounting/dimensions/group', { name }).then(r => r.data),
  leaveGroup: () => api.delete<DimensionGroupInfo>('/accounting/dimensions/group').then(r => r.data),

  profit: (params: { type_id: number; from: string; to: string; scope?: 'group' }) =>
    api.get<DimensionProfitReport>('/accounting/reports/dimension-profit', { params }).then(r => r.data),
}
