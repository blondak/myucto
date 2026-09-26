import type { RouteLocationRaw } from 'vue-router'

/**
 * Položky projekce uzávěrky (ClosingProjectionCalculator) — popisek a stránka, ze které
 * částka pochází. Sdílí je náhled DPPO i odhad do konce roku ve výsledovce po účtech,
 * literály klíčů drží jmenný prostor `taxReturn` v mapě překladů obou stránek.
 */
export const CLOSING_PROJECTION_LABELS: Record<string, string> = {
  small_asset_accrual: 'taxReturn.proj_small_asset',
  prepaid_expense_accrual: 'taxReturn.proj_prepaid',
  fx_revaluation: 'taxReturn.proj_fx',
  prior_deferral_release: 'taxReturn.proj_prior_release',
  provision: 'taxReturn.proj_provision',
  estimate: 'taxReturn.proj_estimate',
  stock_closing: 'taxReturn.proj_stock',
  depreciation: 'taxReturn.proj_depreciation',
}

export function closingProjectionLabelKey(item: { key: string; label_key: string }): string {
  return CLOSING_PROJECTION_LABELS[item.key] ?? item.label_key
}

/** Odkaz na zdroj položky: odpisy vedou na majetek, ostatní na uzávěrku období. */
export function closingProjectionSource(key: string, periodId: number | null | undefined): { to: RouteLocationRaw; labelKey: string } | null {
  if (key === 'depreciation') {
    return { to: { name: 'accounting-assets' }, labelKey: 'taxReturn.proj_source_assets' }
  }
  if (!periodId) return null
  return { to: { name: 'accounting-period-closing', params: { id: periodId } }, labelKey: 'taxReturn.proj_source_closing' }
}
