import { afterEach, describe, expect, it, vi } from 'vitest'

const { post, del } = vi.hoisted(() => ({ post: vi.fn(), del: vi.fn() }))
vi.mock('../client', () => ({ api: { post, delete: del } }))

import { moneyS3Api, retryDelay } from '../moneyS3'

function limited(retryAfter: string) {
  return Object.assign(new Error('429'), { response: { status: 429, headers: { 'retry-after': retryAfter }, data: {} } })
}

describe('retryDelay', () => {
  it('u 429 čeká podle Retry-After, nejvýš minutu', () => {
    expect(retryDelay(limited('7'), 1)).toBe(7000)
    expect(retryDelay(limited('3600'), 1)).toBe(60_000)
  })

  it('bez použitelného Retry-After čeká rostoucí pauzu', () => {
    expect(retryDelay({ response: { status: 503, headers: {} } }, 2)).toBe(2000)
    expect(retryDelay(limited('x'), 3)).toBe(3000)
  })
})

describe('uploadChunked', () => {
  afterEach(() => {
    vi.useRealTimers()
    post.mockReset()
  })

  it('po 429 počká podle serveru a zopakuje část i dokončení', async () => {
    vi.useFakeTimers()
    const file = new File([new Uint8Array(10)], 'agenda.lz')
    post
      .mockResolvedValueOnce({ data: { token: 't', chunk_size: 6 } })
      .mockResolvedValueOnce({ data: { received: 6 } })
      .mockRejectedValueOnce(limited('2'))
      .mockResolvedValueOnce({ data: { received: 10 } })
      .mockRejectedValueOnce(limited('1'))
      .mockResolvedValueOnce({ data: { token: 't', job_id: 5 } })

    const done = moneyS3Api.uploadChunked(file)
    await vi.advanceTimersByTimeAsync(3000)

    await expect(done).resolves.toEqual({ token: 't', job_id: 5 })
    expect(post).toHaveBeenCalledTimes(6)
    expect(post.mock.calls.at(-1)?.[0]).toBe('/admin/imports/money-s3/uploads/t/complete')
  })
})

describe('deleteRun', () => {
  it('protokol zkoušky nanečisto maže na adrese Money S3', async () => {
    del.mockResolvedValueOnce({ data: { ok: true } })
    await expect(moneyS3Api.deleteRun(4)).resolves.toEqual({ ok: true })
    expect(del).toHaveBeenCalledWith('/admin/imports/money-s3/runs/4')
  })
})
