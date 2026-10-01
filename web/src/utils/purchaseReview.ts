import type { PurchaseInvoiceMissingDimension, PurchaseInvoiceReview } from '@/api/purchaseInvoices'

/**
 * Potřebuje doklad okno Kontrola vytěžených dokladů? Rozhoduje backend (`review`,
 * PurchaseInvoiceReviewNeeds) — hlášení vytěžení, chybějící povinná dimenze a další
 * důvody. Bez `review` (starší odpověď, endpoint bez něj) platí jen hlášení vytěžení.
 */
export function needsReview(inv: { extraction_warning?: string | null; review?: PurchaseInvoiceReview | null }): boolean {
  if (inv.review) return inv.review.reasons.length > 0
  return !!inv.extraction_warning
}

/** Povinné dimenze, které dokladu chybí (prázdné, když nic nechybí). */
export function missingDimensions(inv: { review?: PurchaseInvoiceReview | null } | null | undefined): PurchaseInvoiceMissingDimension[] {
  return inv?.review?.details?.missing_required_dimension?.missing_dimensions ?? []
}
