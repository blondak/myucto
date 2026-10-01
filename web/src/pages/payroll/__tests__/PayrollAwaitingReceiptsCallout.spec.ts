import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ operationalHealth: vi.fn() }))

vi.mock('@/api/payroll', () => ({
  payrollApi: { operationalHealth: m.operationalHealth },
}))
vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    mobileKeyProfile: vi.fn(),
    startMobileKeyOutboxBatch: vi.fn(),
    downloadReceiptsBatchWithMobileKey: vi.fn(),
  },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import PayrollAwaitingReceiptsCallout from '../PayrollAwaitingReceiptsCallout.vue'

function health(awaiting: number) {
  return { isds_outbox: { failed: 0, send_uncertain: 0, rejected: 0, awaiting_receipt: awaiting } }
}

describe('PayrollAwaitingReceiptsCallout', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('upozorní na odeslané zprávy bez doručenky a nabídne jedno tlačítko', async () => {
    m.operationalHealth.mockResolvedValue(health(3))

    const wrapper = mount(PayrollAwaitingReceiptsCallout)
    await flushPromises()

    expect(wrapper.get('[data-test="payroll-awaiting-receipts"]').text())
      .toContain('payroll.dashboard.awaiting_receipts.title 3')
    expect(wrapper.get('[data-test="mobile-key-receipts-action"]').text()).toContain('3')
  })

  it('bez čekajících zpráv se neukáže', async () => {
    m.operationalHealth.mockResolvedValue(health(0))

    const wrapper = mount(PayrollAwaitingReceiptsCallout)
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-awaiting-receipts"]').exists()).toBe(false)
  })
})
