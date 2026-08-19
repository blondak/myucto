import { api } from './client'
import type { PayrollRegzelEnvironment } from './payroll'

// ── Záměr uplatňovat slevu na pojistném (OZUSPOJ) ───────────────────────────
// Sleva zaměstnavatele za kratší úvazky podle § 7a zák. č. 589/1992 Sb. se
// vykazuje v měsíčním hlášení, ale NÁROK na ni zakládá úplně jiné podání:
// oznámení záměru podle § 23e, které ČSSZ vede v evidenci podle § 23f.
// Bez doručeného záměru se sleva neuzná, a protože je kontrola 291 propustná,
// pozná se to až z protokolu — kdy je pojistné odvedené ponížené a § 7c odst. 3
// z rozdílu dělá dluh. Proto tenhle panel: záměr se eviduje, podává a teprve
// jeho PŘIJETÍ pouští slevu do výpočtu.

export type PayrollDiscountIntentStatus =
  | 'draft'
  | 'submitted'
  | 'accepted'
  | 'rejected'
  | 'ended'
  | 'cancelled'

/** `typPodani` datové věty OZUSPOJ23: 1 zahájení, 2 skončení, 3 storno. */
export type PayrollDiscountIntentSubmissionKind = 'start' | 'end' | 'cancellation'

export interface PayrollDiscountIntent {
  id: number
  employment_id: number
  employee_id: number
  employee_name: string
  discount_reason: string
  intent_from: string
  intent_to: string | null
  status: PayrollDiscountIntentStatus
  /** Den DORUČENÍ oznámení ČSSZ — na něm podle § 7a odst. 5 stojí nárok. */
  accepted_on: string | null
  ended_accepted_on: string | null
  rejection_reason: string | null
  employee_informed_on: string | null
  ossz_code: number
  row_version: number
  /** Doloží tenhle záměr slevu, nebo se sleva neuplatní? */
  evidences_discount: boolean
  earliest_notification_on: string
  notification_due_on: string
  /** Období 01–03/2026 mají vlastní hranici 30. 6. 2026 (kontrola 333). */
  transitional_q1_2026: boolean
}

export interface PayrollDiscountIntentPreview {
  intent_id: number
  agenda_code: string
  submission_kind: string
  xml: string
  xml_sha256: string
  window: { earliest_notification_on: string; due_on: string }
  official_submission: { supported: boolean; reason: string }
}

export interface PayrollDiscountIntentPrepared {
  intent_id: number
  submission_id: number
  obligation_id: number
  status: string
  agenda_code: string
  submission_kind: string
  artifact_sha256: string
  created: boolean
}

export const payrollDiscountIntentsApi = {
  list: (
    environment: PayrollRegzelEnvironment,
    employmentId?: number,
  ) =>
    api.get<{ items: PayrollDiscountIntent[] }>(
      '/payroll/submissions/discount-intents',
      { params: { environment, employment_id: employmentId || undefined } },
    ).then(response => response.data.items),

  create: (
    environment: PayrollRegzelEnvironment,
    payload: {
      employment_id: number
      intent_from: string
      employee_informed_on?: string | null
    },
  ) =>
    api.post<PayrollDiscountIntent>(
      '/payroll/submissions/discount-intents',
      { ...payload, environment },
    ).then(response => response.data),

  preview: (
    id: number,
    environment: PayrollRegzelEnvironment,
    kind: PayrollDiscountIntentSubmissionKind,
  ) =>
    api.get<PayrollDiscountIntentPreview>(
      `/payroll/submissions/discount-intents/${id}/preview`,
      { params: { environment, submission_kind: kind } },
    ).then(response => response.data),

  prepare: (
    id: number,
    environment: PayrollRegzelEnvironment,
    kind: PayrollDiscountIntentSubmissionKind,
  ) =>
    api.post<PayrollDiscountIntentPrepared>(
      `/payroll/submissions/discount-intents/${id}/prepare`,
      { environment, submission_kind: kind },
    ).then(response => response.data),

  requestEnd: (
    id: number,
    environment: PayrollRegzelEnvironment,
    intentTo: string,
  ) =>
    api.post<PayrollDiscountIntent>(
      `/payroll/submissions/discount-intents/${id}/end`,
      { environment, intent_to: intentTo },
    ).then(response => response.data),

  /**
   * Výsledek zpracování od ČSSZ. `accepted_on` se opisuje z protokolu, nikdy
   * se nedosazuje „dnes" — den doručení rozhoduje i o pořadí mezi
   * zaměstnavateli podle § 7a odst. 5 věty třetí.
   */
  recordReceipt: (
    id: number,
    environment: PayrollRegzelEnvironment,
    payload: {
      outcome: 'accepted' | 'rejected' | 'ended' | 'cancelled'
      accepted_on?: string | null
      reason?: string | null
    },
  ) =>
    api.post<PayrollDiscountIntent>(
      `/payroll/submissions/discount-intents/${id}/receipt`,
      { ...payload, environment },
    ).then(response => response.data),
}
