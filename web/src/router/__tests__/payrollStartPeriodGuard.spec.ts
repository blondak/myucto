import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  supplierId: 2,
}))

vi.mock('@/api/client', () => ({ api: { get: m.get } }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: m.supplierId }),
}))

import {
  payrollStartPeriodGuard,
  resetPayrollStartPeriodCache,
  startAwarePayrollPeriod,
} from '@/router/payrollStartPeriodGuard'

function route(name: string, query: Record<string, string> = {}, params: Record<string, string> = {}): RouteLocationNormalized {
  return { name, query, params, path: '/', fullPath: '/', hash: '', matched: [], meta: {}, redirectedFrom: undefined } as unknown as RouteLocationNormalized
}

describe('výchozí období mzdových obrazovek u převzaté firmy', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 8, 27, 10, 0, 0))
    resetPayrollStartPeriodCache()
    m.get.mockReset()
  })

  it('první měsíc vedení mezd má přednost před převzatým předchozím měsícem', () => {
    expect(startAwarePayrollPeriod('2026-09')).toBe('2026-09')
    expect(startAwarePayrollPeriod('2026-01')).toBe('2026-08')
    expect(startAwarePayrollPeriod(null)).toBe('2026-08')
  })

  it('bez ?period doplní u převzaté firmy první měsíc vedení mezd', async () => {
    m.get.mockResolvedValue({ data: { state: { start_period: '2026-09' } } })

    const result = await payrollStartPeriodGuard(route('payroll-submissions-tab', {}, { tab: 'jmhz' }))

    expect(result).toMatchObject({ query: { period: '2026-09' } })
  })

  it('výslovné období, zdravotní záložku ani běžnou firmu nepřepisuje', async () => {
    m.get.mockResolvedValue({ data: { state: { start_period: '2026-01' } } })

    expect(await payrollStartPeriodGuard(route('payroll-time', { period: '2026-05' }))).toBe(true)
    expect(await payrollStartPeriodGuard(route('payroll-submissions-tab', {}, { tab: 'health' }))).toBe(true)
    expect(await payrollStartPeriodGuard(route('payroll-time'))).toBe(true)
    expect(await payrollStartPeriodGuard(route('invoices'))).toBe(true)
  })
})
