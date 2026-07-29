import { api } from './client'

/**
 * Sdílený slug helper — serverový Slugifier (GET /api/slug). Jediný zdroj pravdy,
 * takže preview kódu v UI odpovídá tomu, co by backend uložil. Použití: číselníky
 * e-shopu i admin/codebooks (viz CodeNameFields.vue).
 */
export const slugify = (text: string): Promise<string> =>
  api.get<{ slug: string }>('/slug', { params: { text } }).then(r => r.data.slug)
