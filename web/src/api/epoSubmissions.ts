import { api } from './client'

export type SubmissionStatus = 'draft' | 'generated' | 'downloaded' | 'submitted' | 'accepted' | 'rejected'
export type ValidationStatus = 'passed' | 'failed' | 'skipped'
export type AttemptStatus =
  | 'prepared' | 'handoff_created' | 'awaiting_confirmation' | 'confirmed'
  | 'testing' | 'test_passed' | 'test_failed' | 'submitting' | 'processing'
  | 'submitted' | 'rejected' | 'uncertain' | 'failed' | 'expired' | 'cancelled'
export type ArtifactKind =
  | 'source_xml' | 'epo_xml' | 'signed_submission_p7s' | 'confirmation_p7s'
  // Odvozené z potvrzenky přímého podání — aplikace je vytáhne sama, u asistovaného
  // podání je uživatel nahrává ručně přes „Nahrát výstupy z EPO".
  | 'confirmation_xml' | 'epo_echo'
  | 'confirmation_signer_cert' | 'submission_signer_cert'
  | 'epo_error_xml' | 'epo_status_xml' | 'receipt_pdf' | 'other'
export type VerificationStatus = 'not_applicable' | 'pending' | 'valid' | 'warning' | 'invalid'

export interface EpoMessage {
  type: string | null
  code: string | null
  text: string | null
  field: string | null
  section: string | null
  line: string | null
}

export interface EpoAttempt {
  id: number
  channel: 'epo_assisted' | 'epo_direct'
  epo_environment: 'production' | 'test'
  status: AttemptStatus
  request_sha256: string
  signing_credential_id: number | null
  signing_fingerprint: string | null
  response_http_status: number | null
  test_passed: boolean | null
  test_messages: EpoMessage[]
  tested_at: string | null
  error_code: string | null
  error_message: string | null
  requested_by: number | null
  requested_at: string
  handoff_expires_at: string | null
  submitted_at: string | null
  remote_submission_ref: string | null
  remote_status: Record<string, string> | null
  last_status_at: string | null
  confirmed_at: string | null
  poll_count: number
  next_poll_at: string | null
  status_query_available: boolean
  refresh_available: boolean
  confirmation_recovery_available: boolean
  resolution_code: string | null
  resolution_note: string | null
  resolved_by: number | null
  resolved_at: string | null
  updated_at: string
}

export interface EpoArtifact {
  id: number
  tax_submission_id: number
  attempt_id: number | null
  document_id: number
  artifact_kind: ArtifactKind
  sha256: string
  verification_status: VerificationStatus
  verification: {
    signature_valid?: boolean
    chain_valid?: boolean
    is_confirmation?: boolean
    epo_signer_valid?: boolean
    reference?: string | null
    submitted_at?: string | null
    content_match?: boolean | null
    form_match?: boolean | null
    snapshot_sha256_match?: boolean
  } | null
  title: string
  original_name: string
  size_bytes: number
  doc_type: string
  folder_id: number | null
  created_at: string
}

export interface TaxSubmission {
  id: number
  form_code: string
  form_variant?: string
  period_year: number
  period_month: number | null
  period_quarter: number | null
  xml_size_bytes: number
  xml_sha256: string
  validation_status: ValidationStatus
  validation_errors: string[]
  status: SubmissionStatus
  submitted_at: string | null
  submission_ref: string | null
  summary: Record<string, unknown> | null
  generated_at: string
  notes: string | null
  attempts: EpoAttempt[]
  artifacts: EpoArtifact[]
  /** Smazatelnost počítá backend — UI jen zrcadlí to, co brána skutečně udělá. */
  deletable: boolean
  delete_blocker: SubmissionDeleteBlocker | null
  /** Nedořešené předání jde odemknout vědomým potvrzením „nepodáno". */
  delete_needs_acknowledgement: boolean
}

/**
 * `submitted_snapshot` / `delivered_attempt` = prokazatelně podáno, mazat nelze.
 * `unresolved_attempt` = nevíme, jak předání dopadlo — uživatel to může uzavřít.
 */
export type SubmissionDeleteBlocker =
  | 'submitted_snapshot'
  | 'delivered_attempt'
  | 'unresolved_attempt'

