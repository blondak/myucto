import { afterEach, describe, expect, it, vi } from 'vitest'

const { post, get, download } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), download: vi.fn() }))
vi.mock('../client', () => ({ api: { post, get } }))
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: download }))

import { isPohodaUploadReady, pohodaApi } from '../pohoda'

function conflict(received: number) {
  return Object.assign(new Error('409'), { response: { status: 409, headers: {}, data: { error: { received } } } })
}

describe('pohodaApi.uploadChunked', () => {
  afterEach(() => {
    vi.useRealTimers()
    post.mockReset()
  })

  it('posílá části na adresu POHODY a po 409 naváže tam, kde server data má', async () => {
    const file = new File([new Uint8Array(10)], 'pohoda_export.zip')
    const progress: number[] = []
    post
      .mockResolvedValueOnce({ data: { token: 'p1', chunk_size: 4 } })
      .mockResolvedValueOnce({ data: { received: 4 } })
      .mockRejectedValueOnce(conflict(8))
      .mockResolvedValueOnce({ data: { received: 10 } })
      .mockResolvedValueOnce({ data: { token: 'p1', job_id: 9 } })

    const started = vi.fn()
    await expect(pohodaApi.uploadChunked(file, sent => progress.push(sent), started)).resolves.toEqual({ token: 'p1', job_id: 9 })

    expect(started).toHaveBeenCalledWith('p1')
    expect(post.mock.calls[0]).toEqual(['/admin/imports/pohoda/uploads/chunked', { file_name: 'pohoda_export.zip', size: 10 }])
    expect(post.mock.calls[1][0]).toBe('/admin/imports/pohoda/uploads/p1/chunks')
    expect((post.mock.calls[3][1] as FormData).get('offset')).toBe('8')
    expect(post.mock.calls.at(-1)?.[0]).toBe('/admin/imports/pohoda/uploads/p1/complete')
    expect(progress).toEqual([0, 4, 8, 10])
  })

  it('po výpadku serveru část zopakuje', async () => {
    vi.useFakeTimers()
    const file = new File([new Uint8Array(3)], 'pohoda_export.zip')
    post
      .mockResolvedValueOnce({ data: { token: 'p2', chunk_size: 8 } })
      .mockRejectedValueOnce(Object.assign(new Error('503'), { response: { status: 503, headers: {}, data: {} } }))
      .mockResolvedValueOnce({ data: { received: 3 } })
      .mockResolvedValueOnce({ data: { token: 'p2', job_id: null } })

    const done = pohodaApi.uploadChunked(file)
    await vi.advanceTimersByTimeAsync(1000)

    await expect(done).resolves.toEqual({ token: 'p2', job_id: null })
    expect(post).toHaveBeenCalledTimes(4)
  })
})

describe('pohodaApi', () => {
  it('hotový export pozná podle seznamu agend', () => {
    expect(isPohodaUploadReady({ token: 't', status: 'processing', file_name: 'x.zip', size: 1, received: 1, job_id: 1, error: null })).toBe(false)
    expect(isPohodaUploadReady({
      token: 't', status: 'ready', file_name: 'x.zip', sha256: '', uploaded_at: '', uploaded_by: 1,
      supplier_ico: '12345678', default_year: 2026, agendas: [], preflight: {},
    })).toBe(true)
  })

  it('převod spouští s druhem převodu (účetnictví nebo mzdy)', async () => {
    post.mockResolvedValueOnce({ data: { job_id: 5, status: 'queued', mode: 'dry_run' } })

    await expect(pohodaApi.start('p1', { mode: 'dry_run', year: 2026, kind: 'payroll' })).resolves.toEqual({ job_id: 5, status: 'queued', mode: 'dry_run' })
    expect(post).toHaveBeenCalledWith('/admin/imports/pohoda/uploads/p1/start', { mode: 'dry_run', year: 2026, kind: 'payroll' })
  })

  it('agendu jen se mzdami vezme jako hotový export', () => {
    expect(isPohodaUploadReady({
      token: 't', status: 'ready', file_name: 'mzdy.zip', sha256: '', uploaded_at: '', uploaded_by: 1,
      supplier_ico: '12345678', default_year: 2026, preflight: {}, payroll_preflight: { 2026: [] },
      agendas: [{
        ico: '12345678', year: 2026, company: '', program: 'POHODA Mzdy', exported_at: null, files: [],
        counts: { journal: 0, opening: 0, first_date: null, last_date: null, issued: 0, purchase: 0, internal: 0, cash: 0, bank: 0, partners: 0 },
        has_accounting: false, has_payroll: true, payroll: { employees: 2, months: 2, payslips: 4, first: '2026-01', last: '2026-02' },
      }],
    })).toBe(true)
  })

  it('exportní nástroj stahuje přes společné stahování souborů', async () => {
    get.mockResolvedValueOnce({ data: { files: [{ name: 'Export-Pohoda.cmd', size: 120 }] } })

    await expect(pohodaApi.toolFiles()).resolves.toEqual({ files: [{ name: 'Export-Pohoda.cmd', size: 120 }] })
    await pohodaApi.downloadTool()
    await pohodaApi.downloadToolFile('Export-Pohoda.ps1')

    expect(get).toHaveBeenCalledWith('/admin/imports/pohoda/tool')
    expect(download).toHaveBeenNthCalledWith(1, '/admin/imports/pohoda/tool/download', 'pohoda-export.zip')
    expect(download).toHaveBeenNthCalledWith(2, '/admin/imports/pohoda/tool/download?name=Export-Pohoda.ps1', 'Export-Pohoda.ps1')
  })
})
