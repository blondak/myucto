import type { LocationQuery, LocationQueryRaw } from 'vue-router'
import type {
  PayrollInput,
  PayrollInputBatchFailure,
  PayrollInputSourceKind,
  PayrollInputStatus,
} from '@/api/payroll'

/**
 * Filtr seznamu mzdových vstupů — stav, adresa a parametry serveru na jednom
 * místě.
 *
 * Po importu docházky má měsíc stovky vstupů. Filtr proto žije v adrese:
 * odkaz z blokátoru běhu („koncepty měsíce") i sdílený odkaz na konkrétní
 * dávku musí otevřít přesně tentýž výřez, a obnovení stránky ho nesmí zahodit.
 */

export type PayrollInputGroupBy = 'employee' | 'component'
export type PayrollInputFilterStatus = Exclude<PayrollInputStatus, 'cancelled'>

export const PAYROLL_INPUT_FILTER_STATUSES: readonly PayrollInputFilterStatus[] = ['draft', 'approved', 'locked']
export const PAYROLL_INPUT_SOURCE_KINDS: readonly PayrollInputSourceKind[] = [
  'manual', 'recurring', 'time', 'absence', 'import', 'correction', 'travel',
]
const GROUP_BY: readonly PayrollInputGroupBy[] = ['employee', 'component']

/**
 * Zdroje, u kterých jde koncept upravit přímo v seznamu.
 *
 * Importovaný koncept je jen částka ze souboru — oprava před schválením je
 * běžná věc a server jí zachová původ i `external_id`. Vstupy z pravidelného
 * předpisu, docházky, absence a pracovní cesty ne: jejich částka je odvozená
 * z navázané evidence, a oprava by ji od ní odtrhla. Ty se opravují u zdroje.
 */
export const PAYROLL_INPUT_EDITABLE_SOURCES: readonly PayrollInputSourceKind[] = ['manual', 'import', 'correction']

export interface PayrollInputFilterState {
  q: string
  employeeId: number | null
  componentIds: number[]
  sourceKind: PayrollInputSourceKind | null
  statuses: PayrollInputFilterStatus[]
  importId: number | null
  groupBy: PayrollInputGroupBy | null
}

/** Klíče filtru v adrese; ostatní klíče (`tab`, `period`, `employment`) se nechávají být. */
const QUERY_KEYS = ['q', 'employee', 'component', 'source', 'status', 'import', 'group'] as const

export function emptyPayrollInputFilters(): PayrollInputFilterState {
  return {
    q: '',
    employeeId: null,
    componentIds: [],
    sourceKind: null,
    statuses: [],
    importId: null,
    groupBy: null,
  }
}

function queryString(query: LocationQuery, name: string): string {
  const value = query[name]
  const raw = Array.isArray(value) ? value[0] : value
  return typeof raw === 'string' ? raw.trim() : ''
}

function positiveId(raw: string): number | null {
  const id = Number(raw)
  return raw !== '' && Number.isInteger(id) && id > 0 ? id : null
}

function list(raw: string): string[] {
  return Array.from(new Set(raw.split(',').map(item => item.trim()).filter(item => item !== '')))
}

/** Neplatné hodnoty z adresy se tiše zahodí — slepý odkaz nesmí rozbít stránku. */
export function payrollInputFiltersFromQuery(query: LocationQuery): PayrollInputFilterState {
  const source = queryString(query, 'source')
  const group = queryString(query, 'group')

  return {
    q: queryString(query, 'q').slice(0, 100),
    employeeId: positiveId(queryString(query, 'employee')),
    componentIds: list(queryString(query, 'component'))
      .map(positiveId)
      .filter((id): id is number => id !== null),
    sourceKind: (PAYROLL_INPUT_SOURCE_KINDS as readonly string[]).includes(source)
      ? source as PayrollInputSourceKind
      : null,
    statuses: list(queryString(query, 'status'))
      .filter((status): status is PayrollInputFilterStatus =>
        (PAYROLL_INPUT_FILTER_STATUSES as readonly string[]).includes(status)),
    importId: positiveId(queryString(query, 'import')),
    groupBy: (GROUP_BY as readonly string[]).includes(group) ? group as PayrollInputGroupBy : null,
  }
}

