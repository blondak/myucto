import type { PurchaseInvoice } from '@/api/purchaseInvoices'

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
