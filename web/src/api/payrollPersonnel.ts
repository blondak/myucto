import { api } from './client'

/** Druh dokumentu personálního spisu — zrcadlí `PayrollPersonnelFileRepository::CATEGORIES`. */
export type PersonnelDocumentCategory =
  | 'employment_contract'
  | 'contract_amendment'
  | 'job_description'
  | 'termination'
  | 'agreement'
  | 'certificate'
  | 'medical'
  | 'training'
  | 'other'

export const PERSONNEL_DOCUMENT_CATEGORIES: readonly PersonnelDocumentCategory[] = [
  'employment_contract',
  'contract_amendment',
  'job_description',
  'termination',
  'agreement',
  'certificate',
  'medical',
  'training',
  'other',
]

export interface PersonnelDocument {
  id: number
  employee_id: number
  category: PersonnelDocumentCategory
  title: string
  document_date: string | null
  valid_until: string | null
  note: string | null
  original_name: string
  mime_type: string
  size_bytes: number
  created_at: string
  updated_at: string
  uploaded_by_name: string | null
  /** PDF nebo obrázek — prohlížeč ho umí zobrazit v náhledu. */
  previewable: boolean
}

export interface PersonnelNote {
  id: number
  /** `null` = poznámka je po výmazu osobních údajů nečitelná. */
  body: string | null
  erased: boolean
  pinned: boolean
  created_at: string
  updated_at: string
  created_by_name: string | null
  updated_by_name: string | null
}

export interface PersonnelFile {
  employee_id: number
  documents: PersonnelDocument[]
  notes: PersonnelNote[]
  max_file_bytes: number
  allowed_extensions: string[]
}

export interface PersonnelDocumentMeta {
  category: PersonnelDocumentCategory
  title: string
  document_date: string | null
  valid_until: string | null
  note: string | null
}

export interface PersonnelNoteInput {
  body: string
  pinned: boolean
}

const base = (employeeId: number) => `/payroll/people/${employeeId}/personnel-file`

export const payrollPersonnelApi = {
  show: (employeeId: number) =>
    api.get<PersonnelFile>(base(employeeId)).then(response => response.data),

  upload: (employeeId: number, file: File, meta: PersonnelDocumentMeta) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('category', meta.category)
    fd.append('title', meta.title)
    if (meta.document_date) fd.append('document_date', meta.document_date)
    if (meta.valid_until) fd.append('valid_until', meta.valid_until)
    if (meta.note) fd.append('note', meta.note)
    return api.post<{ document: PersonnelDocument }>(
      `${base(employeeId)}/documents`,
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(response => response.data.document)
  },

  updateDocument: (employeeId: number, documentId: number, meta: PersonnelDocumentMeta) =>
    api.put<{ document: PersonnelDocument }>(`${base(employeeId)}/documents/${documentId}`, meta)
      .then(response => response.data.document),

  deleteDocument: (employeeId: number, documentId: number) =>
    api.delete(`${base(employeeId)}/documents/${documentId}`).then(() => undefined),

  /** Obsah dokumentu; `inline` vrátí PDF/obrázek s typem pro náhled, jinak přílohu. */
  content: (employeeId: number, documentId: number, inline: boolean) =>
    api.get<Blob>(`${base(employeeId)}/documents/${documentId}/content`, {
      params: inline ? { inline: '1' } : undefined,
      responseType: 'blob',
    }).then(response => response.data),

  createNote: (employeeId: number, input: PersonnelNoteInput) =>
    api.post<PersonnelFile>(`${base(employeeId)}/notes`, input).then(response => response.data),

  updateNote: (employeeId: number, noteId: number, input: PersonnelNoteInput) =>
    api.put<PersonnelFile>(`${base(employeeId)}/notes/${noteId}`, input).then(response => response.data),

  deleteNote: (employeeId: number, noteId: number) =>
    api.delete<PersonnelFile>(`${base(employeeId)}/notes/${noteId}`).then(response => response.data),
}
