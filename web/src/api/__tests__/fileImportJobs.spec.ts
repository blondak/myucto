import { beforeEach, describe, expect, it, vi } from 'vitest'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('../client', () => ({ api: { post } }))

import { IMPORT_JOB_MAX_FILE_BYTES, startImportJob } from '../imports'

describe('file import upload chunks', () => {
  beforeEach(() => post.mockReset())

  it('stages twenty files, then finalizes the same purchase batch and reports progress', async () => {
    const files = Array.from({ length: 21 }, (_, i) => new File(['<Invoice/>'], `synthetic-${i}.isdoc`))
    const result = { job_id: 23, status: 'queued', files: 21, kind: 'purchase' }
    post.mockResolvedValueOnce({ data: { upload_token: '0123456789abcdef' } }).mockResolvedValueOnce({ data: result })
    const progress = vi.fn()
    expect(await startImportJob(files, 'purchase', 'draft', progress)).toEqual(result)
    expect(post).toHaveBeenCalledTimes(2)
    const first = new URL(post.mock.calls[0]![0], 'https://example.invalid')
    const last = new URL(post.mock.calls[1]![0], 'https://example.invalid')
    expect(first.searchParams.get('stage')).toBe('1')
    expect(first.searchParams.get('file_count')).toBe('20')
    expect((post.mock.calls[0]![1] as FormData).getAll('files[]')).toHaveLength(20)
    expect(last.searchParams.get('stage')).toBeNull()
    expect(last.searchParams.get('upload')).toBe('0123456789abcdef')
    expect(last.searchParams.get('file_count')).toBe('1')
    expect(last.searchParams.get('purchase_status')).toBe('draft')
    expect(last.searchParams.get('kind')).toBe('purchase')
    expect(progress.mock.calls).toEqual([[20, 21], [21, 21]])
  })

  it('does not finalize after a failed intermediate chunk', async () => {
    const files = Array.from({ length: 41 }, (_, i) => new File(['x'], `synthetic-${i}.xml`))
    const error = new Error('synthetic upload failure')
    post.mockResolvedValueOnce({ data: { upload_token: '0123456789abcdef' } }).mockRejectedValueOnce(error)
    await expect(startImportJob(files)).rejects.toBe(error)
    expect(post).toHaveBeenCalledTimes(2)
    expect(post.mock.calls.every(call => new URL(call[0], 'https://example.invalid').searchParams.get('stage') === '1')).toBe(true)
  })

  it('rejects an oversized individual file before uploading any part', async () => {
    const file = new File(['synthetic'], 'synthetic.zip')
    Object.defineProperty(file, 'size', { value: IMPORT_JOB_MAX_FILE_BYTES + 1 })
    await expect(startImportJob([file])).rejects.toThrow('import_file_too_large')
    expect(post).not.toHaveBeenCalled()
  })

  it('rejects an oversized selection without silently importing its prefix', async () => {
    const file = new File(['synthetic'], 'synthetic.xml')
    await expect(startImportJob(Array.from({ length: 5001 }, () => file))).rejects.toThrow('import_too_many_files')
    expect(post).not.toHaveBeenCalled()
  })
})