export interface EpoFolder {
  id: number
  parent_id: number | null
  name: string
}

export interface EpoSettingsInput {
  vat_root_folder_id: number | null
  income_tax_root_folder_id: number | null
}

export interface EpoSettings extends EpoSettingsInput {
  folders: EpoFolder[]
  epo_environment: 'production' | 'test'
}

export interface HandoffResult {
  attempt_id: number
  url: string
  expires_at: string
  archive_folder_id: number | null
  source_document_id: number | null
  environment: 'production' | 'test'
}

export interface EpoSigningCredential {
  id: number
  label: string
  fingerprint_sha256: string
  subject_dn: string
  issuer_dn: string
  serial_hex: string | null
  valid_from: string
  valid_to: string
  ik_mpsv_present: boolean
  epo_verified: boolean
  epo_verified_at: string | null
  valid_now: boolean
  created_at: string
  enabled_for_supplier: boolean
  linked_profiles_count: number
  linked_supplier_profiles_count: number
}

export interface EpoTestResult {
  attempt_id: number
  passed: boolean
  messages: EpoMessage[]
  large_submission: boolean
  environment: 'production' | 'test'
  artifacts: EpoArtifact[]
  attempts: EpoAttempt[]
}

export interface EpoDirectResult {
  attempt_id: number
  status: 'processing' | 'confirmed' | 'rejected' | 'uncertain' | 'cancelled'
  reference?: string
  submitted_at?: string
  chain_valid?: boolean
  message?: string
  messages?: EpoMessage[]
  remote_status?: Record<string, string>
  environment: 'production' | 'test'
  artifacts?: EpoArtifact[]
  attempts?: EpoAttempt[]
}

export interface ArtifactUploadResult {
  created: EpoArtifact[]
  errors: Array<{ name: string; code: string; message?: string }>
  artifacts: EpoArtifact[]
  attempts: EpoAttempt[]
}

/**
 * Čerstvé ověření pro práci s podpisovým certifikátem a přímým podáním.
 * Účet s passkey/TOTP posílá jednorázový `step_up_token` (u TOTP stačí i přímo
 * `totp_code`); `password` zůstává jen pro účet bez jakéhokoli silného faktoru.
 */
export interface EpoStepUpProof {
  password?: string
  totp_code?: string
  step_up_token?: string
}

export function stepUpProofBody(proof: EpoStepUpProof): Record<string, string> {
  const body: Record<string, string> = {}
  if (proof.step_up_token) body.step_up_token = proof.step_up_token
  else if (proof.totp_code) body.totp_code = proof.totp_code
  if (proof.password) body.password = proof.password
  return body
}

export function appendStepUpProof(data: FormData, proof: EpoStepUpProof): void {
  for (const [key, value] of Object.entries(stepUpProofBody(proof))) {
    data.append(key, value)
  }
}

function sid(): string {
  const value = localStorage.getItem('myinvoice.current_supplier_id')
  return value && /^\d+$/.test(value) ? value : ''
}

