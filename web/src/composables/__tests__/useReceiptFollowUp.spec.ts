import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  downloadReceiptsInMobileKeySession: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    downloadReceiptsInMobileKeySession: m.downloadReceiptsInMobileKeySession,
  },
}))

import { useReceiptFollowUp } from '../useReceiptFollowUp'

function harness(onResult: () => void, delays = [1000, 2000]) {
  let api: ReturnType<typeof useReceiptFollowUp> | null = null
  const wrapper = mount(defineComponent({
    setup() {
      api = useReceiptFollowUp(() => 'production', onResult, delays)
      return () => h('div')
    },
  }))
  return { wrapper, api: api! }
}

describe('useReceiptFollowUp', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('polls the retained session with back-off until nothing is pending', async () => {
    m.downloadReceiptsInMobileKeySession
      .mockResolvedValueOnce({ attached: 0, pending: 3, failed: 0, items: [], session_open: true })
      .mockResolvedValueOnce({ attached: 3, pending: 0, failed: 0, items: [], session_open: false })
    const onResult = vi.fn()
    const { api } = harness(onResult)

    api.start({ session_token: 'token', expires_at: '2026-10-01T12:05:00+02:00' })
    expect(api.active.value).toBe(true)
    expect(m.downloadReceiptsInMobileKeySession).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1000)
    expect(m.downloadReceiptsInMobileKeySession).toHaveBeenCalledTimes(1)
    expect(m.downloadReceiptsInMobileKeySession).toHaveBeenLastCalledWith('token', 'production', false)

    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
    expect(m.downloadReceiptsInMobileKeySession).toHaveBeenCalledTimes(2)
    expect(onResult).toHaveBeenCalledTimes(2)
    expect(api.active.value).toBe(false)

    await vi.advanceTimersByTimeAsync(60_000)
    expect(m.downloadReceiptsInMobileKeySession).toHaveBeenCalledTimes(2)
  })

  it('ends the session on the last attempt and never starts a new login', async () => {
    m.downloadReceiptsInMobileKeySession.mockResolvedValue({
      attached: 0, pending: 1, failed: 0, items: [], session_open: true,
    })
    const { api } = harness(vi.fn(), [1000])

    api.start({ session_token: 'token', expires_at: '' })
    await vi.advanceTimersByTimeAsync(1000)
    await flushPromises()
    await vi.advanceTimersByTimeAsync(0)
    await flushPromises()

    expect(m.downloadReceiptsInMobileKeySession).toHaveBeenCalledTimes(2)
    expect(m.downloadReceiptsInMobileKeySession).toHaveBeenLastCalledWith('token', 'production', true)
    expect(api.active.value).toBe(false)
  })

  it('stops quietly when the session expired', async () => {
    m.downloadReceiptsInMobileKeySession.mockRejectedValue(new Error('isds_mobile_session_expired'))
    const { api } = harness(vi.fn())

    api.start({ session_token: 'token', expires_at: '' })
    await vi.advanceTimersByTimeAsync(1000)
    await flushPromises()

    expect(api.active.value).toBe(false)
    await vi.advanceTimersByTimeAsync(60_000)
    expect(m.downloadReceiptsInMobileKeySession).toHaveBeenCalledTimes(1)
  })

  it('does nothing without a retained session', () => {
    const { api } = harness(vi.fn())
    api.start(null)
    expect(api.active.value).toBe(false)
  })
})
