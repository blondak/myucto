import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  readiness: vi.fn(),
  activate: vi.fn(),
}))

vi.mock('@/api/payrollEnforcement', () => ({
  payrollEnforcementApi: { bulkReadiness: m.readiness, bulkActivate: m.activate },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import EnforcementBulkActivationPanel from '@/components/payroll/EnforcementBulkActivationPanel.vue'

function row(id: number, missing: string[] = []) {
  return {
    case_id: id, row_version: 3, employee_id: 100 + id, employee_name: `Syntetický ${id}`,
    case_key: `k${id}`, effective_from: '2026-05-01', claim_count: 1, missing,
    legal_message: null, decision_document_id: missing.length ? null : 9,
  }
}

describe('EnforcementBulkActivationPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('připravené případy zahájí jedním potvrzením a u chybějících nabídne proklik', async () => {
    m.readiness.mockResolvedValue([row(1), row(2, ['legal_parties', 'order_issued_on'])])
    m.activate.mockResolvedValue([{ case_id: 1, status: 'activated', message: null }])
    const wrapper = mount(EnforcementBulkActivationPanel, { props: { canWrite: true } })
    await flushPromises()

    await wrapper.get('[data-test="enforcement-bulk-toggle"]').trigger('click')
    expect(wrapper.get('[data-test="enforcement-bulk-incomplete-2"]').text())
      .toContain('payroll.enforcement.bulk.missing.legal_parties')
    await wrapper.get('[data-test="enforcement-bulk-open-2"]').trigger('click')
    expect(wrapper.emitted('open-case')?.[0]).toEqual([2, 102])

    const activate = wrapper.get('[data-test="enforcement-bulk-activate"]')
    expect(activate.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="enforcement-bulk-confirm"]').setValue(true)
    await activate.trigger('click')
    await flushPromises()

    expect(m.activate).toHaveBeenCalledWith([{ case_id: 1, row_version: 3 }])
    expect(wrapper.emitted('activated')).toBeTruthy()
  })
})
