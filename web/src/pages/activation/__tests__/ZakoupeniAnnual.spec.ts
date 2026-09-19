import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const auth = reactive({
  isSuperadmin: true,
  isManagedInstallation: false,
  refresh: vi.fn(async () => undefined),
})

const api = {
  status: vi.fn(),
  completePurchase: vi.fn(),
  startPurchase: vi.fn(),
  resumePendingChanges: vi.fn(async () => []),
  supportLink: vi.fn(),
  refresh: vi.fn(async () => ({ refreshed: true })),
  annualSwitchQuote: vi.fn(),
  switchToAnnual: vi.fn(),
  waitForChange: vi.fn(),
}

vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/api/license', () => ({ licenseApi: api }))
vi.mock('@/api/instanceStatus', () => ({
  ensureInstanceDunning: vi.fn(async () => undefined),
  instanceStatus: { dunning: ref(null) },
}))
vi.mock('@/api/instanceHealth', () => ({ resolveBillingNarrative: () => null }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string) => key,
    te: () => false,
    tm: () => [],
    rt: (value: string) => value,
  }),
}))

const Zakoupeni = (await import('../Zakoupeni.vue')).default

function status(period: 'month' | 'year' = 'month', over: Record<string, unknown> = {}) {
  return {
    state: 'active',
    instance_id: '123e4567-e89b-42d3-a456-426614174000',
    tier: 'multi10',
    max_companies: 10,
    users_licensed: 1,
    users_active: 1,
    companies_active: 1,
    valid_until: 1_900_000_000,
    trial_ends_at: null,
    overage_deadline: null,
    perpetual: false,
    commercial_features: true,
    tier_commercial: true,
    license_key_masked: 'MYU-…-AAAA',
    last_check_at: null,
    last_check_ok: true,
    buy_url: 'https://shop.example.test/objednavka',
    subscription: {
      state: 'active', period, auto_renew: true,
      next_charge_at: 1_900_000_000, cancelled_at: null, valid_until: 1_900_000_000,
    },
    company: { name: '', ic: '', dic: '', street: '', city: '', zip: '', email: '' },
    ...over,
  }
}

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/activation/purchase', component: { template: '<div />' } },
      { path: '/admin/instance-export', component: { template: '<div />' } },
      { path: '/hosting', component: { template: '<div />' } },
    ],
  })
  await router.push('/activation/purchase')
  await router.isReady()
  const wrapper = mount(Zakoupeni, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  window.history.replaceState({}, '', '/activation/purchase')
  auth.isSuperadmin = true
  auth.isManagedInstallation = false
  auth.refresh.mockClear()
  api.status.mockReset().mockResolvedValue(status('month'))
  api.completePurchase.mockReset()
  api.startPurchase.mockReset()
  api.resumePendingChanges.mockClear()
  api.annualSwitchQuote.mockReset()
  api.switchToAnnual.mockReset()
  api.waitForChange.mockReset()
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})

describe('Zakoupeni.vue — přechod na roční předplatné', () => {
  it('u měsíčního předplatného nabídne kalkulaci a po potvrzení strhne roční cenu', async () => {
    api.annualSwitchQuote.mockResolvedValue({
      current_period: 'month', new_period: 'year',
      amount: 6900, monthly_amount: 690, months_charged: 10, saving: 1380,
      currency: 'CZK', period_end: 1_900_000_000, new_period_end: 1_931_536_000,
      quote_token: 'period-quote', expires_at: null,
    })
    api.switchToAnnual.mockResolvedValue({
      new_period: 'year', amount_charged: 6900, valid_until: 1_931_536_000,
      pending: false, order_id: '42', state: status('year'),
    })

    const wrapper = await mountPage()
    expect(wrapper.find('[data-annual-switch]').exists()).toBe(true)

    await wrapper.find('[data-annual-quote-cta]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-annual-quote]').exists()).toBe(true)

    await wrapper.find('[data-annual-pay-cta]').trigger('click')
    await flushPromises()

    expect(api.switchToAnnual).toHaveBeenCalledWith('period-quote')
    // Bez tokenu z kalkulace se nic nestrhává a bez obnovy stavu by obrazovka
    // dál nabízela přechod, který už proběhl.
    expect(wrapper.find('[data-annual-switch]').exists()).toBe(false)
    expect(auth.refresh).toHaveBeenCalled()
  })

  it('u zaplaceného ročního předplatného nenabízí prodloužení dopředu', async () => {
    api.status.mockResolvedValue(status('year'))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-annual-switch]').exists()).toBe(false)
    expect(api.annualSwitchQuote).not.toHaveBeenCalled()
  })

  it('bez spočítané ceny se nic nestrhává', async () => {
    const wrapper = await mountPage()

    expect(wrapper.find('[data-annual-pay-cta]').exists()).toBe(false)
    expect(api.switchToAnnual).not.toHaveBeenCalled()
  })
})
