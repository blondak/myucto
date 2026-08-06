import { api } from './client'

export type TravelTransportMode =
  | 'public_transport' | 'company_vehicle' | 'private_vehicle' | 'other'
export type TravelItemKind = 'transport' | 'accommodation' | 'incidental' | 'private_vehicle'
export type TravelVehicleKind = 'car' | 'single_track'
export type TravelFuelKind = 'petrol_95' | 'petrol_98' | 'diesel' | 'electricity'
export type TravelTripStatus = 'draft' | 'approved' | 'settled' | 'cancelled'

export interface TravelTripItem {
  id: number
  item_kind: TravelItemKind
  spent_on: string
  description: string
  amount_minor: number | null
  is_documented: boolean
  document_reference: string | null
  vehicle_kind: TravelVehicleKind | null
  distance_m: number | null
  consumption_ml_per_100km: number | null
  fuel_kind: TravelFuelKind | null
  documented_fuel_price_minor: number | null
  sort_order: number
}

export interface TravelMealDay {
  kind: 'meal_allowance'
  date: string
  minutes: number
  band: number
  free_meals: number
  base_rate_minor: number
  statutory_minimum_minor: number
  tax_exempt_maximum_minor: number
  entitlement_minor: number
  exempt_minor: number
  taxable_minor: number
  ruleset_id: string
  rule?: string
  merged_dates?: string[]
  note?: string
}

export interface TravelCalculationItem {
  kind: TravelItemKind
  item_id: number | null
  date: string
  description: string
  documented: boolean
  entitlement_minor: number
  exempt_minor: number
  taxable_minor: number
  basic_compensation_minor?: number
  fuel_volume_ml?: number
  fuel_cost_minor?: number
  fuel_price_per_unit_minor?: number
  fuel_price_documented?: boolean
  distance_m?: number
}

export interface TravelCalculationStep {
  label: string
  input_minor_units: number
  rate: { decimal: string; numerator: number; scale: number; denominator: number }
  unrounded_numerator: number
  unrounded_denominator: number
  rounding_mode: string
  output_minor_units: number
}

export interface TravelCalculation {
  status: 'supported' | 'manual_review'
  blockers: string[]
  ruleset_ids: string[]
  meal_days: TravelMealDay[]
  items: TravelCalculationItem[]
  entitlement_total_minor: number
  exempt_total_minor: number
  taxable_total_minor: number
  advance_minor: number
  settlement_difference_minor: number
  steps: TravelCalculationStep[]
}

export interface TravelTrip {
  id: number
  employee_id: number
  employment_id: number
  employee_name: string
  employment_code: string
  relation_type: string
  country_code: string
  departure_at: string
  arrival_at: string
  origin_place: string
  destination_place: string
  purpose: string
  transport_mode: TravelTransportMode
  meal_rate_band_1_minor: number | null
  meal_rate_band_2_minor: number | null
  meal_rate_band_3_minor: number | null
  advance_minor: number
  settlement_period_start: string
  status: TravelTripStatus
  entitlement_total_minor: number | null
  exempt_total_minor: number | null
  taxable_total_minor: number | null
  ruleset_id: string | null
  calculation: TravelCalculation | null
  row_version: number
  items: TravelTripItem[]
  free_meals: Record<string, number>
}

export interface TravelTripItemPayload {
  item_kind: TravelItemKind
  spent_on: string
  description: string
  amount?: string | null
  is_documented?: boolean
  document_reference?: string | null
  vehicle_kind?: TravelVehicleKind | null
  fuel_kind?: TravelFuelKind | null
  distance_km?: string | null
  consumption_per_100km?: string | null
  documented_fuel_price?: string | null
}

export interface TravelTripPayload {
  employee_id: number | null
  employment_id: number | null
  country_code: string
  departure_at: string
  arrival_at: string
  origin_place: string
  destination_place: string
  purpose: string
  transport_mode: TravelTransportMode
  meal_rate_band_1?: string | null
  meal_rate_band_2?: string | null
  meal_rate_band_3?: string | null
  advance?: string | null
  settlement_period: string
  items: TravelTripItemPayload[]
  free_meals: { meal_date: string; meal_count: number }[]
  row_version?: number
}

export interface TravelMaterialization {
  status: string
  trip_id: number
  period: string
  created_count: number
  replayed_count: number
  created: { part: string; component_code: string; input_id: number; amount_minor: number }[]
  replayed: { part: string; component_code: string; input_id: number; amount_minor: number }[]
}

export const payrollTravelApi = {
  list: (period?: string) =>
    api.get<{ trips: TravelTrip[] }>('/payroll/travel/trips', {
      params: period ? { period } : {},
    }).then(response => response.data.trips),
  preview: (payload: TravelTripPayload) =>
    api.post<{ calculation: TravelCalculation }>('/payroll/travel/preview', payload)
      .then(response => response.data.calculation),
  create: (payload: TravelTripPayload) =>
    api.post<{ trip: TravelTrip }>('/payroll/travel/trips', payload)
      .then(response => response.data.trip),
  update: (id: number, payload: TravelTripPayload) =>
    api.put<{ trip: TravelTrip }>(`/payroll/travel/trips/${id}`, payload)
      .then(response => response.data.trip),
  calculation: (id: number) =>
    api.get<{ trip: TravelTrip; calculation: TravelCalculation }>(
      `/payroll/travel/trips/${id}/calculation`,
    ).then(response => response.data),
  approve: (id: number, rowVersion: number) =>
    api.post<{ trip: TravelTrip; calculation: TravelCalculation }>(
      `/payroll/travel/trips/${id}/approve`,
      { row_version: rowVersion },
    ).then(response => response.data),
  materialize: (id: number) =>
    api.post<{ materialization: TravelMaterialization }>(
      `/payroll/travel/trips/${id}/materialize`,
      {},
    ).then(response => response.data.materialization),
}
