import { api } from './client'
import { downloadApiFile } from '@/utils/downloadFile'

/** Soubor přenášený v JSON těle; obsah je base64 bez prefixu `data:`. */
export interface ImportFilePayload {
  name: string
  content_base64: string
}

// ─── Registrace JMHZ ──────────────────────────────────────────────────────────

export type RegistrationEnvironment = 'production' | 'test'
export type RegistrationDocumentType = 'REGZEC25' | 'PREZEC26' | 'JMHZ'
export type RegistrationRelationType =
  | 'employment'
  | 'small_scale_employment'
  | 'dpc'
  | 'dpp'
  | 'statutory_body'
export type RegistrationMatchStatus = 'new' | 'matched' | 'ambiguous' | 'not_found'
export type RegistrationMatchedBy = 'birth_number' | 'oic' | 'id_ppv' | 'manual'
export type RegistrationOperation =
  | 'create_person'
  | 'create_employment'
  | 'update'
  | 'terminate'
  | 'assign_identifiers'
  | 'pair_required'
  | 'none'
  | 'unsupported'
/** Typ podání měsíčního hlášení: řádné, opravné, storno. */
export type RegistrationSubmissionType = 'R' | 'O' | 'S'
export type RegistrationOpeningBalanceStatus = 'ready' | 'blocked' | 'unchanged'
export type RegistrationAverageStatus = 'ready' | 'blocked' | 'exists'

export interface RegistrationFileInfo {
  name: string
  sha256: string
  document_type: RegistrationDocumentType | null
  record_count: number
  error: string | null
  warnings: string[]
  /** Období měsíčního hlášení (YYYY-MM); u registrací `null`. */
  period: string | null
  submission_type: RegistrationSubmissionType | null
}

export interface RegistrationHistory {
  period: string
  gross_minor: number | null
  tax_base_minor: number | null
  advance_tax_minor: number | null
  bonus_minor: number | null
  social_base_minor: number | null
  worked_hours: string | null
  average_hourly_minor: number | null
}

export interface RegistrationPair {
  key: string
  employment_id: number
}

export interface RegistrationEmploymentOption {
  employment_id: number
  employee_id: number
  label: string
  code: string
}

export interface RegistrationOpeningBalanceMonth {
  month: number
  social_assessment_base_minor_units: number
  advance_base_minor_units: number
  advance_tax_minor_units: number
  withholding_base_minor_units: number
  withholding_tax_minor_units: number
  applied_non_refundable_credits_minor_units: number
  applied_child_credit_minor_units: number
  tax_bonus_minor_units: number
  bonus_qualifying_income_minor_units: number
}

export interface RegistrationOpeningBalance {
  employee_id: number
  employee_name: string
  year: number
  months: RegistrationOpeningBalanceMonth[]
  status: RegistrationOpeningBalanceStatus
  reason: string | null
}

export interface RegistrationAverage {
  employment_id: number
  label: string
  year: number
  quarter: number
  gross_minor: number
  worked_minutes: number
  average_hourly_minor: number
  reported_hourly_minor: number | null
  status: RegistrationAverageStatus
  reason: string | null
}

export interface RegistrationCandidate {
  employee_id: number
  employment_id: number
  label: string
}

export interface RegistrationChange {
  field: string
  label: string
  current: string | null
  imported: string | null
}

export interface RegistrationRecord {
  key: string
  file: string
  sequence: number
  document_type: RegistrationDocumentType
  action_code: number
  action_label: string
  prepared_on: string | null
  effective_on: string | null
  person: {
    full_name: string
    first_name: string | null
    last_name: string | null
    birth_date: string | null
    birth_number_masked: string | null
    has_oic: boolean
  }
  employment: {
    start_on: string | null
    end_on: string | null
    activity_code: string | null
    relation_type: RegistrationRelationType | null
    position_name: string | null
    has_id_ppv: boolean
  }
  match: {
    status: RegistrationMatchStatus
    matched_by: RegistrationMatchedBy | null
    employee_id: number | null
    employee_name: string | null
    employment_id: number | null
    employment_code: string | null
    candidates: RegistrationCandidate[]
  }
  operation: RegistrationOperation
  changes: RegistrationChange[]
  warnings: string[]
  blocker: string | null
  selectable: boolean
  /** Jen měsíční hlášení (`document_type: 'JMHZ'`). */
  period?: string | null
  form_id?: string | null
  history?: RegistrationHistory | null
}

