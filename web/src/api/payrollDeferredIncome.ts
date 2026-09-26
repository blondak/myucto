import { api } from './client'

/**
 * Odložený příjem (JMHZ scénář 8, typ 10548) na kartě skončeného pracovního
 * vztahu: účetní potvrdí, že příjem zúčtovaný v měsíci po skončení vztahu je
 * odložený příjem. Mzdový běh si potvrzení zmrazí a hlášení ho vykáže
 * formulářem „Odložený příjem".
 */
export type PayrollDeferredIncomeType = '1' | '2' | '3' | '4' | '5' | '6'

export interface PayrollDeferredIncome {
  id: number
  employment_id: number
  /** První den měsíce zúčtování, `YYYY-MM-01`. */
  period_start: string
  deferred_type: PayrollDeferredIncomeType
  note: string | null
  row_version: number
  updated_at: string
}

export interface PayrollDeferredIncomeList {
  items: PayrollDeferredIncome[]
  /** Typy, které aplikace zpracuje sama (ostatní jdou přes ePortál ČSSZ). */
  supported_types: PayrollDeferredIncomeType[]
}

export const payrollDeferredIncomeApi = {
  list: (employmentId: number) =>
    api.get<PayrollDeferredIncomeList>(`/payroll/employments/${employmentId}/deferred-income`)
      .then(response => response.data),
  /** `period` ve tvaru `YYYY-MM`. */
  save: (
    employmentId: number,
    period: string,
    payload: { deferred_type: PayrollDeferredIncomeType, note: string | null },
  ) =>
    api.put<{ item: PayrollDeferredIncome }>(
      `/payroll/employments/${employmentId}/deferred-income/${period}`,
      payload,
    ).then(response => response.data.item),
  remove: (employmentId: number, period: string) =>
    api.delete<{ deleted: boolean }>(
      `/payroll/employments/${employmentId}/deferred-income/${period}`,
    ).then(response => response.data.deleted),
}
