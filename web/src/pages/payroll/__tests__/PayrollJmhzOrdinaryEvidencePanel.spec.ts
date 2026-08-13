import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  confirm: vi.fn(),
  canWrite: vi.fn(() => true),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    jmhzOrdinaryEvidence: m.get,
    confirmJmhzOrdinaryEvidence: m.confirm,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollJmhzOrdinaryEvidencePanel from '@/pages/payroll/PayrollJmhzOrdinaryEvidencePanel.vue'

const run = {
  id: 8,
  revision_id: 18,
  revision_no: 2,
  revision_status: 'approved',
  period_start: '2026-08-01',
}

describe('PayrollJmhzOrdinaryEvidencePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.get.mockResolvedValue(null)
    m.confirm.mockResolvedValue({
      id: 31,
      revision_id: 18,
      confirmed_at: '2026-08-13T12:00:00Z',
      source_manifest_sha256: 'a'.repeat(64),
    })
  })

  it('vyžaduje pět samostatných potvrzení a uloží je jedním idempotentním příkazem', async () => {
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    const checks = wrapper.findAll('input[type="checkbox"]')
    expect(checks).toHaveLength(5)
    expect(checks.every(check => !(check.element as HTMLInputElement).checked)).toBe(true)
    const button = wrapper.get('button')
    expect(button.attributes('disabled')).toBeDefined()

    for (const check of checks) await check.setValue(true)
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    await flushPromises()

    expect(m.confirm).toHaveBeenCalledWith(18, expect.any(String))
    expect(wrapper.text()).toContain('jmhz_evidence_confirmed')
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0)
  })

  it('v režimu jen pro čtení nepovolí potvrzení', async () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mount(PayrollJmhzOrdinaryEvidencePanel, {
      props: { runs: [run] as never[] },
    })
    await flushPromises()

    expect(wrapper.findAll('input[type="checkbox"]')
      .every(check => check.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
  })
})
