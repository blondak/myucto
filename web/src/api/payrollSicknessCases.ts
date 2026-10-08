import { api } from './client'
import type { PayrollRegzelEnvironment } from './payroll'

// ── Případy dávek nemocenského pojištění (NEMPRI, HZUPN) ────────────────────
// Dvě podání s vlastními lhůtami podle § 97 zák. č. 187/2006 Sb.:
//
//   NEMPRI — oznámení zaměstnavatele o žádosti zaměstnance o dávku a podklady
//            pro výpočet (odst. 1 a 2). U nemocenského „neprodleně po uplynutí
//            prvních 14 dnů trvání dočasné pracovní neschopnosti".
//   HZUPN  — hlášení při ukončení pracovní neschopnosti, tedy oznámení
//            skutečností, které mohou mít vliv na výplatu dávek (odst. 3).
//
// Případ žije v evidenci i bez podání. Je to celý smysl: lhůta běží od 15. dne
// neschopnosti bez ohledu na to, jestli si toho někdo všiml, a nesplnění je
// přestupek podle § 130 odst. 1 písm. c) a d).

/** `dokument/druhDavky`, tedy `StDruhDavky` z NEMPRI25.xsd. */
export type PayrollSicknessBenefitKind = 'NEM' | 'VPM' | 'OPP' | 'PPM' | 'OSE' | 'DLO'

/**
 * Společný stav případu, odvozený ze stavů obou podání. NENÍ to stav podání:
 * `prepared` znamená „XML je zmrazené", povinnost splní až předání ČSSZ;
 * `submitted` = část podání vyřízena, další čeká; `accepted` = vše vyřízeno.
 */
export type PayrollSicknessCaseStatus =
  | 'draft'
  | 'prepared'
  | 'submitted'
  | 'accepted'
  | 'rejected'
  | 'cancelled'

/**
 * Který tiskopis se z případu staví. `nempri_transfer` je druhé oznámení
 * NEMPRI s rozhodným obdobím ke dni převedení na jinou práci (§ 19 odst. 6,
 * Všeobecné zásady NEMPRI); podává se jen u převedené zaměstnankyně.
 */
export type PayrollSicknessDocumentKind = 'nempri' | 'nempri_transfer' | 'hzupn'

/**
 * Stav jednoho podání. NEMPRI a HZUPN mají vlastní lhůty (§ 97 odst. 1–3),
 * takže přijaté NEMPRI neuzavře čekající HZUPN. `predecessor` = podal
 * předchozí mzdový program.
 */
export type PayrollSicknessDocumentStatus = 'pending' | 'accepted' | 'rejected' | 'predecessor'

/** Odkud případ je: z MyÚčta, nebo převzatý z předchozího mzdového programu. */
export type PayrollSicknessCaseSource = 'myucto' | 'predecessor'

/** Důvod převedení na jinou práci (§ 19 odst. 6 zák. č. 187/2006 Sb.). */
export type PayrollSicknessTransferReason = 'pregnancy' | 'maternity' | 'breastfeeding'

export interface PayrollSicknessWorkInterval {
  from: string
  to: string
}

/** Důvod péče u ošetřovného (`onemocnela` … `uzavrenaSkola`). */
export type PayrollSicknessCareReason = 'ill' | 'quarantine' | 'cannot_care' | 'school_closed'

/**
 * Ručně doplněný měsíc rozhodného období. Posílá se jen za měsíce, které
 * nepokrývá měsíční hlášení z MyÚčta a ke kterým chybí převzatá mzda.
 */
export interface PayrollSicknessDecisiveMonth {
  /** `RRRR-MM` */
  period: string
  income_minor: number
  excluded_days: number
}

