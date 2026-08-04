import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollRegzelSnapshot } from '@/api/payroll'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: {
    get: m.get,
    post: m.post,
    put: m.put,
  },
}))

import { payrollApi } from '@/api/payroll'

describe('payroll REGZEL API', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('posílá prostředí odděleně v historii, prepare i downloadu', async () => {
    m.get.mockResolvedValueOnce({ data: { items: [] } })
    await payrollApi.regzelSnapshots('test')
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/submissions/regzel/snapshots',
      { params: { environment: 'test' } },
    )

    m.post.mockResolvedValueOnce({ data: { snapshot: { id: 9 } } })
    await payrollApi.prepareRegzel({
      office_id: 42,
      environment: 'test',
      evidence_confirmed: true,
      idempotency_key: 'synthetic-key',
    })
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/submissions/regzel/prepare',
      expect.objectContaining({
        office_id: 42,
        environment: 'test',
        evidence_confirmed: true,
      }),
    )

    const snapshot: PayrollRegzelSnapshot = {
      id: 9,
      environment: 'test',
      office_id: 42,
      document_type: 'REGZELDOPL25',
      interaction_code: 'supplemental_information',
      mapping_version: 'regzeldopl25-map-1',
      xsd_version: '1.2',
      source_snapshot_hash: 'a'.repeat(64),
      xml_sha256: 'b'.repeat(64),
      xml_byte_size: 123,
    }
    m.get.mockResolvedValueOnce({ data: new Blob(['synthetic']) })
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:synthetic-regzel')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    await payrollApi.downloadRegzelSnapshot(snapshot)
    expect(m.get).toHaveBeenLastCalledWith(
      '/payroll/submissions/regzel/snapshots/9/xml',
      {
        params: { environment: 'test' },
        responseType: 'blob',
      },
    )
  })
})