export interface RegistrationPreview {
  environment: RegistrationEnvironment
  files: RegistrationFileInfo[]
  records: RegistrationRecord[]
  summary: {
    total: number
    create: number
    update: number
    terminate: number
    none: number
    blocked: number
    pair_required: number
  }
  employment_options: RegistrationEmploymentOption[]
  opening_balances: RegistrationOpeningBalance[]
  averages: RegistrationAverage[]
}

export interface RegistrationPreviewPayload {
  environment: RegistrationEnvironment
  files: ImportFilePayload[]
  pairs?: RegistrationPair[]
}

export interface RegistrationApplyPayload extends RegistrationPreviewPayload {
  /** Smí být prázdné, když se jen přebírá historie. */
  keys: string[]
  evidence_confirmed: boolean
  office_id: number | null
  pairs: RegistrationPair[]
  apply_opening_balances: boolean
  apply_averages: boolean
  auto_approve_changes: boolean
  auto_approve_averages: boolean
}

export type RegistrationResultStatus = 'applied' | 'failed' | 'skipped'
export type RegistrationResultOperation =
  | 'person_created'
  | 'employment_created'
  | 'identity_facts'
  | 'address'
  | 'terms'
  | 'activated'
  | 'identifiers'
  | 'terminated'
  | 'no_show'
  | 'health_insurer'
  | 'tax_declaration'
  | 'tax_credit_claims'
  | 'social_discount'
  | 'dependants'

export interface RegistrationApplyResult {
  results: {
    key: string
    status: RegistrationResultStatus
    message: string | null
    employee_id: number | null
    employment_id: number | null
    operations: (RegistrationResultOperation | string)[]
  }[]
  summary: { applied: number; failed: number; skipped: number }
  opening_balances: {
    saved: number
    skipped: { employee_id: number; employee_name: string; year: number; reason: string }[]
  }
  averages: {
    created: number
    approved: number
    skipped: { employment_id: number; label: string; year: number; quarter: number; reason: string }[]
  }
  change_checklist: {
    completed: number
    failed: { employment_id: number; item_key: string; message: string }[]
  }
}

// ─── Docházka ─────────────────────────────────────────────────────────────────

export type AttendanceUnit = 'hours' | 'excel_duration' | 'amount' | 'text'
export type AttendanceMeaning =
  | 'ignore' | 'person_name' | 'personal_number' | 'birth_number' | 'relation_label'
  | 'department' | 'cost_center' | 'position' | 'weekly_hours' | 'start_end_note' | 'monthly_wage'
  | 'worked_hours' | 'overtime_hours' | 'night_hours' | 'weekend_hours' | 'holiday_work_hours'
  | 'afternoon_hours' | 'fund_hours'
  | 'vacation_hours' | 'holiday_hours' | 'sick_hours' | 'doctor_hours' | 'care_hours'
  | 'paternity_hours' | 'unpaid_leave_hours' | 'unexcused_hours' | 'obstacle_employee_hours'
  | 'obstacle_employer_hours' | 'business_trip_hours' | 'home_office_hours' | 'compensatory_time_off_hours'
  | 'component' | 'reference_gross' | 'reference_net' | 'reference_hours'

export interface AttendanceRule {
  sheet: string | null
  header: string
  meaning: AttendanceMeaning
  unit: AttendanceUnit | null
  /** `'*'` = kód složky se odvodí z hlavičky sloupce. */
  component_code: string | null
}

export type AttendanceProfileComponentKind =
  | 'hourly_wage'
  | 'task_wage'
  | 'bonus'
  | 'premium'
  | 'compensation'
  | 'allowance'
  | 'other'

export interface AttendanceProfileComponent {
  code: string
  name: string
  kind: AttendanceProfileComponentKind
}

export interface AttendanceFileInfo {
  name: string
  sha256: string
  format: 'xlsx' | 'csv'
  sheets: number
  error: string | null
}

export interface AttendanceColumn {
  letter: string
  header: string
  meaning: AttendanceMeaning
  unit: AttendanceUnit | null
  component_code: string | null
  rule_source: 'profile' | 'suggested' | 'none' | string
  samples: string[]
}