export interface PayrollSicknessCase {
  id: number
  employee_id: number
  employment_id: number
  full_name: string
  benefit_kind: PayrollSicknessBenefitKind
  ossz_code: number
  decision_number: string | null
  foreign_case: number
  /** Slovenská neschopenka: NEMPRI ji hlásí jako zahraniční, HZUPN jako českou (N). */
  slovak_case: number
  correction: number
  incapacity_from: string
  incapacity_to: string | null
  issued_on: string | null
  payroll_payment_date: string | null
  worked_on_decisive_day: number
  hours_worked: string | null
  daily_working_hours: string | null
  small_scope_income_minor: number | null
  receives_pension: number
  pension_kind: string | null
  is_student: number
  within_school_holidays: number | null
  first_employment_free_time: number
  unpaid_leave: number
  unpaid_leave_from: string | null
  unpaid_leave_to: string | null
  starts_maternity: number | null
  child_birth_date: string | null
  transferred_other_work: number
  transferred_on: string | null
  transfer_reason?: PayrollSicknessTransferReason | null
  enforcement: number
  insolvency: number
  returned_to_work: number | null
  return_reason: string | null
  returned_on: string | null
  hours_worked_last_day: string | null
  shift_hours_last_day: string | null
  additional_note: string | null
  status: PayrollSicknessCaseStatus
  nempri_status: PayrollSicknessDocumentStatus
  /** Den DORUČENÍ NEMPRI ČSSZ z protokolu, ne den přípravy. */
  nempri_accepted_on: string | null
  nempri_rejection_reason: string | null
  hzupn_status: PayrollSicknessDocumentStatus
  /** Den DORUČENÍ HZUPN ČSSZ z protokolu. */
  hzupn_accepted_on: string | null
  hzupn_rejection_reason: string | null
  /** Druhé oznámení ke dni převedení; `null` = nepodává se. */
  nempri_transfer_status: PayrollSicknessDocumentStatus | null
  nempri_transfer_accepted_on: string | null
  nempri_transfer_rejection_reason: string | null
  cancelled: number
  source: PayrollSicknessCaseSource
  /** Odkaz na převzatý záznam předchozího programu. */
  external_reference: string | null
  nempri_submission_id: number | null
  hzupn_submission_id: number | null
  nempri_transfer_submission_id: number | null
  row_version: number
  work_days: PayrollSicknessWorkInterval[]
  // ── žádost o dávku (OSE, DLO, OPP, PPM) ──
  action_start: number
  action_continuation: number
  action_end: number
  application_from: string | null
  application_to: string | null
  cared_dependant_id: number | null
  cared_first_name: string | null
  cared_last_name: string | null
  cared_birth_date: string | null
  care_reason: PayrollSicknessCareReason | null
  school_name: string | null
  school_business_id: string | null
  shared_household: number | null
  lone_caregiver: number | null
  child_under_16: number | null
  other_maternity_claim: number | null
  other_parental_claim: number | null
  other_person_s57: number | null
  cared_personally: number | null
  care_days: PayrollSicknessWorkInterval[]
  relationship_code: string | null
  alternation: number | null
  paternity_reason: string | null
  maternity_care_reason: string | null
  child_order: number | null
  worked_last_day: number | null
  planned_shifts: number | null
  planned_shifts_worked: number | null
  // ── podklady pro výplatu DLO (trvání a ukončení) ──
  /** `maVolno` — měl zaměstnanec v období dávky pracovní volno. */
  dlo_has_leave?: number | null
  /** `pracovniVolno` — období pracovního volna. */
  dlo_leave_periods?: PayrollSicknessWorkInterval[] | null
  /** `seznamRozvrhuSmen` — rozvrh plánovaných směn. */
  dlo_shift_schedule?: PayrollSicknessWorkInterval[] | null
  // ── rozhodné období a kontakt ──
  probable_income_czk: number | null
  /** Návrh pravděpodobného příjmu: sjednaná měsíční hrubá mzda v haléřích. */
  probable_income_suggestion_minor?: number | null
  decisive_months: PayrollSicknessDecisiveMonth[]
  contact_worker_name: string | null
  contact_worker_phone: string | null
  contact_worker_email: string | null
  // ── dlouhodobé ošetřovné: rozhodnutí zaměstnavatele (§ 191a ZP) ──
  long_term_care_consent?: PayrollLongTermCareConsent | null
  long_term_care_consent_on?: string | null
  long_term_care_refusal_reason?: string | null
  /** Absence, ze které případ vznikl; `null` = založený ručně. */
  absence_id?: number | null
  /** Vznik události vůči trvání vztahu a ochranné lhůtě § 15 (jen v seznamu). */
  protection_period?: PayrollSicknessProtectionPeriod
}

