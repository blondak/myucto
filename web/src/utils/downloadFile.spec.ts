import { beforeEach, describe, expect, it, vi } from 'vitest'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@/api/client', () => ({ api: { get } }))

import { downloadApiFile } from '@/utils/downloadFile'

describe('downloadApiFile', () => {
  beforeEach(() => {
    get.mockReset()
    vi.stubGlobal('URL', {
      createObjectURL: vi.fn(() => 'blob:download'),
      revokeObjectURL: vi.fn(),
    })
  })

  it('počká na API odpověď, stáhne soubor pod názvem serveru a zachová jen jednu /api', async () => {
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    get.mockResolvedValue({
      data: new Blob(['<xml/>'], { type: 'application/xml' }),
      headers: { 'content-disposition': "attachment; filename*=UTF-8''DPHDP3-2026-08.xml" },
    })

    await downloadApiFile('/api/reports/dphdp3?year=2026&month=8')

    expect(get).toHaveBeenCalledWith('/reports/dphdp3?year=2026&month=8', { responseType: 'blob' })
    expect(click).toHaveBeenCalledOnce()
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:download')
  })
})