/** Adresa s filtrem: klíče filtru se nahradí, ostatní zůstanou. */
export function payrollInputFiltersToQuery(
  current: LocationQuery,
  state: PayrollInputFilterState,
): LocationQueryRaw {
  const next: LocationQueryRaw = { ...current }
  for (const key of QUERY_KEYS) delete next[key]
  const q = state.q.trim()
  if (q !== '') next.q = q
  if (state.employeeId !== null) next.employee = String(state.employeeId)
  if (state.componentIds.length > 0) next.component = state.componentIds.join(',')
  if (state.sourceKind !== null) next.source = state.sourceKind
  if (state.statuses.length > 0) next.status = state.statuses.join(',')
  if (state.importId !== null) next.import = String(state.importId)
  if (state.groupBy !== null) next.group = state.groupBy

  return next
}

/** Parametry pro `GET /payroll/inputs` i `filter` hromadných akcí — tentýž výřez. */
export function payrollInputFilterParams(state: PayrollInputFilterState): Record<string, string | number> {
  const params: Record<string, string | number> = {}
  const q = state.q.trim()
  if (q !== '') params.q = q
  if (state.employeeId !== null) params.employee_id = state.employeeId
  if (state.componentIds.length > 0) params.component_id = state.componentIds.join(',')
  if (state.sourceKind !== null) params.source_kind = state.sourceKind
  if (state.statuses.length > 0) params.status = state.statuses.join(',')
  if (state.importId !== null) params.import_id = state.importId

  return params
}

/** Zúžuje filtr výpis? Seskupení nic nezužuje, jen mění pohled. */
export function payrollInputFiltersActive(state: PayrollInputFilterState): boolean {
  return Object.keys(payrollInputFilterParams(state)).length > 0
}

export function payrollInputListEditable(input: Pick<PayrollInput, 'status' | 'source_kind'>): boolean {
  return input.status === 'draft' && PAYROLL_INPUT_EDITABLE_SOURCES.includes(input.source_kind)
}

/**
 * Stejné důvody se sečtou do jedné věty — u pěti set vstupů jich bývá pár
 * druhů, ne pět set.
 */
export function groupPayrollInputFailures(
  failures: PayrollInputBatchFailure[],
): Array<{ message: string, count: number }> {
  const counts = new Map<string, number>()
  for (const failure of failures) {
    counts.set(failure.message, (counts.get(failure.message) ?? 0) + 1)
  }
  return Array.from(counts, ([message, count]) => ({ message, count }))
}

export interface PayrollInputBatchPass {
  done: number[]
  failed: PayrollInputBatchFailure[]
  skipped: PayrollInputBatchFailure[]
  remaining?: number
  complete?: boolean
  next_after_id?: number
}

export interface PayrollInputBatchTotal {
  done: number
  failed: PayrollInputBatchFailure[]
  skipped: number
  remaining: number
  complete: boolean
}

/**
 * Opakuje hromadnou akci, dokud server nehlásí `complete`.
 *
 * Server má na jeden požadavek časový rozpočet; na velkém měsíci vrátí kurzor
 * a pokračuje se od něj. Smyčka se zastaví i tehdy, když se kurzor nepohnul —
 * jinak by se při chybě na serveru točila donekonečna.
 */
export async function runPayrollInputBatch(
  call: (afterId: number) => Promise<PayrollInputBatchPass>,
  onProgress?: (done: number) => void,
  maxPasses = 200,
): Promise<PayrollInputBatchTotal> {
  let afterId = 0
  let done = 0
  let skipped = 0
  const failed: PayrollInputBatchFailure[] = []
  let remaining = 0
  let complete = false
  for (let pass = 0; pass < maxPasses; pass++) {
    const result = await call(afterId)
    done += result.done.length
    skipped += result.skipped.length
    failed.push(...result.failed)
    remaining = result.remaining ?? 0
    complete = result.complete ?? true
    onProgress?.(done)
    const next = result.next_after_id ?? 0
    if (complete || next <= afterId) break
    afterId = next
  }

  return { done, failed, skipped, remaining, complete }
}