export const epoSubmissionsApi = {
  list: () => api.get<TaxSubmission[]>('/reports/submissions').then(r => r.data),

  settings: () => api.get<EpoSettings>('/reports/submissions/settings').then(r => r.data),

  updateSettings: (settings: EpoSettingsInput) =>
    api.put<EpoSettings>('/reports/submissions/settings', settings).then(r => r.data),

  handoff: (id: number, replaceActive = false) =>
    api.post<HandoffResult>(`/reports/submissions/${id}/epo-handoff`, {
      replace_active: replaceActive,
    }).then(r => r.data),

  credentials: () =>
    api.get<EpoSigningCredential[]>('/reports/submissions/epo-credentials').then(r => r.data),

  uploadCredential: (
    file: File,
    label: string,
    pfxPassword: string,
    proof: EpoStepUpProof,
  ) => {
    const data = new FormData()
    data.append('file', file, file.name)
    data.append('label', label)
    data.append('pfx_password', pfxPassword)
    appendStepUpProof(data, proof)
    return api.post<EpoSigningCredential>('/reports/submissions/epo-credentials', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },

  setCredentialSupplier: (
    credentialId: number,
    enabled: boolean,
    proof: EpoStepUpProof,
  ) => api.put<{ credentials: EpoSigningCredential[] }>(
    `/reports/submissions/epo-credentials/${credentialId}/supplier`,
    { enabled, ...stepUpProofBody(proof) },
  ).then(r => r.data),

  deleteCredential: (credentialId: number, proof: EpoStepUpProof) =>
    api.delete<{ deleted: boolean }>(`/reports/submissions/epo-credentials/${credentialId}`, {
      data: stepUpProofBody(proof),
    }).then(r => r.data),

  testDirect: (
    id: number,
    credentialId: number,
    proof: EpoStepUpProof,
  ) =>
    api.post<EpoTestResult>(`/reports/submissions/${id}/epo-test`, {
      credential_id: credentialId,
      ...stepUpProofBody(proof),
    }).then(r => r.data),

  submitDirect: (
    id: number,
    attemptId: number,
    proof: EpoStepUpProof,
  ) => api.post<EpoDirectResult>(`/reports/submissions/${id}/epo-submit`, {
    attempt_id: attemptId,
    ...stepUpProofBody(proof),
  }).then(r => r.data),

  refreshDirectStatus: (id: number, attemptId: number) =>
    api.post<EpoDirectResult>(`/reports/submissions/${id}/epo-status`, {
      attempt_id: attemptId,
    }).then(r => r.data),

  recoverDirectConfirmation: (
    id: number,
    attemptId: number,
    file: File,
    proof: EpoStepUpProof,
  ) => {
    const data = new FormData()
    data.append('file', file, file.name)
    appendStepUpProof(data, proof)
    return api.post<EpoDirectResult>(
      `/reports/submissions/${id}/epo-attempts/${attemptId}/confirmation`,
      data,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },

  resolveDirectNotSubmitted: (
    id: number,
    attemptId: number,
    note: string,
    proof: EpoStepUpProof,
  ) => api.post<EpoDirectResult>(
    `/reports/submissions/${id}/epo-attempts/${attemptId}/resolve-not-submitted`,
    {
      verified_not_submitted: true,
      note,
      ...stepUpProofBody(proof),
    },
  ).then(r => r.data),

  uploadArtifacts: (
    id: number,
    files: File[],
    attemptId?: number,
    onProgress?: (percent: number) => void,
  ) => {
    const data = new FormData()
    for (const file of files) data.append('file[]', file, file.name)
    if (attemptId) data.append('attempt_id', String(attemptId))
    return api.post<ArtifactUploadResult>(`/reports/submissions/${id}/artifacts`, data, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (event) => {
        if (onProgress && event.total) {
          onProgress(Math.round((event.loaded / event.total) * 100))
        }
      },
    }).then(r => r.data)
  },

  markSubmitted: (id: number, submittedAt: string, submissionRef: string) =>
    api.post<TaxSubmission>(`/reports/submissions/${id}/submit`, {
      submitted_at: submittedAt,
      submission_ref: submissionRef,
    }).then(r => r.data),

  /**
   * `notSubmittedNote` = vědomé potvrzení, že nedořešené předání nakonec podané nebylo.
   * Tvrdé blokace (prokazatelně podáno) neuvolní — ty backend odmítne i s poznámkou.
   */
  remove: (id: number, notSubmittedNote?: string) =>
    api.delete<{ deleted: boolean; released_attempts: number }>(
      `/reports/submissions/${id}`,
      notSubmittedNote ? { data: { not_submitted_note: notSubmittedNote } } : undefined,
    ).then(r => r.data),

  xmlUrl: (id: number) => {
    const params = new URLSearchParams()
    const supplierId = sid()
    if (supplierId) params.set('supplier_id', supplierId)
    const query = params.toString()
    return `/api/reports/submissions/${id}/xml${query ? `?${query}` : ''}`
  },

  artifactDownloadUrl: (submissionId: number, artifactId: number) => {
    const params = new URLSearchParams()
    const supplierId = sid()
    if (supplierId) params.set('supplier_id', supplierId)
    const query = params.toString()
    return `/api/reports/submissions/${submissionId}/artifacts/${artifactId}/download${query ? `?${query}` : ''}`
  },
}
