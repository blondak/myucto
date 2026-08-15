import { api } from './client'

/**
 * Datová schránka jako průřezový kanál podání.
 *
 * Není to mzdová odbočka: touhle cestou jde odeslat přiznání k DPH, kontrolní
 * i souhrnné hlášení, DPPO i přehledy zdravotním pojišťovnám.
 */

/** Doprava — co víme o cestě zprávy k příjemci. */
export type DispatchState =
  | 'ready'
  | 'sending'
  | 'send_uncertain'
  | 'sent'
  | 'delivered'
  | 'failed'
  | 'cancelled'

/**
 * Vyřízení — co o podání rozhodl ÚŘAD.
 *
 * ⚠️ Samostatná osa schválně. Datová schránka vrací doručenku, tedy důkaz
 * o DORUČENÍ, ne o zpracování; `acceptance_state` proto u ISDS podání zůstává
 * `unknown` i poté, co je `dispatch_state` `delivered`. Kdo obě osy v UI slije,
 * vyrobí podání, které se tváří jako přijaté, aniž o něm úřad rozhodl.
 */
export type AcceptanceState = 'unknown' | 'accepted' | 'rejected'

export type AcceptanceEvidence = 'epo_protocol' | 'agency_protocol_message' | 'manual_confirmation'

export type RecipientKind = 'tax_office' | 'cssz' | 'health_insurer' | 'other'

export type InboxClassification =
  | 'delivery_receipt'
  | 'cssz_protocol'
  | 'health_insurer_response'
  | 'tax_office_response'
  | 'unclassified'

export interface DataBoxCredential {
  id: number
  supplier_id: number
  environment: 'production' | 'test'
  channel: 'isds'
  label: string
  box_id: string
  auth_mode: 'certificate'
  certificate_fingerprint: string | null
  certificate_valid_to: string | null
  last_verified_at: string | null
  /** Vybírání schránky — vypnuté, dokud ho uživatel vědomě nezapne (§ 17 odst. 3). */
  inbox_polling_enabled: boolean
  inbox_polling_enabled_at: string | null
  inbox_polling_enabled_by: number | null
}

export interface SubmissionRecipient {
  id: number
  supplier_id: number | null
  code: string
  name: string
  kind: RecipientKind
  isds_box_id: string | null
  source_url: string | null
  source_note: string | null
  is_active: boolean
  is_system: boolean
  /** Číselník smí být prázdný — u finančních úřadů doklad nemáme a nehádáme. */
  has_box_id: boolean
  verified_in_isds_at: string | null
}

export interface OutboxSubmission {
  id: number
  environment: 'production' | 'test'
  channel: 'epo' | 'isds'
  agenda_code: string
  recipient_id: number | null
  recipient_box_id: string | null
  subject: string
  artifact_kind: 'payroll_submission' | 'tax_submission' | 'document'
  artifact_id: number
  artifact_filename: string
  dispatch_state: DispatchState
  acceptance_state: AcceptanceState
  acceptance_evidence_kind: AcceptanceEvidence | null
  acceptance_note: string | null
  correlation_reference: string
  external_message_id: string | null
  artifact_validation_status: 'passed' | 'failed' | 'skipped' | null
  recipient_box_verified_at: string | null
  receipt_document_id: number | null
  receipt_signature_status: 'unverified' | 'trusted'
  confirmed_by: number | null
  confirmed_at: string | null
  sent_at: string | null
  delivered_at: string | null
  accepted_at: string | null
  rejected_at: string | null
  last_error_code: string | null
  last_error_message: string | null
  row_version: number
  created_at: string
}

export interface OutboxAttempt {
  id: number
  attempt_no: number
  outcome: 'in_flight' | 'sent' | 'uncertain' | 'rejected' | 'failed'
  external_message_id: string | null
  error_code: string | null
  error_message: string | null
  started_at: string
  finished_at: string | null
}

export interface InboxMessage {
  id: number
  external_message_id: string
  sender_box_id: string | null
  sender_name: string | null
  subject: string | null
  sender_ident: string | null
  classification: InboxClassification
  matched_outbox_id: number | null
  document_id: number | null
  signature_status: 'unverified' | 'trusted'
  delivered_at: string | null
  accepted_at: string | null
  fetched_at: string
}

/**
 * Stav dotazování schránky.
 *
 * `last_attempt_at` a `last_ok_at` jsou zvlášť schválně: bez toho by „žádné
 * nové zprávy" a „na schránku se nedovoláme" vypadaly v UI stejně.
 */
export interface InboxPollState {
  last_attempt_at: string | null
  last_ok_at: string | null
  last_ok_count: number | null
  consecutive_failures: number
  last_error_code: string | null
  last_error_message: string | null
}

export const dataBoxApi = {
  credentials: () =>
    api.get<{ items: DataBoxCredential[] }>('/settings/databox').then(r => r.data.items),

  saveCredential: (data: FormData) =>
    api.post<DataBoxCredential>('/settings/databox', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data),

  deleteCredential: (environment: string) =>
    api.delete(`/settings/databox/${environment}`).then(r => r.data),

  /**
   * Zapnutí/vypnutí vybírání schránky. `acknowledged` musí být `true` —
   * je to potvrzení, že uživatel ví, že vyzvednutí zprávy je doručení
   * a rozjíždí zákonné lhůty.
   */
  setPolling: (environment: string, enabled: boolean, acknowledged: boolean) =>
    api.post<{ inbox_polling_enabled: boolean }>('/settings/databox/polling', {
      environment,
      enabled,
      acknowledged,
    }).then(r => r.data),

  recipients: (kind?: RecipientKind) =>
    api.get<{ items: SubmissionRecipient[] }>('/submissions/recipients', {
      params: kind ? { kind } : undefined,
    }).then(r => r.data.items),

  saveRecipient: (data: Partial<SubmissionRecipient>) =>
    api.post<{ id: number }>('/submissions/recipients', data).then(r => r.data),

  deleteRecipient: (id: number) =>
    api.delete(`/submissions/recipients/${id}`).then(r => r.data),

  outbox: (environment: string) =>
    api.get<{ items: OutboxSubmission[] }>('/submissions/outbox', {
      params: { environment },
    }).then(r => r.data.items),

  attempts: (id: number) =>
    api.get<{ items: OutboxAttempt[] }>(`/submissions/outbox/${id}/attempts`).then(r => r.data.items),

  confirm: (id: number, environment: string) =>
    api.post<{ row: OutboxSubmission; dispatched: boolean }>(
      `/submissions/outbox/${id}/confirm`,
      { environment },
    ).then(r => r.data),

  resolve: (id: number, environment: string) =>
    api.post<OutboxSubmission>(`/submissions/outbox/${id}/resolve`, { environment }).then(r => r.data),

  cancel: (id: number) =>
    api.post<OutboxSubmission>(`/submissions/outbox/${id}/cancel`, {}).then(r => r.data),

  inbox: (environment: string, classification?: InboxClassification) =>
    api.get<{ items: InboxMessage[]; state: InboxPollState | null }>('/submissions/inbox', {
      params: { environment, classification },
    }).then(r => r.data),

  pollInbox: (environment: string) =>
    api.post<{ fetched: number; stored: number; skipped: number; failed: number; unclassified: number }>(
      '/submissions/inbox/poll',
      { environment },
    ).then(r => r.data),

  classify: (id: number, classification: InboxClassification, outboxId: number | null) =>
    api.post(`/submissions/inbox/${id}/classify`, { classification, outbox_id: outboxId }).then(r => r.data),
}
