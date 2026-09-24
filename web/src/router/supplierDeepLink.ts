import type { RouteLocationNormalized } from 'vue-router'
import { api } from '@/api/client'
import { useSupplierStore } from '@/stores/supplier'
import { useSupplierSwitch } from '@/composables/useSupplierSwitch'

/**
 * Detailové routy, jejichž záznam patří jedné firmě. Klíč = jméno routy, hodnota =
 * typ pro `GET /api/locate/{type}/{id}` a odkud vzít id (parametr cesty, u deníku query).
 */
const LOCATABLE: Record<string, { type: string, query?: string }> = {
  'invoice-detail': { type: 'invoice' },
  'invoice-edit': { type: 'invoice' },
  'purchase-invoice-detail': { type: 'purchase_invoice' },
  'purchase-invoice-edit': { type: 'purchase_invoice' },
  'document-detail': { type: 'document' },
  'other-item-detail': { type: 'other_item' },
  'other-item-edit': { type: 'other_item' },
  'accounting-cash-edit': { type: 'cash_document' },
  'stock-document-detail': { type: 'stock_document' },
  'stock-sales-order-detail': { type: 'sales_order' },
  'stock-purchase-order-detail': { type: 'purchase_order' },
  'bank-detail': { type: 'bank_statement' },
  'accounting-journal': { type: 'journal_entry', query: 'entry_id' },
}

function entityId(to: RouteLocationNormalized, query?: string): number {
  const raw = query ? to.query[query] : to.params.id
  const value = Array.isArray(raw) ? raw[0] : raw
  const id = Number(value)
  return Number.isInteger(id) && id > 0 ? id : 0
}

/**
 * Odkaz na doklad jiné firmy, do které uživatel smí: přepne firmu a otevře tentýž
 * doklad (celé přenačtení stránky). Vrací `true`, když přepnutí začalo — navigace se
 * pak zastaví a pokračuje po přenačtení. Selhání dotazu navigaci nebrání: detail pak
 * ukáže obvyklé „nenalezeno".
 */
export async function switchSupplierForDeepLink(to: RouteLocationNormalized): Promise<boolean> {
  const target = typeof to.name === 'string' ? LOCATABLE[to.name] : undefined
  if (!target) return false
  const supplierStore = useSupplierStore()
  if (!supplierStore.hasMultiple) return false
  const id = entityId(to, target.query)
  if (id === 0) return false

  let owner = 0
  try {
    const { data } = await api.get<{ supplier_id: number }>(`/locate/${target.type}/${id}`)
    owner = Number(data?.supplier_id ?? 0)
  } catch {
    return false
  }
  if (!owner || owner === supplierStore.currentSupplierId) return false
  if (!supplierStore.availableSuppliers.some(s => s.id === owner)) return false

  await useSupplierSwitch().switchTo(owner, to.fullPath)
  return true
}
