import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { api } from '@/api/client'
import { useSupplierStore } from '@/stores/supplier'
import { payrollWorkingPeriod } from '@/pages/payroll/payrollComponentsUi'

/*
 * Výchozí období mzdových obrazovek u firmy, která mzdy převzala z předchozího
 * programu.
 *
 * Obrazovky bez `?period=` otevírají předchozí kalendářní měsíc (v září se
 * dělá srpen). U firmy, která v MyÚčtu počítá mzdy od září, je ale srpen měsíc
 * předchozího programu: Měsíční přehled, JMHZ, docházka i rychlý vstup se
 * otevřely na převzatém měsíci, ve kterém se nic dělat nedá, a účetní musela
 * každou stránku přepínat ručně. Výchozí je proto první měsíc vedení mezd,
 * pokud je pozdější než zpracovávaný měsíc.
 */
const ROUTES = new Set([
  'payroll-quick-inputs',
  'payroll-time',
  'payroll-documents',
  'payroll-runs',
  'payroll-submissions',
  'payroll-submissions-tab',
])

export function startAwarePayrollPeriod(startPeriod: string | null, date = new Date()): string {
  const working = payrollWorkingPeriod(date)
  return startPeriod !== null && /^\d{4}-(0[1-9]|1[0-2])$/.test(startPeriod) && startPeriod > working
    ? startPeriod
    : working
}

const cache = new Map<number, Promise<string | null>>()

function startPeriod(supplierId: number): Promise<string | null> {
  let pending = cache.get(supplierId)
  if (pending === undefined) {
    pending = api.get<{ state?: { start_period?: string | null } }>('/payroll/capabilities')
      .then(response => response.data.state?.start_period ?? null)
      .catch(() => {
        cache.delete(supplierId)
        return null
      })
    cache.set(supplierId, pending)
  }
  return pending
}

export function resetPayrollStartPeriodCache(): void {
  cache.clear()
}

export async function payrollStartPeriodGuard(
  to: RouteLocationNormalized,
): Promise<true | RouteLocationRaw> {
  if (typeof to.name !== 'string' || !ROUTES.has(to.name)) return true
  if (to.query.period !== undefined) return true
  // Zdravotní agenda běží v osmidenních lhůtách od události a otevírá se
  // záměrně na dnešním měsíci.
  if (to.params.tab === 'health') return true
  let supplierId = 0
  try {
    supplierId = useSupplierStore().currentSupplierId
  } catch {
    return true
  }
  if (!supplierId) return true
  const period = startAwarePayrollPeriod(await startPeriod(supplierId))
  if (period === payrollWorkingPeriod()) return true

  return { ...to, query: { ...to.query, period } } as RouteLocationRaw
}
