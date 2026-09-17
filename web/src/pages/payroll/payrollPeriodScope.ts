import type { LocationQuery, LocationQueryRaw } from 'vue-router'

/**
 * Rozsah období u agend zúžených na jeden pracovní vztah.
 *
 * Mzdové agendy jsou postavené na MĚSÍCI — tak se mzdy počítají, schvalují
 * i podávají. Jakmile se ale seznam zúží na jednoho člověka (odkaz z karty
 * zaměstnance, `?employment=12`), měsíc přestane stačit: účetní se ptá „co
 * tenhle člověk bral loni", ne „co bral v srpnu". Přepínat období po jednom
 * měsíci a pamatovat si čísla v hlavě není odpověď.
 *
 * Rozsah proto žije v adrese vedle období: obnovení stránky ani sdílený odkaz
 * ho nesmí zahodit, stejně jako u filtru v `payrollInputFilters.ts`.
 */
export type PayrollPeriodScope = 'month' | 'year' | 'all'

export const PAYROLL_PERIOD_SCOPES: readonly PayrollPeriodScope[] = ['month', 'year', 'all']

export interface PayrollPeriodRange {
  /** První měsíc rozsahu, `YYYY-MM`. */
  from: string
  /** Poslední měsíc rozsahu včetně, `YYYY-MM`. */
  to: string
}

/**
 * Mantinely rozsahu „vše".
 *
 * Server vyžaduje období vždy (`period` je povinné), takže „vše" se posílá jako
 * dostatečně široký rozsah. Meze jsou schválně mimo jakoukoli reálnou mzdovou
 * evidenci: dotaz jede po indexu `(supplier_id, period_start)` a zúžení na
 * vztah ho drží malý, takže šířka rozsahu nic nestojí. Vrátí se jen měsíce,
 * ve kterých něco je — prázdné měsíce mezi nimi nevzniknou.
 */
const FLOOR = '1990-01'
const CEILING = '2099-12'

/** Klíč rozsahu v adrese; `month` se nezapisuje, je to výchozí stav. */
const QUERY_KEY = 'scope'

export function payrollPeriodScopeFromQuery(query: LocationQuery): PayrollPeriodScope {
  const raw = query[QUERY_KEY]
  const value = Array.isArray(raw) ? raw[0] : raw

  return typeof value === 'string'
    && (PAYROLL_PERIOD_SCOPES as readonly string[]).includes(value)
    ? value as PayrollPeriodScope
    : 'month'
}

/** Adresa s rozsahem; ostatní klíče zůstávají. */
export function payrollPeriodScopeToQuery(
  current: LocationQuery,
  scope: PayrollPeriodScope,
): LocationQueryRaw {
  const next: LocationQueryRaw = { ...current }
  if (scope === 'month') delete next[QUERY_KEY]
  else next[QUERY_KEY] = scope

  return next
}

/**
 * Rozsah měsíců pro dotaz na server; `null` u rozsahu „měsíc" — tam se nic
 * nemění a volající posílá samotné období jako dosud.
 *
 * Rok se bere z vybraného období, ne z kalendáře: účetní se v září dívá na
 * srpen, a když si přepne na „rok", čeká rok TOHO období.
 */
export function payrollPeriodRange(
  scope: PayrollPeriodScope,
  period: string,
): PayrollPeriodRange | null {
  if (scope === 'month') return null
  if (scope === 'all') return { from: FLOOR, to: CEILING }
  const year = period.slice(0, 4)

  return { from: `${year}-01`, to: `${year}-12` }
}
