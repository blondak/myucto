import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollDocument } from '@/api/payroll'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: {
    get: m.get,
    post: m.post,
  },
}))

import { payrollApi } from '@/api/payroll'

describe('payroll document downloads', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.post.mockResolvedValue({
      data: {
        token: 'c'.repeat(64),
        expires_at: '2026-08-03 12:05:00',
      },
    })
    m.get.mockResolvedValue({ data: new Blob(['synthetic']) })
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:synthetic')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
  })

  it('keeps the one-time token in a request header and out of the URL', async () => {
    const item: PayrollDocument = {
      id: 42,
      run_id: 7,
      revision_id: 8,
      employee_id: 9,
      employee_name: 'Testovací Zaměstnanec',
      document_kind: 'payslip',
      file_sha256: 'a'.repeat(64),
      size_bytes: 9,
      mime_type: 'application/pdf',
      suggested_filename: 'vyplatni-paska-2026-07-abcdef123456.pdf',
      created_at: '2026-08-03 12:00:00',
    }

    await payrollApi.downloadDocument(item)

    expect(m.post).toHaveBeenCalledWith('/payroll/documents/42/download-grant')
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/documents/42/download',
      expect.objectContaining({
        responseType: 'blob',
        headers: { 'X-Payroll-Download-Token': 'c'.repeat(64) },
      }),
    )
    expect(m.get.mock.calls[0][0]).not.toContain('token=')
  })

  it('uses the dedicated annual anchor endpoints', async () => {
    m.get.mockResolvedValueOnce({ data: { year: 2026, items: [] } })
    await payrollApi.listAnnualDocuments(2026)
    expect(m.get).toHaveBeenCalledWith('/payroll/documents/annual', {
      params: { year: 2026 },
    })

    m.post.mockResolvedValueOnce({ data: { id: 77 } })
    await payrollApi.generatePayrollSheet(9, 2026)
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/people/9/documents/payroll-sheet/2026',
      {},
    )
  })
})
