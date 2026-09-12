import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import IntegrationCenter from '../IntegrationCenter.vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  connectors: vi.fn(),
  update: vi.fn(),
  diagnostics: vi.fn(),
  create: vi.fn(),
  createSample: vi.fn(),
  remove: vi.fn(),
  credentials: vi.fn(),
  rotateWebhookSecret: vi.fn(),
  reconcile: vi.fn(),
  retryOutbox: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: mocks.success, error: mocks.error }) }))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (value: string) => value }))
vi.mock('@/api/eshopIntegrations', () => ({
  eshopIntegrationsApi: {
    list: mocks.list,
    connectors: mocks.connectors,
    update: mocks.update,
    diagnostics: mocks.diagnostics,
    create: mocks.create,
    createSample: mocks.createSample,
    remove: mocks.remove,
    credentials: mocks.credentials,
    rotateWebhookSecret: mocks.rotateWebhookSecret,
    reconcile: mocks.reconcile,
    retryOutbox: mocks.retryOutbox,
  },
}))

const defaultOwnership = { 'product.name': 'local', 'product.price': 'local', 'order.status': 'remote' }
const catalog = {
  owners: ['local', 'remote', 'manual'],
  connectors: [
    {
      key: 'custom.webhook', available: true, i18n: 'custom_webhook', name: 'Vlastní napojení',
      capabilities: ['webhook_inbound', 'change_feed', 'reconcile'], notes: [], free_fields: true,
      credentials: [
        { key: 'endpoint_url', type: 'url', required: false, label: 'Adresa' },
        { key: 'endpoint_token', type: 'secret', required: false, label: 'Token' },
      ],
      mappings: [
        { type: 'warehouses', source: 'warehouses', label: 'Sklady' },
        { type: 'currencies', source: 'currencies', label: 'Měny' },
        { type: 'languages', source: 'languages', label: 'Jazyky' },
      ],
      fields: [
        { key: 'product.name', area: 'product', default_owner: 'local', label: 'Název' },
        { key: 'product.price', area: 'price', default_owner: 'local', label: 'Cena' },
        { key: 'order.status', area: 'order', default_owner: 'remote', label: 'Stav' },
      ],
    },
    {
      key: 'shoptet', available: false, i18n: 'shoptet', name: 'Shoptet', capabilities: [], notes: ['receiver'],
      free_fields: false, credentials: [], mappings: [], fields: [],
    },
  ],
  lookups: {
    warehouses: [{ value: 'HLAVNI', label: 'HLAVNI · Hlavní sklad', active: true }],
    currencies: [{ value: 'CZK', label: 'CZK · Koruna česká', active: true }, { value: 'EUR', label: 'EUR · Euro', active: true }],
    languages: [{ value: 'cs', label: 'cs · Čeština', active: true }],
    vat_rates: [],
  },
  defaults: {
    'custom.webhook': {
      status: 'draft',
      mappings: { warehouses: { HLAVNI: '<stockId skladu v Shoptetu>' }, currencies: { CZK: 'CZK' } },
      field_ownership: defaultOwnership,
      rate_limit_per_minute: 60,
      retention_days: 30,
    },
  },
}

function connection(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    connection_uuid: '00000000-0000-4000-8000-000000000007',
    supplier_id: 1,
    connector_key: 'custom.webhook',
    name: 'Syntetický e-shop',
    status: 'draft',
    mappings: {},
    field_ownership: {},
    credentials_configured: false,
    credentials_fields: [],
    webhook_configured: false,
    rate_limit_per_minute: 60,
    retention_days: 30,
    last_synced_at: null,
    last_error_code: null,
    last_error_at: null,
    created_at: '2026-09-10 12:00:00',
    updated_at: '2026-09-10 12:00:00',
    ...overrides,
  }
}

const emptyDiagnostics = { last_synced_at: null, last_error_code: null, last_error_at: null, inbox: {}, outbox: {}, errors: [], jobs: [] }

async function mountWith(existing: Record<string, unknown>[]) {
  mocks.list.mockResolvedValue(existing)
  const wrapper = mount(IntegrationCenter, {
    global: { stubs: { EmptyState: true, CatalogJobProgress: true, RouterLink: true } },
  })
  await flushPromises()
  return wrapper
}

async function open(wrapper: VueWrapper, id = 7) {
  await wrapper.get(`[data-test="connection-${id}"]`).trigger('click')
  await flushPromises()
}

