import { api } from './client'

// ── Sdělení zdravotní pojišťovny zaměstnancem (§ 12 písm. b) zák. č. 48/1997 Sb.) ──
// Pojištěnec sdělí zaměstnavateli pojišťovnu při nástupu a změnu do osmi dnů,
// zaměstnavatel přijetí sdělení písemně potvrdí. Obě data patří k větě
// historie pojišťovny osoby; obsah hromadného oznámení (HOZ) se jimi nemění.

export interface PayrollHealthInsurerNotice {
  /** ID věty historie pojišťovny osoby. */
  id: number
  insurer_code: string
  insurer_name: string | null
  insurer_status: string
  effective_from: string
  effective_to: string | null
  /** Den, kdy zaměstnanec sdělil pojišťovnu. */
  employee_notified_on: string | null
  /** Den písemného potvrzení zaměstnavatelem. */
  employer_confirmed_on: string | null
  confirmation_available: boolean
}

export const payrollHealthInsurerNoticesApi = {
  list: (personId: number) =>
    api.get<{ items: PayrollHealthInsurerNotice[] }>(
      `/payroll/people/${personId}/health-insurer-notices`,
    ).then(response => response.data.items),

  record: (
    personId: number,
    coverageId: number,
    payload: { employee_notified_on: string | null, employer_confirmed_on: string | null },
  ) =>
    api.put<PayrollHealthInsurerNotice>(
      `/payroll/people/${personId}/health-insurer-notices/${coverageId}`,
      payload,
    ).then(response => response.data),

  /** Adresa PDF potvrzení; stahuje se přes `downloadApiFile`. */
  confirmationUrl: (personId: number, coverageId: number) =>
    `/payroll/people/${personId}/health-insurer-notices/${coverageId}/confirmation`,
}
