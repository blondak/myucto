import { api } from './client'

export interface OtherItemSchedule {
  id: number
  source_item_id: number | null
  frequency: 'monthly' | 'quarterly' | 'yearly'
  anchor_on: string
  due_days: number
  ends_on: string | null
  next_index: number
  status: 'active' | 'paused'
  template: Record<string, unknown>
  occurrences?: Array<{ occurrence_index: number; item_id: number; issued_on: string; status: string }>
}

export interface OtherItemInstallment {
  id?: number
  position?: number
  due_on: string
  amount: number
}

export const otherItemPlansApi = {
  schedules: () => api.get<{ items: OtherItemSchedule[] }>('/accounting/other-items/schedules').then(r => r.data.items),
  schedule: (id: number) => api.get<OtherItemSchedule>(`/accounting/other-items/schedules/${id}`).then(r => r.data),
  createSchedule: (itemId: number, payload: { frequency: OtherItemSchedule['frequency']; ends_on: string | null }) =>
    api.post<OtherItemSchedule>(`/accounting/other-items/${itemId}/schedule`, payload).then(r => r.data),
  generate: (id: number, through: string) =>
    api.post<{ created_ids: number[]; schedule: OtherItemSchedule }>(`/accounting/other-items/schedules/${id}/generate`, { through }).then(r => r.data),
  setStatus: (id: number, status: OtherItemSchedule['status']) =>
    api.put<OtherItemSchedule>(`/accounting/other-items/schedules/${id}/status`, { status }).then(r => r.data),
  installments: (itemId: number) => api.get<{ items: OtherItemInstallment[] }>(`/accounting/other-items/${itemId}/installments`).then(r => r.data.items),
  setInstallments: (itemId: number, items: OtherItemInstallment[]) =>
    api.put<{ items: OtherItemInstallment[] }>(`/accounting/other-items/${itemId}/installments`, { items }).then(r => r.data.items),
}
