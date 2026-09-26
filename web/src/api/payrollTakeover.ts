/**
 * Převzatá část roku přechodu — typy nálezů, které vrací vyúčtování daně,
 * uzávěrka mzdového roku a kontrola převzetí.
 */

/** Osoba, které v převzatém měsíci trval vztah, ale počáteční stav ho nemá. */
export interface TakeoverGap {
  employee_id: number
  employee_name: string
  missing_months: number[]
}

export type TakeoverLayerMetric =
  | 'tax_base'
  | 'advance_tax'
  | 'withholding_tax'
  | 'tax_bonus'
  | 'social_base'
  | 'health_base'

/** Rozdíl mezi počátečním stavem (A) a převzatou mzdou (B) za týž měsíc. */
export interface TakeoverLayerDifference {
  employee_id: number
  employee_name: string
  period: string
  metric: TakeoverLayerMetric
  opening_minor: number
  takeover_minor: number
  difference_minor: number
}

/** Měsíce, které má jen jedna z vrstev. */
export interface TakeoverLayerOneSided {
  employee_id: number
  employee_name: string
  periods: string[]
}

export interface TakeoverCheck {
  takeover_months: number[]
  missing_openings: TakeoverGap[]
  differences: TakeoverLayerDifference[]
  opening_only: TakeoverLayerOneSided[]
  takeover_only: TakeoverLayerOneSided[]
}