describe('IntegrationCenter', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.connectors.mockResolvedValue(catalog)
    mocks.diagnostics.mockResolvedValue(emptyDiagnostics)
    mocks.update.mockImplementation(async (id: number, input: Record<string, unknown>) => connection({ id, ...input }))
    mocks.credentials.mockImplementation(async (id: number) => connection({ id }))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })
  afterEach(() => { vi.restoreAllMocks() })

  it('keeps a legacy connection with an unknown connector editable as JSON', async () => {
    const legacy = connection({ connector_key: 'synthetic.empty', name: 'Empty mapping', mappings: [], field_ownership: [] })
    const wrapper = await mountWith([legacy])
    const card = wrapper.get('[data-test="connection-7"]')
    await open(wrapper)

    expect(card.classes()).toContain('bg-surface-raised')
    expect(wrapper.find('[data-test="legacy-warning"]').exists()).toBe(true)
    expect((wrapper.get('[data-test="mappings"]').element as HTMLTextAreaElement).value).toBe('{}')
    expect((wrapper.get('[data-test="ownership"]').element as HTMLTextAreaElement).value).toBe('{}')

    await wrapper.get('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(mocks.update).toHaveBeenCalledWith(7, expect.objectContaining({
      connector_key: 'synthetic.empty', mappings: {}, field_ownership: {},
    }))
    expect(mocks.credentials).not.toHaveBeenCalled()
    expect(mocks.error).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('edits mappings and field ownership as tables and saves them as objects', async () => {
    const wrapper = await mountWith([connection({
      mappings: { warehouses: { HLAVNI: 'main-store' } },
      field_ownership: { 'product.price': 'remote' },
    })])
    await open(wrapper)

    expect((wrapper.get('[data-test="mapping-local-warehouses-0"]').element as HTMLSelectElement).value).toBe('HLAVNI')
    expect((wrapper.get('[data-test="mapping-remote-warehouses-0"]').element as HTMLInputElement).value).toBe('main-store')
    expect(wrapper.find('[data-test="mappings"]').exists()).toBe(false)

    await wrapper.get('[data-test="mapping-add-currencies"]').trigger('click')
    await wrapper.get('[data-test="mapping-local-currencies-0"]').setValue('EUR')
    await wrapper.get('[data-test="mapping-remote-currencies-0"]').setValue(' EUR ')
    await wrapper.get('[data-test="owner-product.name-manual"]').setValue(true)
    expect(wrapper.find('[data-test="dirty"]').exists()).toBe(true)

    await wrapper.get('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(mocks.update).toHaveBeenCalledWith(7, expect.objectContaining({
      mappings: { warehouses: { HLAVNI: 'main-store' }, currencies: { EUR: 'EUR' } },
      field_ownership: { 'product.name': 'manual', 'product.price': 'remote', 'order.status': 'remote' },
    }))
    expect(mocks.success).toHaveBeenCalledWith('common.saved')
    wrapper.unmount()
  })

  it('blocks saving an incomplete mapping row and explains why', async () => {
    const wrapper = await mountWith([connection()])
    await open(wrapper)
    await wrapper.get('[data-test="mapping-add-warehouses"]').trigger('click')
    await wrapper.get('[data-test="mapping-local-warehouses-0"]').setValue('HLAVNI')

    await wrapper.get('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(mocks.update).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="form-errors"]').text()).toContain('eshop.integrations.mapping_errors.remote_missing')
    wrapper.unmount()
  })

  it('shows stored credentials only as a state and sends only newly typed values', async () => {
    const wrapper = await mountWith([connection({ credentials_configured: true, credentials_fields: ['endpoint_token'] })])
    await open(wrapper)

    const token = wrapper.get('[data-test="credential-endpoint_token"]').element as HTMLInputElement
    expect(token.type).toBe('password')
    expect(token.value).toBe('')
    expect(wrapper.get('[data-test="credential-state-endpoint_token"]').text()).toBe('eshop.integrations.credential_stored')
    expect(wrapper.get('[data-test="credential-state-endpoint_url"]').text()).toBe('eshop.integrations.credential_not_stored')

    await wrapper.get('[data-test="credential-endpoint_url"]').setValue('https://shop.example.test/hook')
    await wrapper.get('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(mocks.credentials).toHaveBeenCalledWith(7, { endpoint_url: 'https://shop.example.test/hook' }, [])
    expect((wrapper.get('[data-test="credential-endpoint_url"]').element as HTMLInputElement).value).toBe('')

    await wrapper.get('[data-test="credential-clear-endpoint_token"]').setValue(true)
    await wrapper.get('[data-test="save"]').trigger('click')
    await flushPromises()
    expect(mocks.credentials).toHaveBeenLastCalledWith(7, {}, ['endpoint_token'])
    wrapper.unmount()
  })

  it('switches to advanced JSON mode and back only with a valid object', async () => {
    const wrapper = await mountWith([connection({ mappings: { warehouses: { HLAVNI: 'main-store' } } })])
    await open(wrapper)

    await wrapper.get('[data-test="advanced-toggle"]').setValue(true)
    const mappings = wrapper.get('[data-test="mappings"]')
    expect(JSON.parse((mappings.element as HTMLTextAreaElement).value)).toEqual({ warehouses: { HLAVNI: 'main-store' } })

    await mappings.setValue('{"payment_methods":{"card":"CARD"}}')
    await wrapper.get('[data-test="advanced-toggle"]').setValue(false)
    expect(wrapper.find('[data-test="mappings"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="form-errors"]').text()).toContain('eshop.integrations.mapping_errors.unknown_type')

    await wrapper.get('[data-test="mappings"]').setValue('{"languages":{"cs":"cz"}}')
    await wrapper.get('[data-test="advanced-toggle"]').setValue(false)
    expect(wrapper.find('[data-test="mappings"]').exists()).toBe(false)
    expect((wrapper.get('[data-test="mapping-remote-languages-0"]').element as HTMLInputElement).value).toBe('cz')
    expect(wrapper.find('[data-test="mapping-row-warehouses-0"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('prefills a new connection from the server defaults and offers the webhook only after saving', async () => {
    mocks.create.mockImplementation(async (input: Record<string, unknown>) => connection({ id: 9, ...input }))
    const wrapper = await mountWith([connection(), connection({ id: 8, name: 'Druhé' })])
    await wrapper.get('[data-test="new-connection"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="connector-shoptet"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="connector-custom.webhook"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-test="step-connector"]').attributes('data-state')).toBe('done')
    expect((wrapper.get('[data-test="connection-name"]').element as HTMLInputElement).value).toBe('eshop.integrations.connectors.custom_webhook.name')
    expect((wrapper.get('[data-test="mapping-local-warehouses-0"]').element as HTMLSelectElement).value).toBe('HLAVNI')
    expect((wrapper.get('[data-test="mapping-remote-currencies-0"]').element as HTMLInputElement).value).toBe('CZK')
    expect(wrapper.find('[data-test="mapping-placeholder-warehouses-0"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="step-mapping"]').attributes('data-state')).toBe('todo')
    expect(wrapper.find('[data-test="webhook-after-save"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="dirty"]').exists()).toBe(false)

    await wrapper.get('[data-test="mapping-remote-warehouses-0"]').setValue('1')
    mocks.list.mockResolvedValue([connection(), connection({ id: 8, name: 'Druhé' }), connection({ id: 9, name: 'Nové' })])
    await wrapper.get('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(mocks.create).toHaveBeenCalledWith(expect.objectContaining({
      connector_key: 'custom.webhook', status: 'draft', rate_limit_per_minute: 60, retention_days: 30,
      mappings: { warehouses: { HLAVNI: '1' }, currencies: { CZK: 'CZK' } },
      field_ownership: defaultOwnership,
    }))
    expect(wrapper.find('[data-test="developer-panel"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="webhook-url"]').text()).toContain('/api/public/integrations/webhooks/00000000-0000-4000-8000-000000000007')
    wrapper.unmount()
  })

  it('offers a sample connection on the empty page and opens its developer panel', async () => {
    const sample = connection({ id: 11, name: 'Ukázkové napojení (vzor Shoptet)', mappings: catalog.defaults['custom.webhook'].mappings })
    mocks.createSample.mockResolvedValue(sample)
    const wrapper = await mountWith([])

    expect(wrapper.find('[data-test="empty-state"]').exists()).toBe(true)
    expect(mocks.createSample).not.toHaveBeenCalled()
    mocks.list.mockResolvedValue([sample])
    await wrapper.get('[data-test="create-sample"]').trigger('click')
    await flushPromises()

    expect(mocks.createSample).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="empty-state"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="connection-11"]').classes()).toContain('bg-surface-raised')
    expect(wrapper.get('[data-test="secret-first"]').text()).toContain('eshop.integrations.secret_first_title')
    expect((wrapper.get('[data-test="example-details"]').element as HTMLDetailsElement).open).toBe(true)
    expect(wrapper.get('[data-test="example-body"]').text()).toContain('"event_type": "order.created"')
    expect(mocks.success).toHaveBeenCalledWith('eshop.integrations.sample_created')
    wrapper.unmount()
  })

  it('opens a single prepared connection automatically and can delete it', async () => {
    mocks.remove.mockResolvedValue({ deleted: true })
    const wrapper = await mountWith([connection({ name: 'Ukázkové napojení (vzor Shoptet)' })])

    expect(wrapper.get('[data-test="connection-7"]').classes()).toContain('bg-surface-raised')
    expect(wrapper.find('[data-test="developer-panel"]').exists()).toBe(true)

    mocks.list.mockResolvedValue([])
    await wrapper.get('[data-test="delete-connection"]').trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(mocks.remove).toHaveBeenCalledWith(7)
    expect(wrapper.find('[data-test="empty-state"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('keeps the one-time webhook secret visible after the connection list reloads', async () => {
    mocks.rotateWebhookSecret.mockResolvedValue({ connection_uuid: 'x', secret: 'synthetic-once-secret' })
    const wrapper = await mountWith([connection()])
    await open(wrapper)
    mocks.list.mockResolvedValue([connection({ webhook_configured: true })])

    await wrapper.get('[data-test="rotate-secret"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="webhook-secret"]').text()).toBe('synthetic-once-secret')
    expect(wrapper.get('[data-test="ready-secret"]').text()).toBe('eshop.integrations.ready_secret')
    wrapper.unmount()
  })
})