export interface AttendanceSheet {
  id: string
  file: string
  sheet: string
  header_row: number | null
  data_rows: number
  used: boolean
  columns: AttendanceColumn[]
}

export interface AttendanceEmploymentOption {
  employment_id: number
  employee_id: number
  label: string
  code: string
  status: string
}

export type AttendanceMatchStatus = 'linked' | 'matched' | 'ambiguous' | 'not_found'

export interface AttendanceConflict {
  hours?: string | null
  amount?: string | null
  amount_minor?: number | null
  source: string
}

export interface AttendanceMetric {
  meaning: AttendanceMeaning
  hours: string
  source: string
  conflicts: AttendanceConflict[]
}

export interface AttendanceComponentValue {
  component_code: string
  amount: string
  amount_minor: number
  source: string
  conflicts: AttendanceConflict[]
}

export interface AttendancePerson {
  key: string
  display_name: string
  personal_number: string | null
  birth_number_masked: string | null
  relation_label: string | null
  department: string | null
  cost_center: string | null
  position: string | null
  weekly_hours: string | null
  start_end_note: string | null
  monthly_wage: string | null
  sources: { sheet_id: string; row: number }[]
  match: {
    status: AttendanceMatchStatus
    matched_by: 'link' | 'birth_number' | 'employment_code' | 'name' | null
    employment_id: number | null
    employee_id: number | null
    employee_name: string | null
    employment_code: string | null
    candidates: { employment_id: number; employee_id: number; label: string }[]
  }
  metrics: AttendanceMetric[]
  components: AttendanceComponentValue[]
  reference: { gross_minor: number | null; net_minor: number | null; hours: string | null }
  warnings: string[]
}

export interface AttendanceComponentCheck {
  component_code: string
  status: 'ok' | 'missing' | 'not_one_off' | 'will_create'
  message: string | null
  name: string | null
  kind: AttendanceProfileComponentKind | string | null
}

export interface AttendanceUnrecognizedColumn {
  sheet_id: string
  letter: string
  header: string
  samples: string[]
}

export interface AttendancePreviewProfile {
  id: number | null
  name: string | null
  auto: boolean
}

export interface AttendancePreview {
  period: string
  content_hash: string
  profile: AttendancePreviewProfile
  files: AttendanceFileInfo[]
  sheets: AttendanceSheet[]
  unrecognized_columns: AttendanceUnrecognizedColumn[]
  rules: AttendanceRule[]
  employment_options: AttendanceEmploymentOption[]
  persons: AttendancePerson[]
  component_checks: AttendanceComponentCheck[]
  summary: {
    persons: number
    matched: number
    ambiguous: number
    not_found: number
    metrics: number
    components: number
    amount_minor_total: number
  }
}

export interface AttendancePreviewPayload {
  period: string
  files: ImportFilePayload[]
  rules: AttendanceRule[] | null
  profile_id: number | null
  components?: AttendanceProfileComponent[] | null
}

export interface AttendanceLink {
  person_key: string
  employment_id: number
}

export interface AttendanceApplyPayload {
  period: string
  files: ImportFilePayload[]
  rules: AttendanceRule[]
  links: AttendanceLink[]
  save_links: boolean
  create_inputs: boolean
  create_components: boolean
  adopt_personal_numbers: boolean
  components?: AttendanceProfileComponent[] | null
  profile_id?: number | null
}

export interface AttendancePersonalNumberConflict {
  key: string
  display_name: string
  reason: string
}

export interface AttendanceBatch {
  id: number
  period: string
  source_system: 'attendance' | string
  created_at: string
  created_by_name: string | null
  files: { name: string; sha256: string }[]
  person_count: number
  metric_count: number
  input_count: number
}

export interface AttendanceApplyResult {
  replayed: boolean
  batch: AttendanceBatch
  inputs: {
    import_id: number | null
    created: number
    duplicates: number
    errors: { row_number: number; error_message: string }[]
  }
  links_saved: number
  skipped_persons: { key: string; display_name: string; reason: string }[]
  personal_numbers_adopted: number
  personal_number_conflicts: AttendancePersonalNumberConflict[]
}

