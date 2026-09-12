import type { ConnectorDefinition, IntegrationOwner, LookupOption, MappingSource } from '@/api/eshopIntegrations'

/**
 * Převody mezi uloženým tvarem nastavení připojení a tabulkami editoru.
 *
 * Uložené mapování: `{ "warehouses": { "HLAVNI": "main-store" } }`
 * (typ → místní hodnota → hodnota v externím systému). Kontroly tady jen
 * předbíhají server, aby uživatel dostal chybu u řádku ještě před odesláním;
 * rozhoduje validace v IntegrationConnectionService.
 */

export const OWNERS: IntegrationOwner[] = ['local', 'remote', 'manual']
export const FREE_FIELD_PATTERN = /^[a-z][a-z0-9_.]{0,99}$/

export interface MappingRow { local: string; remote: string }
export type MappingRows = Record<string, MappingRow[]>

export interface MappingIssue {
  code: 'local_missing' | 'remote_missing' | 'duplicate' | 'unknown_type' | 'unknown_value' | 'not_object'
  type: string
  row?: number
  value?: string
}

export interface OwnershipRow {
  key: string
  owner: IntegrationOwner
  known: boolean
  defaultOwner: IntegrationOwner | null
  area: string | null
}

/** Rozepsané přístupové údaje: vyplněné hodnoty k přepsání a pole k odebrání. */
export interface CredentialDraft {
  values: Record<string, string>
  clear: string[]
}

export interface OwnershipIssue {
  code: 'unknown_field' | 'bad_owner'
  key: string
}

export function isPlainObject(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === 'object' && !Array.isArray(value)
}

export function asObject(value: unknown): Record<string, unknown> {
  return isPlainObject(value) ? value : {}
}

export function mappingRowsFrom(mappings: unknown, types: string[]): MappingRows {
  const source = asObject(mappings)
  const rows: MappingRows = {}
  for (const type of types) {
    const pairs = asObject(source[type])
    rows[type] = Object.entries(pairs).map(([local, remote]) => ({ local, remote: remote == null ? '' : String(remote) }))
  }
  return rows
}

export function mappingsFromRows(rows: MappingRows): { value: Record<string, Record<string, string>>; issues: MappingIssue[] } {
  const value: Record<string, Record<string, string>> = {}
  const issues: MappingIssue[] = []
  for (const [type, list] of Object.entries(rows)) {
    const pairs: Record<string, string> = {}
    list.forEach((row, index) => {
      const local = row.local.trim()
      const remote = row.remote.trim()
      if (local === '' && remote === '') return
      if (local === '') { issues.push({ code: 'local_missing', type, row: index + 1 }); return }
      if (remote === '') { issues.push({ code: 'remote_missing', type, row: index + 1, value: local }); return }
      if (local in pairs) { issues.push({ code: 'duplicate', type, value: local }); return }
      pairs[local] = remote
    })
    if (Object.keys(pairs).length) value[type] = pairs
  }
  return { value, issues }
}

/** Kontrola ručně psaného JSON mapování před převodem zpět do tabulek. */
export function validateMappingsObject(
  value: Record<string, unknown>,
  definition: ConnectorDefinition,
  lookups: Partial<Record<MappingSource, LookupOption[]>>,
): MappingIssue[] {
  const issues: MappingIssue[] = []
  const allowed = new Map(definition.mappings.map(mapping => [mapping.type, mapping.source]))
  for (const [type, pairs] of Object.entries(value)) {
    const source = allowed.get(type)
    if (!source) { issues.push({ code: 'unknown_type', type }); continue }
    if (!isPlainObject(pairs)) { issues.push({ code: 'not_object', type }); continue }
    const known = new Set((lookups[source] ?? []).map(option => option.value))
    for (const [local, remote] of Object.entries(pairs)) {
      if (!known.has(local)) issues.push({ code: 'unknown_value', type, value: local })
      else if (typeof remote !== 'string' || remote.trim() === '') issues.push({ code: 'remote_missing', type, value: local })
    }
  }
  return issues
}

export function ownershipRowsFrom(definition: ConnectorDefinition | null, stored: unknown): OwnershipRow[] {
  const owners = asObject(stored)
  const valid = (owner: unknown): owner is IntegrationOwner => OWNERS.includes(owner as IntegrationOwner)
  const rows: OwnershipRow[] = (definition?.fields ?? []).map(field => ({
    key: field.key,
    owner: valid(owners[field.key]) ? owners[field.key] as IntegrationOwner : field.default_owner,
    known: true,
    defaultOwner: field.default_owner,
    area: field.area,
  }))
  const knownKeys = new Set(rows.map(row => row.key))
  for (const [key, owner] of Object.entries(owners)) {
    if (knownKeys.has(key)) continue
    rows.push({ key, owner: valid(owner) ? owner : 'manual', known: false, defaultOwner: null, area: null })
  }
  return rows
}

export function ownershipFromRows(rows: OwnershipRow[]): Record<string, IntegrationOwner> {
  return Object.fromEntries(rows.map(row => [row.key, row.owner]))
}

export function validateOwnershipObject(value: Record<string, unknown>, definition: ConnectorDefinition): OwnershipIssue[] {
  const known = new Set(definition.fields.map(field => field.key))
  const issues: OwnershipIssue[] = []
  for (const [key, owner] of Object.entries(value)) {
    if (!known.has(key) && (!definition.free_fields || !FREE_FIELD_PATTERN.test(key))) {
      issues.push({ code: 'unknown_field', key })
    } else if (!OWNERS.includes(owner as IntegrationOwner)) {
      issues.push({ code: 'bad_owner', key })
    }
  }
  return issues
}

/** Parsuje JSON objekt; `null` znamená, že text není platný JSON objekt. */
export function parseJsonObject(text: string): Record<string, unknown> | null {
  try {
    const parsed = JSON.parse(text.trim() === '' ? '{}' : text)
    return isPlainObject(parsed) ? parsed : null
  } catch {
    return null
  }
}

/** Zástupná hodnota `<…>` z výchozího nastavení, kterou má uživatel nahradit skutečnou. */
export function isPlaceholderValue(value: string): boolean {
  return /^<[^<>]+>$/.test(value.trim())
}

/** i18n klíč pole: tečky by ve vue-i18n znamenaly vnoření, proto podtržítka. */
export function fieldI18nKey(fieldKey: string): string {
  return fieldKey.replace(/\./g, '_')
}