/** Rozhodnutí zaměstnavatele o nepřítomnosti kvůli dlouhodobé péči. */
export type PayrollLongTermCareConsent = 'granted' | 'refused'

export interface PayrollSicknessProtectionPeriod {
  status: 'during_employment' | 'protection_period' | 'outside' | 'unknown'
  employment_end?: string | null
  protection_until?: string | null
  legal_reference?: string
  reason_code?: string
  message?: string
}

/**
 * Co se schválením nebo zrušením nepřítomnosti stalo s případem dávky.
 * `null` = z nepřítomnosti žádná dávka neplyne.
 */
export interface PayrollAbsenceSicknessCaseOutcome {
  /** `shortened` = zrušená navazující nepřítomnost vrátila konec případu zpět. */
  outcome: 'created' | 'extended' | 'linked' | 'cancelled' | 'kept' | 'skipped' | 'shortened'
  case_id: number | null
  benefit_kind: PayrollSicknessBenefitKind | null
  nempri_due_on: string | null
  reason_code: string | null
  message: string | null
}

export interface PayrollSicknessCasePreview {
  case_id: number
  agenda_code: string
  document_kind: PayrollSicknessDocumentKind
  document_type: string
  xml: string
  xml_sha256: string
  channel: string
  window: {
    earliest_notification_on: string
    due_on: string
    legal_reference: string
    /** `statute_verified` jen u § 97 odst. 5; jinde zákon den nestanoví. */
    deadline_source_status: string
  }
  official_submission: { supported: boolean; reason: string }
}

export interface PayrollSicknessCasePrepared {
  case_id: number
  submission_id: number
  obligation_id: number
  status: string
  agenda_code: string
  document_kind: PayrollSicknessDocumentKind
  artifact_sha256: string
  channel?: string
  created: boolean
}

/**
 * Kudy podání odejde. `automatic` znamená odesílací bránu ISDS (uživatel
 * odeslání potvrdí přímo v perimetru datové schránky), `mobile_key` odeslání
 * z aplikace po potvrzení relace v mobilu, `manual_upload` stažení přílohy
 * a odeslání z vlastní schránky. Počítá to server, ne frontend — dostupnost
 * se mění v čase a podle firmy.
 */
export interface PayrollSicknessTransport {
  automatic: boolean
  channel: 'gateway' | 'mobile_key' | 'manual_upload'
  reason: string | null
}

/** Řádek fronty u připraveného podání — je, nebo není už zařazené k odeslání. */
export interface PayrollSicknessReadySubmission {
  submission_id: number
  agenda_code: string
  submission_kind: string
  submission_status: string
  corrects_submission_id: number | null
  period_start: string
  period_end: string
  created_at: string
  outbox_id: number | null
  outbox_dispatch_state: string | null
  outbox_acceptance_state: string | null
  outbox_external_message_id: string | null
}

export interface PayrollSicknessCaseList {
  items: PayrollSicknessCase[]
  transport: PayrollSicknessTransport
  ready_submissions: PayrollSicknessReadySubmission[]
}

/** Výsledek zařazení do fronty podání datovou schránkou. */
export interface PayrollSicknessDispatched {
  case_id: number
  document_kind: PayrollSicknessDocumentKind
  agenda_code: string
  outbox_id: number
  created: boolean
  recipient: { box_id: string; name: string; note: string }
  subject: string
  sender_ident: string
  attachment: { filename: string; mime: string; sha256: string; bytes: number }
  transport: PayrollSicknessTransport
}