export interface AttendancePersonCreate {
  person_key: string
  full_name: string
  first_name: string
  last_name: string
  birth_number: string | null
  relation_type: RegistrationRelationType
  weekly_hours: string | null
  /** Sjednaná měsíční mzda v celých korunách (z mzdového výměru v podkladech). */
  monthly_gross: number | null
  planned_start_on: string
  personal_number: string | null
  activate: boolean
}

export interface AttendancePersonsResult {
  results: {
    person_key: string
    status: 'created' | 'failed'
    employee_id: number | null
    employment_id: number | null
    message: string | null
  }[]
}

export interface AttendanceBatchRow {
  employment_id: number
  employee_name: string
  employment_code: string
  meaning: AttendanceMeaning | string
  hours: string | null
  amount_minor: number | null
  source: string
}

export interface AttendanceProfile {
  id: number
  name: string
  rules: AttendanceRule[]
  components: AttendanceProfileComponent[]
  is_sample: boolean
  updated_at: string
}

export interface AttendanceProfileSavePayload {
  id: number | null
  name: string
  rules: AttendanceRule[]
  components: AttendanceProfileComponent[]
}

/** Přenosný tvar profilu (export/import JSON mezi instalacemi a firmami). */
export interface AttendanceProfileExport {
  format: 'myucto-attendance-profile'
  version: 1
  name: string
  rules: AttendanceRule[]
  components: AttendanceProfileComponent[]
}

export const payrollImportsApi = {
  previewRegistrations: (payload: RegistrationPreviewPayload) =>
    api.post<RegistrationPreview>('/payroll/imports/registrations/preview', payload)
      .then(response => response.data),
  applyRegistrations: (payload: RegistrationApplyPayload) =>
    api.post<RegistrationApplyResult>('/payroll/imports/registrations/apply', payload)
      .then(response => response.data),

  previewAttendance: (payload: AttendancePreviewPayload) =>
    api.post<AttendancePreview>('/payroll/imports/attendance/preview', payload)
      .then(response => response.data),
  applyAttendance: (payload: AttendanceApplyPayload) =>
    api.post<AttendanceApplyResult>('/payroll/imports/attendance/apply', payload)
      .then(response => response.data),
  // S podklady (jako u náhledu) doplní server rodná čísla, která klient zná jen maskovaná.
  createAttendancePersons: (
    period: string,
    persons: AttendancePersonCreate[],
    source?: Omit<AttendancePreviewPayload, 'period'>,
  ) =>
    api.post<AttendancePersonsResult>('/payroll/imports/attendance/persons', { period, persons, ...source })
      .then(response => response.data),
  attendanceBatches: (period?: string | null) =>
    api.get<{ batches: AttendanceBatch[] }>('/payroll/imports/attendance/batches', {
      params: period ? { period } : undefined,
    }).then(response => response.data.batches),
  attendanceBatch: (id: number) =>
    api.get<{ batch: AttendanceBatch; rows: AttendanceBatchRow[] }>(`/payroll/imports/attendance/batches/${id}`)
      .then(response => response.data),
  attendanceProfiles: () =>
    api.get<{ profiles: AttendanceProfile[] }>('/payroll/imports/attendance/profiles')
      .then(response => response.data.profiles),
  saveAttendanceProfile: (payload: AttendanceProfileSavePayload) =>
    api.post<{ profile: AttendanceProfile }>('/payroll/imports/attendance/profiles', payload)
      .then(response => response.data.profile),
  deleteAttendanceProfile: (id: number) =>
    api.delete<{ deleted: true }>(`/payroll/imports/attendance/profiles/${id}`)
      .then(response => response.data),
  copyAttendanceProfile: (id: number, targetSupplierId: number) =>
    api.post<{ profile: AttendanceProfile }>(`/payroll/imports/attendance/profiles/${id}/copy`, {
      target_supplier_id: targetSupplierId,
    }).then(response => response.data.profile),
  exportAttendanceProfile: (id: number, fallbackFilename: string) =>
    downloadApiFile(`/payroll/imports/attendance/profiles/${id}/export`, fallbackFilename),
  importAttendanceProfile: (profile: AttendanceProfileExport, name?: string) =>
    api.post<{ profile: AttendanceProfile }>('/payroll/imports/attendance/profiles/import', {
      profile,
      ...(name !== undefined ? { name } : {}),
    }).then(response => response.data.profile),
}
