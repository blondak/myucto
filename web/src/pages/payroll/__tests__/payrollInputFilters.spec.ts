import { describe, expect, it, vi } from 'vitest'
import {
  emptyPayrollInputFilters,
  groupPayrollInputFailures,
  payrollInputFilterParams,
  payrollInputFiltersActive,
  payrollInputFiltersFromQuery,
  payrollInputFiltersToQuery,
  payrollInputListEditable,
  runPayrollInputBatch,
  type PayrollInputBatchPass,
} from '@/pages/payroll/payrollInputFilters'

describe('payrollInputFilters', () => {
  it('reads a valid filter from the URL', () => {
    expect(payrollInputFiltersFromQuery({
      q: ' Novák ',
      employee: '8',
      component: '5,6,5',
      source: 'import',
      status: 'draft,approved',
      import: '12',
      group: 'employee',
    })).toEqual({
      q: 'Novák',
      employeeId: 8,
      componentIds: [5, 6],
      sourceKind: 'import',
      statuses: ['draft', 'approved'],
      importId: 12,
      groupBy: 'employee',
    })
  })

  /** Slepý odkaz z bookmarku nesmí stránku rozbít — neplatné hodnoty se zahodí. */
  it('drops invalid URL values instead of failing', () => {
    expect(payrollInputFiltersFromQuery({
      employee: 'abc',
      component: '0,-2,x,7',
      source: 'magic',
      status: 'cancelled,draft',
      import: '1.5',
      group: 'month',
    })).toEqual({
      ...emptyPayrollInputFilters(),
      componentIds: [7],
      statuses: ['draft'],
    })
  })

  it('writes only the filter keys to the URL and keeps the rest', () => {
    const state = {
      ...emptyPayrollInputFilters(),
      q: 'SYN-1',
      componentIds: [3, 4],
      statuses: ['draft' as const],
    }
    expect(payrollInputFiltersToQuery(
      { tab: 'inputs', period: '2026-06', employment: '12', import: '9', group: 'component' },
      state,
    )).toEqual({
      tab: 'inputs',
      period: '2026-06',
      employment: '12',
      q: 'SYN-1',
      component: '3,4',
      status: 'draft',
    })
  })

  it('round-trips the filter through the URL', () => {
    const state = {
      q: 'Alfa',
      employeeId: 3,
      componentIds: [1, 2],
      sourceKind: 'manual' as const,
      statuses: ['approved' as const, 'locked' as const],
      importId: 4,
      groupBy: 'component' as const,
    }
    const query = payrollInputFiltersToQuery({}, state) as Record<string, string>
    expect(payrollInputFiltersFromQuery(query)).toEqual(state)
  })

  it('maps the filter to server parameters, omitting empty values', () => {
    expect(payrollInputFilterParams(emptyPayrollInputFilters())).toEqual({})
    expect(payrollInputFilterParams({
      q: '  ',
      employeeId: 3,
      componentIds: [1, 2],
      sourceKind: 'import',
      statuses: ['draft'],
      importId: 7,
      groupBy: 'employee',
    })).toEqual({
      employee_id: 3,
      component_id: '1,2',
      source_kind: 'import',
      status: 'draft',
      import_id: 7,
    })
  })

  it('treats grouping as a view, not as a narrowing', () => {
    expect(payrollInputFiltersActive({ ...emptyPayrollInputFilters(), groupBy: 'employee' })).toBe(false)
    expect(payrollInputFiltersActive({ ...emptyPayrollInputFilters(), statuses: ['draft'] })).toBe(true)
  })

  it('allows editing imported drafts, never derived or approved inputs', () => {
    expect(payrollInputListEditable({ status: 'draft', source_kind: 'manual' })).toBe(true)
    expect(payrollInputListEditable({ status: 'draft', source_kind: 'import' })).toBe(true)
    expect(payrollInputListEditable({ status: 'approved', source_kind: 'import' })).toBe(false)
    expect(payrollInputListEditable({ status: 'draft', source_kind: 'travel' })).toBe(false)
    expect(payrollInputListEditable({ status: 'draft', source_kind: 'absence' })).toBe(false)
    expect(payrollInputListEditable({ status: 'draft', source_kind: 'recurring' })).toBe(false)
  })

  it('groups failures by reason', () => {
    expect(groupPayrollInputFailures([
      { id: 1, code: 'a', message: 'Limit.' },
      { id: 2, code: 'a', message: 'Limit.' },
      { id: 3, code: 'b', message: 'Docházka.' },
    ])).toEqual([
      { message: 'Limit.', count: 2 },
      { message: 'Docházka.', count: 1 },
    ])
  })

  it('continues a bulk action from the server cursor until it is complete', async () => {
    const passes: PayrollInputBatchPass[] = [
      { done: [1, 2], failed: [{ id: 3, code: 'x', message: 'X' }], skipped: [], remaining: 5, complete: false, next_after_id: 3 },
      { done: [4, 5], failed: [], skipped: [{ id: 6, code: 'y', message: 'Y' }], remaining: 1, complete: true, next_after_id: 6 },
    ]
    const call = vi.fn(async (_afterId: number) => passes.shift()!)
    const progress = vi.fn()

    const result = await runPayrollInputBatch(call, progress)

    expect(call.mock.calls.map(args => args[0])).toEqual([0, 3])
    expect(progress).toHaveBeenLastCalledWith(4)
    expect(result).toEqual({
      done: 4,
      failed: [{ id: 3, code: 'x', message: 'X' }],
      skipped: 1,
      remaining: 1,
      complete: true,
    })
  })

  /** Kurzor, který se nepohne, by smyčku roztočil donekonečna. */
  it('stops when the server cursor does not advance', async () => {
    const call = vi.fn(async () => ({ done: [], failed: [], skipped: [], remaining: 9, complete: false, next_after_id: 0 }))

    const result = await runPayrollInputBatch(call)

    expect(call).toHaveBeenCalledTimes(1)
    expect(result.complete).toBe(false)
    expect(result.remaining).toBe(9)
  })
})
