import { afterEach, describe, expect, it, vi } from 'vitest'

const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }))
vi.mock('../client', () => ({ api: { post, get } }))

import { isPremierUploadReady, premierApi } from '../premier'

function conflict(received: number) {
  return Object.assign(new Error('409'), { response: { status: 409, headers: {}, data: { error: { received } } } })
}

describe('premierApi.uploadChunked', () => {
  afterEach(() => {
    vi.useRealTimers()
    post.mockReset()
  })

  it('posílá části na adresu PREMIER a po 409 naváže tam, kde server data má', async () => {
    const file = new File([new Uint8Array(10)], 'premier_backup.izip')
    const progress: number[] = []
    post
      .mockResolvedValueOnce({ data: { token: 'p1', chunk_size: 4 } })
      .mockResolvedValueOnce({ data: { received: 4 } })
      .mockRejectedValueOnce(conflict(8))
      .mockResolvedValueOnce({ data: { received: 10 } })
      .mockResolvedValueOnce({ data: { token: 'p1', job_id: 9 } })

    const started = vi.fn()
    await expect(premierApi.uploadChunked(file, sent => progress.push(sent), started)).resolves.toEqual({ token: 'p1', job_id: 9 })

    expect(started).toHaveBeenCalledWith('p1')
    expect(post.mock.calls[0]).toEqual(['/admin/imports/premier/uploads/chunked', { file_name: 'premier_backup.izip', size: 10 }])
    expect(post.mock.calls[1][0]).toBe('/admin/imports/premier/uploads/p1/chunks')
    expect((post.mock.calls[3][1] as FormData).get('offset')).toBe('8')
    expect(post.mock.calls.at(-1)?.[0]).toBe('/admin/imports/premier/uploads/p1/complete')
    expect(progress).toEqual([0, 4, 8, 10])
  })

  it('po výpadku serveru část zopakuje', async () => {
    vi.useFakeTimers()
    const file = new File([new Uint8Array(3)], 'premier_backup.icab')
    post
      .mockResolvedValueOnce({ data: { token: 'p2', chunk_size: 8 } })
      .mockRejectedValueOnce(Object.assign(new Error('503'), { response: { status: 503, headers: {}, data: {} } }))
      .mockResolvedValueOnce({ data: { received: 3 } })
      .mockResolvedValueOnce({ data: { token: 'p2', job_id: null } })

    const done = premierApi.uploadChunked(file)
    await vi.advanceTimersByTimeAsync(1000)

    await expect(done).resolves.toEqual({ token: 'p2', job_id: null })
    expect(post).toHaveBeenCalledTimes(4)
  })
})

describe('premierApi', () => {
  it('hotovou zálohu pozná podle seznamu agend', () => {
    expect(isPremierUploadReady({ token: 't', status: 'processing', file_name: 'x.izip', size: 1, received: 1, job_id: 1, error: null })).toBe(false)
    expect(isPremierUploadReady({
      token: 't', status: 'ready', file_name: 'x.izip', sha256: '', uploaded_at: '', uploaded_by: 1,
      supplier_ico: '12345678', default_year: 2026, agendas: [], preflight: {},
    })).toBe(true)
  })

  it('převod spouští jen s vybranými roky, bez druhu převodu', async () => {
    post.mockResolvedValueOnce({ data: { job_id: 5, status: 'queued', mode: 'dry_run' } })

    await expect(premierApi.start('p1', { mode: 'dry_run', years: [2025, 2026] })).resolves.toEqual({ job_id: 5, status: 'queued', mode: 'dry_run' })
    expect(post).toHaveBeenCalledWith('/admin/imports/premier/uploads/p1/start', { mode: 'dry_run', years: [2025, 2026] })
  })

  it('agendu bez IČO firmy vezme jako hotovou zálohu jen pro informaci', () => {
    expect(isPremierUploadReady({
      token: 't', status: 'ready', file_name: 'premier.izip', sha256: '', uploaded_at: '', uploaded_by: 1,
      supplier_ico: '12345678', default_year: 2025, preflight: {},
      agendas: [{
        dir: '.', ico: '87654321', dic: '', company: 'Jiná firma', year: 2025, entries: 120,
        has_accounting: true, has_payroll: false,
      }],
    })).toBe(true)
  })

  it('spouští běhy a čte je přes runs/run', async () => {
    get.mockResolvedValueOnce({ data: { items: [{ id: 1 }] } })
    await expect(premierApi.runs()).resolves.toEqual({ items: [{ id: 1 }] })
    expect(get).toHaveBeenCalledWith('/admin/imports/premier/runs')

    get.mockResolvedValueOnce({ data: { id: 1 } })
    await expect(premierApi.run(1)).resolves.toEqual({ id: 1 })
    expect(get).toHaveBeenCalledWith('/admin/imports/premier/runs/1')
  })
})