export type PayrollSicknessCaseInput = Partial<
  Omit<
    PayrollSicknessCase,
    | 'id'
    | 'employee_id'
    | 'full_name'
    | 'status'
    | 'nempri_status'
    | 'nempri_accepted_on'
    | 'nempri_rejection_reason'
    | 'hzupn_status'
    | 'hzupn_accepted_on'
    | 'hzupn_rejection_reason'
    | 'nempri_transfer_status'
    | 'nempri_transfer_accepted_on'
    | 'nempri_transfer_rejection_reason'
    | 'cancelled'
    | 'source'
    | 'external_reference'
    | 'nempri_submission_id'
    | 'hzupn_submission_id'
    | 'nempri_transfer_submission_id'
    | 'row_version'
    | 'probable_income_suggestion_minor'
    | 'absence_id'
    | 'protection_period'
  >
>

export const payrollSicknessCasesApi = {
  list: (environment: PayrollRegzelEnvironment, employmentId?: number) =>
    api.get<PayrollSicknessCaseList>(
      '/payroll/submissions/sickness-cases',
      { params: { environment, employment_id: employmentId || undefined } },
    ).then(response => response.data),

  create: (
    environment: PayrollRegzelEnvironment,
    payload: PayrollSicknessCaseInput & {
      employment_id: number
      benefit_kind: PayrollSicknessBenefitKind
      incapacity_from: string
    },
  ) =>
    api.post<PayrollSicknessCase>(
      '/payroll/submissions/sickness-cases',
      { ...payload, environment },
    ).then(response => response.data),

  update: (
    environment: PayrollRegzelEnvironment,
    caseId: number,
    rowVersion: number,
    payload: PayrollSicknessCaseInput,
  ) =>
    api.put<PayrollSicknessCase>(
      `/payroll/submissions/sickness-cases/${caseId}`,
      { ...payload, row_version: rowVersion, environment },
    ).then(response => response.data),

  preview: (
    environment: PayrollRegzelEnvironment,
    caseId: number,
    document: PayrollSicknessDocumentKind,
  ) =>
    api.get<PayrollSicknessCasePreview>(
      `/payroll/submissions/sickness-cases/${caseId}/preview`,
      { params: { environment, document } },
    ).then(response => response.data),

  prepare: (
    environment: PayrollRegzelEnvironment,
    caseId: number,
    document: PayrollSicknessDocumentKind,
  ) =>
    api.post<PayrollSicknessCasePrepared>(
      `/payroll/submissions/sickness-cases/${caseId}/prepare`,
      { environment, document },
    ).then(response => response.data),

  /**
   * Zařadí připravené podání do fronty podání datovou schránkou.
   *
   * Neodesílá samo: podle `transport` se pak buď pokračuje branou ISDS,
   * Mobilním klíčem, nebo si uživatel přílohu stáhne z fronty a odešle ji ze
   * své schránky. Doručenka se v každém případě nahrává ručně — ani jeden
   * kanál neumí schránku ČÍST.
   */
  dispatch: (
    environment: PayrollRegzelEnvironment,
    caseId: number,
    document: PayrollSicknessDocumentKind,
  ) =>
    api.post<PayrollSicknessDispatched>(
      `/payroll/submissions/sickness-cases/${caseId}/dispatch`,
      { environment, document },
    ).then(response => response.data),

  /**
   * Výsledek JEDNOHO podání z protokolu ČSSZ (`document`), nebo zrušení celého
   * případu (`outcome: 'cancelled'`). `predecessor` jen u převzatého případu,
   * `pending` vrací podání vedené jako podané předchozím programem zpět.
   */
  recordReceipt: (
    environment: PayrollRegzelEnvironment,
    caseId: number,
    payload: {
      outcome: 'accepted' | 'rejected' | 'predecessor' | 'pending' | 'cancelled'
      document?: PayrollSicknessDocumentKind
      accepted_on?: string | null
      reason?: string | null
    },
  ) =>
    api.post<PayrollSicknessCase>(
      `/payroll/submissions/sickness-cases/${caseId}/receipt`,
      { ...payload, environment },
    ).then(response => response.data),
}
