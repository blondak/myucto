import { api } from './client'

/** Taxonomie číselníku — do OSS podání jde jen „základní vs. ostatní", ne procento. */
export type OssRateType = 'standard' | 'reduced' | 'second_reduced' | 'parking'

export interface OssMemberStateRate {
  id: number
  country: string
  rate_type: OssRateType
  rate_percent: number
  valid_from: string
  /** Vlastní konec platnosti seedu; NULL = seed neupravován. */
  valid_to: string | null
  valid_to_override: string | null
  /** COALESCE(valid_to_override, valid_to) — proti čemu se doklad opravdu ověřuje. */
  effective_valid_to: string | null
  /** true = řádek doplnil uživatel; false = seed migrace (needituje se, jen překrývá). */
  is_custom: boolean
  disabled: boolean
  disabled_at: string | null
  note: string | null
  created_at: string
  updated_at: string | null
}

export interface OssMemberStateRatesResponse {
  /** false = chybí migrace 1152, číselník v databázi vůbec není. */
  available: boolean
  /** false = chybí migrace 1296, číselník jde jen číst. */
  manageable: boolean
  /** Zápis do globálního číselníku smí jen správce instance. */
  can_write: boolean
  rate_types: OssRateType[]
  rates: OssMemberStateRate[]
}

export interface OssRateCreate {
  country: string
  rate_type: OssRateType
  rate_percent: number
  valid_from: string
  valid_to?: string | null
  note?: string | null
}

export interface OssRatePatch {
  country?: string
  rate_type?: OssRateType
  rate_percent?: number
  valid_from?: string
  valid_to?: string | null
  note?: string | null
  /** Jen u seedu: zkrácení platnosti bez zásahu do seedovaných sloupců. */
  valid_to_override?: string | null
  disabled?: boolean
}

const BASE = '/codebooks/oss-member-state-rates'

export const ossRatesApi = {
  list: (country?: string) =>
    api.get<OssMemberStateRatesResponse>(BASE, {
      params: country ? { country } : {},
    }).then(r => r.data),
  create: (data: OssRateCreate) =>
    api.post<{ id: number; rates: OssMemberStateRate[] }>(BASE, data).then(r => r.data),
  update: (id: number, data: OssRatePatch) =>
    api.put<{ id: number; rates: OssMemberStateRate[] }>(`${BASE}/${id}`, data).then(r => r.data),
  remove: (id: number) =>
    api.delete<{ ok: boolean; rates: OssMemberStateRate[] }>(`${BASE}/${id}`).then(r => r.data),
}
