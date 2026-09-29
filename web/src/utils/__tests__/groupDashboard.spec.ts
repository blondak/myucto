import { describe, expect, it } from 'vitest'
import type { GroupCompany } from '@/api/groupDashboard'
import { accountingGroupKey, groupRiskDestination, margin, metricState, yearChange } from '../groupDashboard'

const company: GroupCompany = {
  id: 1, name: 'Ukázková firma', accounting_mode: 'double_entry', document_basis: 'net', issues: [],
  available: { documents: true, accounting: true, bank: true, cash: true, receivables: true, payables: true, cashflow: true, cashflow_tax: true, cashflow_payroll: true, forecast: true },
}

describe('Group dashboard values', () => {
  it('targets the matching source of a risk, preserving currency and concentration period', () => {
    expect(groupRiskDestination({ kind: 'overdue_receivables', currency: 'CZK' })).toBe('/invoices?currency=CZK&year=all&overdue=1')
    expect(groupRiskDestination({ kind: 'client_concentration', currency: 'EUR' })).toBe('/crm?currency=EUR&months=12#client_concentration')
    expect(groupRiskDestination({ kind: 'vendor_concentration', currency: 'EUR' })).toBe('/crm?currency=EUR&months=12#vendor_concentration')
    expect(groupRiskDestination({ kind: 'document_loss', currency: 'CZK', source_currency: 'EUR' }, { from: '2094-03-15', to: '2094-06-10' })).toBe('/crm?currency=EUR&group_risk=1&from=2094-03-15&to=2094-06-10#group-document-result')
  })
  it('distinguishes missing rights, failed calculations, missing periods and known zeros', () => {
    expect(metricState(company, 'financial')).toBe('restricted')
    expect(metricState({ ...company, financial: null, issues: ['financial'] }, 'financial')).toBe('failed')
    expect(metricState({ ...company, accounting: null }, 'accounting')).toBe('no_period')
    expect(metricState({ ...company, accounting: null, issues: ['accounting'] }, 'accounting')).toBe('failed')
    expect(metricState({ ...company, financial: [{ currency: 'CZK', revenue: 0, costs: 0, profit: 0, previous_revenue: 0, previous_costs: 0, previous_profit: 0 }] }, 'financial')).toBe('available')
  })
  it('does not turn undefined percentages into zero and handles comparison against losses', () => {
    expect(yearChange(100, 0)).toBeNull()
    expect(yearChange(-50, -100)).toBe(50)
    expect(margin(0, -20)).toBeNull()
    expect(margin(100, -20)).toBe(-20)
  })
  it('keeps accounting totals with different currencies and periods distinct', () => {
    const row = { currency: 'CZK', from: '2094-01-01', to: '2094-09-30' }
    expect(accountingGroupKey(row)).not.toBe(accountingGroupKey({ ...row, currency: 'EUR' }))
    expect(accountingGroupKey(row)).not.toBe(accountingGroupKey({ ...row, from: '2094-04-01' }))
    expect(accountingGroupKey(row)).not.toBe(accountingGroupKey({ ...row, to: '2094-09-29' }))
  })
})
