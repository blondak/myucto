import { api } from './client'
import type { CatalogJob } from './catalogJobs'

export type IntegrationStatus = 'draft' | 'active' | 'paused' | 'error'
export type IntegrationOwner = 'local' | 'remote' | 'manual'
export type MappingSource = 'warehouses' | 'currencies' | 'languages' | 'vat_rates'

export interface IntegrationConnection {
  id: number
  connection_uuid: string
  supplier_id: number
  connector_key: string
  name: string
  status: IntegrationStatus
  mappings: Record<string, unknown>
  field_ownership: Record<string, IntegrationOwner>
  credentials_configured: boolean
  /** Jen názvy uložených přístupových údajů, hodnoty server nikdy nevrací. */
  credentials_fields: string[]
  webhook_configured: boolean
  rate_limit_per_minute: number
  retention_days: number
  last_synced_at: string | null
  last_error_code: string | null
  last_error_at: string | null
  created_at: string
  updated_at: string
}

export interface IntegrationError {
  direction: 'inbox' | 'outbox'
  id: number
  entity_type: string
  entity_id: string
  event_type: string
  aggregate_version: number
  status: string
  attempts: number
  last_error_code: string | null
  payload_redacted: boolean
  occurred_at: string
}

export interface IntegrationDiagnostics {
  last_synced_at: string | null
  last_error_code: string | null
  last_error_at: string | null
  inbox: Record<string, number>
  outbox: Record<string, number>
  errors: IntegrationError[]
  jobs: CatalogJob[]
}

export interface ConnectionInput {
  connector_key: string
  name: string
  status: IntegrationStatus
  mappings: Record<string, unknown>
  field_ownership: Record<string, IntegrationOwner>
  rate_limit_per_minute: number
  retention_days: number
}

export interface ConnectorCredentialField {
  key: string
  type: 'text' | 'secret' | 'url'
  required: boolean
  label: string
}

export interface ConnectorMappingType {
  type: string
  source: MappingSource
  label: string
}

export interface ConnectorField {
  key: string
  area: string
  default_owner: IntegrationOwner
  label: string
}

export interface ConnectorDefinition {
  key: string
  available: boolean
  i18n: string
  name: string
  capabilities: string[]
  /** Informativní odstavce pro UI (i18n `connectors.<i18n>.notes.<note>`). */
  notes: string[]
  free_fields: boolean
  credentials: ConnectorCredentialField[]
  mappings: ConnectorMappingType[]
  fields: ConnectorField[]
}

export interface LookupOption {
  value: string
  label: string
  active: boolean
}

/** Výchozí nastavení nového připojení, skládá je server z definice a číselníků firmy. */
export interface ConnectionDefaults {
  status: IntegrationStatus
  mappings: Record<string, Record<string, string>>
  field_ownership: Record<string, IntegrationOwner>
  rate_limit_per_minute: number
  retention_days: number
}

export interface ConnectorCatalog {
  connectors: ConnectorDefinition[]
  owners: IntegrationOwner[]
  lookups: Record<MappingSource, LookupOption[]>
  defaults: Record<string, ConnectionDefaults>
}

export const eshopIntegrationsApi = {
  list: () => api.get<IntegrationConnection[]>('/eshop/integrations').then(r => r.data),
  connectors: () => api.get<ConnectorCatalog>('/eshop/integrations/connectors').then(r => r.data),
  create: (input: ConnectionInput) => api.post<IntegrationConnection>('/eshop/integrations', input).then(r => r.data),
  update: (id: number, input: ConnectionInput) => api.put<IntegrationConnection>(`/eshop/integrations/${id}`, input).then(r => r.data),
  createSample: () => api.post<IntegrationConnection>('/eshop/integrations/sample').then(r => r.data),
  remove: (id: number) => api.delete<{ deleted: boolean }>(`/eshop/integrations/${id}`).then(r => r.data),
  credentials: (id: number, credentials: Record<string, string>, clear: string[] = []) =>
    api.put<IntegrationConnection>(`/eshop/integrations/${id}/credentials`, { credentials, clear }).then(r => r.data),
  rotateWebhookSecret: (id: number) => api.post<{ connection_uuid: string; secret: string }>(`/eshop/integrations/${id}/webhook-secret`).then(r => r.data),
  diagnostics: (id: number) => api.get<IntegrationDiagnostics>(`/eshop/integrations/${id}/diagnostics`).then(r => r.data),
  reconcile: (id: number) => api.post<CatalogJob>(`/eshop/integrations/${id}/reconcile`).then(r => r.data),
  retryOutbox: (id: number, eventId: number) => api.post(`/eshop/integrations/${id}/outbox/${eventId}/retry`).then(r => r.data),
}
