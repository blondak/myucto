import { api } from './client'

/** Vlastní číselná řada kategorie (migrace 1333); null = dědí se z dodavatele. */
export interface RevenueCategoryNumbering {
  invoice_number_format: string | null
  proforma_number_format: string | null
  credit_note_number_format: string | null
  invoice_number_period: 'year' | 'month' | 'none' | null
}

export interface RevenueCategory extends RevenueCategoryNumbering {
  id: number
  code: string
  label: string
  display_order: number
  archived: boolean
  invoices_count?: number
  created_at: string
}

export interface RevenueCategoryCreatePayload extends Partial<RevenueCategoryNumbering> {
  code: string
  label: string
  display_order?: number
}

export const revenueCategoriesApi = {
  list: (includeArchived = false) =>
    api.get<RevenueCategory[]>('/revenue-categories', {
      params: includeArchived ? { include_archived: 1 } : undefined,
    }).then(r => r.data),
  create: (data: RevenueCategoryCreatePayload) =>
    api.post<RevenueCategory>('/revenue-categories', data).then(r => r.data),
  update: (id: number, data: Partial<RevenueCategoryCreatePayload> & { archived?: boolean }) =>
    api.put<RevenueCategory>(`/revenue-categories/${id}`, data).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: boolean; archived: boolean; usage_count?: number }>(
      `/revenue-categories/${id}`,
    ).then(r => r.data),
}
