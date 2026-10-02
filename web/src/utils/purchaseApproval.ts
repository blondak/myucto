import type { PurchaseInvoice } from '@/api/purchaseInvoices'
import type { DimensionType, DimensionValue } from '@/api/dimensions'

type Translate = (key: string, params?: Record<string, unknown>) => string

/** Minimální rozhraní toastu, aby šel util testovat bez celé komposable. */
interface ToastLike {
  info: (message: string) => unknown
}

/**
 * Odpověď přechodu draft → received u dokladu, který vyžaduje schválení: doklad zůstal
 * konceptem a čeká na schvalovatele. Místo „přijato" se ukáže toast „odesláno ke schválení".
 * Vrací true, když šlo o tento případ (volající pak nehlásí úspěšný přechod).
 */
export function announceApprovalRequested(
  result: Pick<PurchaseInvoice, 'approval_requested'> | null | undefined,
  toast: Pick<ToastLike, 'info'>,
  t: Translate,
): boolean {
  if (result?.approval_requested !== true) return false
  toast.info(t('purchase_approval.toast.requested'))
  return true
}

/**
 * Typy dimenzí, které vyžadují schválení a dokladu u nich chybí hodnota v hlavičce.
 * Položkové hodnoty se nepočítají: schvalovatele určuje i středisko z položky, ale
 * chybějící hodnota na položce není chyba (stejně jako u povinných dimenzí).
 */
export function missingApprovalTypes(
  types: readonly DimensionType[],
  header: Record<number, number | null | undefined>,
  itemValueIds: readonly number[] = [],
  values: readonly DimensionValue[] = [],
): DimensionType[] {
  const fromItems = new Set(values.filter(v => itemValueIds.includes(v.id)).map(v => v.type_id))
  return types.filter(ty =>
    ty.is_active && ty.requires_approval === true && !header[ty.id] && !fromItems.has(ty.id))
}
