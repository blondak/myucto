import type { GroupCompany, GroupSection, Risk } from '@/api/groupDashboard'

export type GroupField = 'financial' | 'accounting' | 'monthly' | 'forecast' | 'bank' | 'cash' | 'receivables' | 'payables' | 'cashflow' | 'risks'
export type MetricState = 'available' | 'restricted' | 'failed' | 'no_period'

export const GROUP_SECTIONS: GroupSection[] = ['overview', 'trends', 'forecast', 'cashflow', 'balances', 'receivables', 'risks']
export const SECTION_FIELDS: Record<GroupSection, GroupField[]> = {
  overview: ['financial', 'accounting'], trends: ['monthly'], forecast: ['forecast'], cashflow: ['cashflow'],
  balances: ['bank', 'cash'], receivables: ['receivables', 'payables'], risks: ['risks'],
}

export function metricState(company: GroupCompany, field: GroupField): MetricState {
  if (company[field] === undefined) return 'restricted'
  if (company[field] !== null) return 'available'
  return field === 'accounting' && !company.issues.includes(field) ? 'no_period' : 'failed'
}

export function yearChange(current: number | null, previous: number | null): number | null {
  return current === null || previous === null || previous === 0 ? null : (current - previous) / Math.abs(previous) * 100
}

export function margin(revenue: number | null, profit: number | null): number | null {
  return revenue === null || profit === null || revenue === 0 ? null : profit / revenue * 100
}

export function accountingGroupKey(row: { currency: string; from: string; to: string }): string {
  return `${row.currency}:${row.from}:${row.to}`
}

export function groupRiskDestination(risk: Risk, period?: { from: string; to: string }): string {
  const query = new URLSearchParams({ currency: risk.source_currency ?? risk.currency })
  if (risk.kind === 'overdue_receivables' || risk.kind === 'overdue_payables') {
    query.set('year', 'all')
    query.set('overdue', '1')
    return `${risk.kind === 'overdue_receivables' ? '/invoices' : '/purchase-invoices'}?${query}`
  }
  if (risk.kind === 'document_loss' && period) {
    query.set('group_risk', '1')
    query.set('from', period.from)
    query.set('to', period.to)
    return `/crm?${query}#group-document-result`
  }
  query.set('months', '12')
  return `/crm?${query}#${risk.kind === 'document_loss' ? 'profit' : risk.kind}`
}
