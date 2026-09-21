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
  /** `locked` = zápis leží v uzavřeném nebo zamčeném období (mění se jen analytika). */
  restamp: { lines: number; needs_repost: boolean; locked?: boolean }
}

/** Zaúčtovaný řádek dokladu s dimenzemi, jaké by nesl po uložení (náhled). */
export interface DocumentDimensionsPreviewLine {
  id: number
  entry_id: number
  account_code: string | null
  account_name: string | null
  side: 'debit' | 'credit'
  amount: number
  dimensions: Record<number, number>
}

export interface DocumentDimensionsPreview extends DocumentDimensionsSaveResult {
  /** Uložení by se odmítlo: řádek by bylo nutné rozdělit, ale zápis je v uzavřeném období. */
  refused: boolean
  lines: DocumentDimensionsPreviewLine[]
}

export interface DocumentDimensionsPayload {
  header: DimensionMap
  /** Bez položek zůstanou dimenze položek dokladu beze změny. */
  items?: Record<number, DimensionMap>
}

/** Tělo pro uložení i náhled; `items` jen tehdy, když je volající opravdu posílá. */
export function documentDimensionsBody(payload: DocumentDimensionsPayload) {
  return {
    header: compactDimensions(payload.header),
    ...(payload.items === undefined ? {} : {
      items: Object.fromEntries(Object.entries(payload.items).map(([no, map]) => [no, compactDimensions(map)])),
    }),
  }
}

export type DimensionDocType = 'purchase-invoices' | 'invoices' | 'cash-documents' | 'bank-transactions' | 'journal-templates'

/** Karta s výchozími dimenzemi (klient slouží jako odběratel i dodavatel). */
export type DimensionDefaultsEntity = 'clients' | 'projects'

export type DimensionPrefillSource = 'project' | 'client' | 'document'

/** Předvyplnění hlavičky dokladu: typ → hodnota a odkud se vzala. */
export interface DimensionPrefill {
  header: Record<number, number>
  sources: Record<number, DimensionPrefillSource>
}

export interface DimensionPrefillParams {
  client_id?: number | null
  project_id?: number | null
  invoice_id?: number | null
  purchase_invoice_id?: number | null
}

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
  saveDocument: (doc: DimensionDocType, id: number, payload: DocumentDimensionsPayload) =>
    api.put<DocumentDimensionsSaveResult>(`/accounting/dimensions/documents/${doc}/${id}`, documentDimensionsBody(payload))
      .then(r => r.data),
  /** Co by po uložení nesl každý zaúčtovaný řádek dokladu — nic se neuloží. */
  previewDocument: (doc: DimensionDocType, id: number, payload: DocumentDimensionsPayload) =>
    api.post<DocumentDimensionsPreview>(`/accounting/dimensions/documents/${doc}/${id}/preview`, documentDimensionsBody(payload))
      .then(r => r.data),

  getDefaults: (entity: DimensionDefaultsEntity, id: number) =>
    api.get<{ dimensions: Record<number, number> }>(`/${entity}/${id}/dimensions`).then(r => r.data.dimensions),
  saveDefaults: (entity: DimensionDefaultsEntity, id: number, map: DimensionMap) =>
    api.put<{ dimensions: Record<number, number> }>(`/${entity}/${id}/dimensions`, { dimensions: compactDimensions(map) })
      .then(r => r.data.dimensions),
  prefill: (params: DimensionPrefillParams) =>
    api.get<DimensionPrefill>('/accounting/dimensions/prefill', {
      params: Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v > 0)),
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
