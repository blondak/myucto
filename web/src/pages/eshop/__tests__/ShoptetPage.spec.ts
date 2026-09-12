import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { ShoptetSettings } from '@/api/shoptet'

const m = vi.hoisted(() => ({
  settings: vi.fn(),
  batches: vi.fn(),
  reviewQueue: vi.fn(),
  previewUpload: vi.fn(),
  apply: vi.fn(),
  rotateFeed: vi.fn(),
  saveSettings: vi.fn(),
  setOrderUrl: vi.fn(),
  importDocuments: vi.fn(),
  replace: vi.fn(),
  canWrite: true,
  query: {} as Record<string, string>,
}))

vi.mock('@/api/shoptet', () => ({
  shoptetApi: {
    settings: m.settings,
    batches: m.batches,
    reviewQueue: m.reviewQueue,
    previewUpload: m.previewUpload,
    previewUrl: vi.fn(),
    apply: m.apply,
    discard: vi.fn(),
    erase: vi.fn(),
    markReviewed: vi.fn(),
    importDocuments: m.importDocuments,
    rotateFeed: m.rotateFeed,
    disableFeed: vi.fn(),
    saveSettings: m.saveSettings,
    setOrderUrl: m.setOrderUrl,
    clearOrderUrl: vi.fn(),
    downloadFeed: vi.fn(),
  },
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_error: unknown, fallback: string) => fallback }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (d: string) => d, formatNumber: (n: number) => String(n) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.canWrite }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.query }),
  useRouter: () => ({ replace: m.replace }),
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key,
    tm: (key: string) => [`${key}.0`, `${key}.1`],
    rt: (v: unknown) => String(v),
  }),
}))
vi.mock('@/components/exchange/ImportReportPanel.vue', () => ({ default: { props: ['report'], template: '<div data-test="report-panel" />' } }))

import ShoptetPage from '../ShoptetPage.vue'

function settings(over: Partial<ShoptetSettings> = {}): ShoptetSettings {
  return {
    documents_issuer: 'myucto', order_url_set: false, order_url_hint: null, auto_fetch: false,
    fetch_interval_minutes: 60, fetch_cursor: null, last_fetch_at: null, last_fetch_status: null,
    last_fetch_message: null, default_warehouse_id: null, confirm_orders: false, feed_enabled: false,
    feed_token_created_at: null, feed_warehouse_id: null, feed_include_price: true, feed_scope: 'eshop',
    feed_changed_at: null, feed_last_served_at: null, intervals: [15, 60], ...over,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  m.canWrite = true
  m.query = {}
  m.settings.mockResolvedValue({ settings: settings(), warehouses: [{ id: 1, code: 'HLAVNI', name: 'Hlavní', is_default: true }] })
  m.batches.mockResolvedValue([])
  m.reviewQueue.mockResolvedValue([])
})

describe('ShoptetPage', () => {
  it('shows orders tab with steps and disables URL download until a link is saved', async () => {
    const w = mount(ShoptetPage)
    await flushPromises()

    expect(w.text()).toContain('shoptet.orders.steps.0')
    const byUrl = w.get('[data-test="preview-url"]')
    expect(byUrl.attributes('disabled')).toBeDefined()
    expect(byUrl.attributes('title')).toBe('shoptet.orders.url_missing')
    expect(w.get('[data-test="preview-file"]').attributes('disabled')).toBeDefined()
  })

  it('uploads a file, shows preview and applies only after confirmation', async () => {
    m.previewUpload.mockResolvedValue({
      id: 7, kind: 'orders', source: 'upload', status: 'preview', file_name: 'orders.xml',
      summary: { create: 1, review: 1 },
      report: [{ code: '2026000101', action: 'create', customer: 'Jana', review_reasons: ['Zásilka do SK'], messages: [] }],
      created_at: '2026-09-01 10:00:00',
    })
    m.apply.mockResolvedValue({ id: 7, status: 'applied', summary: { created: 1 }, report: [{ code: '2026000101', status: 'created', order_id: 55 }] })
    const w = mount(ShoptetPage)
    await flushPromises()

    const input = w.get('[data-test="orders-file"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['<ORDERS/>'], 'orders.xml')] })
    await input.trigger('change')
    await w.get('[data-test="preview-file"]').trigger('click')
    await flushPromises()

    expect(m.previewUpload).toHaveBeenCalledOnce()
    expect(m.apply).not.toHaveBeenCalled()
    expect(w.get('[data-test="preview"]').text()).toContain('Zásilka do SK')

    await w.get('[data-test="apply"]').trigger('click')
    await flushPromises()
    expect(m.apply).toHaveBeenCalledWith(7)
    expect(w.get('[data-test="result"]').html()).toContain('/stock/sales-orders/55')
  })

  it('warns in documents tab when MyÚčto issues documents and hides the upload', async () => {
    m.query = { tab: 'documents' }
    const w = mount(ShoptetPage)
    await flushPromises()

    expect(w.find('[data-test="mode-warning"]').exists()).toBe(true)
    expect(w.find('[data-test="import-documents"]').exists()).toBe(false)
  })

  it('shows the fresh feed URL once after generating a token', async () => {
    m.query = { tab: 'feed' }
    m.rotateFeed.mockResolvedValue({
      token: 'a'.repeat(64), path: '/api/public/shoptet/feed/' + 'a'.repeat(64),
      settings: settings({ feed_enabled: true, feed_token_created_at: '2026-09-01 10:00:00' }), warehouses: [],
    })
    const w = mount(ShoptetPage)
    await flushPromises()
    expect(w.find('[data-test="fresh-url"]').exists()).toBe(false)

    await w.get('[data-test="rotate"]').trigger('click')
    await flushPromises()

    const box = w.get('[data-test="fresh-url"]')
    expect((box.get('input').element as HTMLInputElement).value).toContain('/api/public/shoptet/feed/' + 'a'.repeat(64))
  })

  it('settings keep one save button and send the new link before other settings', async () => {
    m.query = { tab: 'settings' }
    m.setOrderUrl.mockResolvedValue({ settings: settings({ order_url_set: true }), warehouses: [] })
    m.saveSettings.mockResolvedValue({ settings: settings({ order_url_set: true, documents_issuer: 'shoptet' }), warehouses: [] })
    const w = mount(ShoptetPage)
    await flushPromises()

    expect(w.findAll('[data-test="save-settings"]')).toHaveLength(1)
    await w.get('[data-test="url-input"]').setValue('https://obchod.example.test/export/orders.xml?hash=abc')
    await w.get('[data-test="issuer-shoptet"]').setValue(true)
    await w.get('[data-test="save-settings"]').trigger('click')
    await flushPromises()

    expect(m.setOrderUrl).toHaveBeenCalledWith('https://obchod.example.test/export/orders.xml?hash=abc')
    expect(m.saveSettings).toHaveBeenCalledWith(expect.objectContaining({ documents_issuer: 'shoptet' }))
    expect(m.setOrderUrl.mock.invocationCallOrder[0]).toBeLessThan(m.saveSettings.mock.invocationCallOrder[0])
  })

  it('read-only user sees no write actions', async () => {
    m.canWrite = false
    const w = mount(ShoptetPage)
    await flushPromises()

    expect(w.find('[data-test="preview-file"]').exists()).toBe(false)
    expect(w.find('[data-test="orders-file"]').exists()).toBe(false)
  })
})
