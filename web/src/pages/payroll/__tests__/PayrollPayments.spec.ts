import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  liabilities: vi.fn(),
  materialize: vi.fn(),
  payerOptions: vi.fn(),
  batches: vi.fn(),
  createBatch: vi.fn(),
  generateExport: vi.fn(),
  createDownloadGrant: vi.fn(),
  downloadExport: vi.fn(),
  reconciliation: vi.fn(),
  match: vi.fn(),
  reverse: vi.fn(),
  runs: vi.fn(),
  canWrite: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payrollPayments', () => ({
  payrollPaymentsApi: {
    liabilities: m.liabilities,
    materializeNetWages: m.materialize,
    payerOptions: m.payerOptions,
    batches: m.batches,
    createBatch: m.createBatch,
    generateExport: m.generateExport,
    createDownloadGrant: m.createDownloadGrant,
    downloadExport: m.downloadExport,
    reconciliation: m.reconciliation,
    match: m.match,
    reverse: m.reverse,
  },
}))
vi.mock('@/api/payroll', () => ({
  payrollApi: { runs: m.runs },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: m.canWrite,
  }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import PayrollPayments from '@/pages/payroll/PayrollPayments.vue'

describe('PayrollPayments', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockImplementation(
      (permission: string) => permission === 'payroll.payments',
    )
    m.liabilities.mockResolvedValue({
      period: '2026-08',
      items: [{
        id: 41,
        run_id: 11,
        revision_id: 12,
        revision_no: 1,
        employee_id: 31,
        employee_name: 'Syntetická osoba',
        liability_kind: 'net_wage',
        direction: 'outgoing',
        recipient_kind: 'bank',
        due_on: '2026-08-15',
        currency_code: 'CZK',
        amount_minor: 4_250_000,
        allocated_minor: 0,
        settled_minor: 0,
        state: 'open',
        created_at: '2026-08-03 08:00:00',
      }],
    })
    m.runs.mockResolvedValue([{
      id: 11,
      period_start: '2026-08-01',
      payment_date: '2026-08-15',
      status: 'approved',
      current_revision_no: 1,
      revision_id: 12,
      revision_no: 1,
      revision_status: 'approved',
      payment_materialization_supported: true,
      row_version: 5,
      result_snapshot: null,
      available_commands: [],
      validations: [],
    }])
    m.materialize.mockResolvedValue({
      liability_ids: [41],
      created_count: 0,
    })
    m.payerOptions.mockResolvedValue([{
      reference: 'currency:7',
      currency_id: 7,
      currency_code: 'CZK',
      bank_name: 'Syntetická banka',
      masked_account: '••••0005/0100',
      export_formats: ['abo'],
    }])
    m.batches.mockResolvedValue({
      period: '2026-08',
      items: [{
        id: 51,
        batch_reference: 'payroll-batch:synthetic',
        channel: 'bank',
        export_format: 'abo',
        planned_payment_date: '2026-08-15',
        currency_code: 'CZK',
        declared_total_minor: 4_250_000,
        declared_item_count: 1,
        settled_minor: 0,
        created_at: '2026-08-04 08:00:00',
        exports: [],
      }],
    })
    m.createBatch.mockResolvedValue({
      batch_id: 51,
      declared_item_count: 1,
    })
    m.generateExport.mockResolvedValue({
      export_id: 61,
      batch_id: 51,
      created: true,
      replayed: false,
    })
    m.createDownloadGrant.mockResolvedValue({
      grant_id: 71,
      export_id: 61,
      token: 'synthetic-one-use-token',
      expires_at: '2026-08-04 08:02:00',
    })
    m.downloadExport.mockResolvedValue(new Blob(['synthetic ABO']))
    m.reconciliation.mockResolvedValue({
      period: '2026-08',
      allocations: [{
        id: 81,
        item_id: 82,
        item_reference: 'payroll-item:synthetic',
        batch_id: 51,
        batch_reference: 'payroll-batch:synthetic',
        channel: 'bank',
        planned_payment_date: '2026-08-15',
        liability_id: 41,
        liability_kind: 'net_wage',
        direction: 'outgoing',
        currency_code: 'CZK',
        employee_name: 'Syntetická osoba',
        amount_minor: 4_250_000,
        settled_minor: 0,
        remaining_minor: 4_250_000,
      }],
      matches: [],
      bank_evidence: [{
        kind: 'bank',
        bank_statement_id: 91,
        bank_transaction_id: 92,
        cash_document_id: null,
        date: '2026-08-15',
        amount_minor: 4_250_000,
        currency_code: 'CZK',
        direction: 'outgoing',
        description: 'Syntetická výplata',
        reference: null,
        status: 'unmatched',
        available_match_minor: 4_250_000,
        available_reversal_minor: 4_250_000,
      }],
      cash_evidence: [],
    })
    m.match.mockResolvedValue({
      event: {
        id: 101,
        event_kind: 'matched',
        allocation_id: 81,
        source_match_id: null,
        evidence_kind: 'bank',
        bank_statement_id: 91,
        bank_transaction_id: 92,
        cash_document_id: null,
        actual_payment_date: '2026-08-15',
        amount_minor: 4_250_000,
        evidence_currency_code: 'CZK',
        evidence_hash: 'a'.repeat(64),
        idempotency_key: 'synthetic-match',
        reversible_minor: 4_250_000,
        created_at: '2026-08-15 12:00:00',
        employee_name: 'Syntetická osoba',
        liability_kind: 'net_wage',
      },
    })
    m.reverse.mockResolvedValue({
      event: {
        id: 102,
        event_kind: 'reversed',
        allocation_id: 81,
        source_match_id: 101,
        evidence_kind: 'bank',
        bank_statement_id: 93,
        bank_transaction_id: 94,
        cash_document_id: null,
        actual_payment_date: '2026-08-16',
        amount_minor: -4_250_000,
        evidence_currency_code: 'CZK',
        evidence_hash: 'b'.repeat(64),
        idempotency_key: 'synthetic-reversal',
        reversible_minor: 0,
        created_at: '2026-08-16 12:00:00',
        employee_name: 'Syntetická osoba',
        liability_kind: 'net_wage',
      },
    })
  })

  it('renders matching desktop and mobile liability views without sensitive references', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()

    expect(wrapper.get('[data-layout="desktop"]').text()).toContain('Syntetická osoba')
    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('Syntetická osoba')
    expect(wrapper.text()).toContain('payroll.payments.recipient.bank')
    expect(wrapper.text()).toContain('payroll.payments.state.open')
    expect(wrapper.text()).not.toContain('employee-account:')
    expect(wrapper.text()).not.toContain('bank_account_hash')
    expect(wrapper.findAll('nav button')).toHaveLength(3)
  })

  it('materializes only the approved current revision and safely replays it', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()

    const button = wrapper.findAll('header button')
      .find(item => item.text().includes('payroll.payments.materialize'))
    expect(button).toBeDefined()
    await button!.trigger('click')
    await flushPromises()

    expect(m.materialize).toHaveBeenCalledOnce()
    expect(m.materialize).toHaveBeenCalledWith(12)
    expect(m.success).toHaveBeenCalledWith(
      expect.stringContaining('payroll.payments.materialized_replay'),
    )
    expect(m.liabilities).toHaveBeenCalledTimes(2)
  })

  it('continues with supported revisions after one materialization fails', async () => {
    m.runs.mockResolvedValue([
      {
        id: 11,
        period_start: '2026-08-01',
        payment_date: '2026-08-15',
        status: 'approved',
        current_revision_no: 1,
        revision_id: 12,
        revision_no: 1,
        revision_status: 'approved',
        payment_materialization_supported: true,
        row_version: 5,
        result_snapshot: null,
        available_commands: [],
        validations: [],
      },
      {
        id: 21,
        period_start: '2026-08-01',
        payment_date: '2026-08-15',
        status: 'approved',
        current_revision_no: 1,
        revision_id: 22,
        revision_no: 1,
        revision_status: 'approved',
        payment_materialization_supported: true,
        row_version: 2,
        result_snapshot: null,
        available_commands: [],
        validations: [],
      },
      {
        id: 31,
        period_start: '2026-08-01',
        payment_date: '2026-08-15',
        status: 'approved',
        current_revision_no: 1,
        revision_id: 32,
        revision_no: 1,
        revision_status: 'approved',
        payment_materialization_supported: false,
        row_version: 1,
        result_snapshot: null,
        available_commands: [],
        validations: [],
      },
    ])
    m.materialize
      .mockRejectedValueOnce(new Error('synthetic blocked revision'))
      .mockResolvedValueOnce({ liability_ids: [42], created_count: 1 })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    const button = wrapper.findAll('header button')
      .find(item => item.text().includes('payroll.payments.materialize'))
    await button!.trigger('click')
    await flushPromises()

    expect(m.materialize).toHaveBeenCalledTimes(2)
    expect(m.materialize).toHaveBeenNthCalledWith(1, 12)
    expect(m.materialize).toHaveBeenNthCalledWith(2, 22)
    expect(m.materialize).not.toHaveBeenCalledWith(32)
    expect(m.success).toHaveBeenCalled()
    expect(m.error).toHaveBeenCalled()
  })

  it('reuses the pending idempotency key after an export timeout', async () => {
    m.generateExport
      .mockRejectedValueOnce(new Error('synthetic timeout'))
      .mockResolvedValueOnce({
        export_id: 61,
        batch_id: 51,
        created: false,
        replayed: true,
      })
    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[1].trigger('click')

    const firstButton = wrapper.findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.generate'))
    expect(firstButton).toBeDefined()
    await firstButton!.trigger('click')
    await flushPromises()

    const retryButton = wrapper.findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.generate'))
    await retryButton!.trigger('click')
    await flushPromises()

    expect(m.generateExport).toHaveBeenCalledTimes(2)
    expect(m.generateExport.mock.calls[0][0]).toBe(51)
    expect(m.generateExport.mock.calls[1][0]).toBe(51)
    expect(m.generateExport.mock.calls[1][1])
      .toBe(m.generateExport.mock.calls[0][1])
  })

  it('creates an ABO batch from a selected liability with the automatic payer option', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()

    const desktop = wrapper.get('[data-layout="desktop"]')
    const rowCheckbox = desktop.findAll('input[type="checkbox"]')[1]
    await rowCheckbox.setValue(true)
    await flushPromises()

    const createButton = wrapper.findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.create'))
    expect(createButton).toBeDefined()
    expect(createButton!.attributes('disabled')).toBeUndefined()
    await createButton!.trigger('click')
    await flushPromises()

    expect(m.createBatch).toHaveBeenCalledOnce()
    expect(m.createBatch).toHaveBeenCalledWith({
      export_format: 'abo',
      payer_reference: 'currency:7',
      items: [{
        liability_id: 41,
        amount_minor: 4_250_000,
      }],
    })
    expect(wrapper.find('[data-layout="batch-desktop"]').exists()).toBe(true)
    expect(wrapper.find('[data-layout="batch-mobile"]').exists()).toBe(true)
  })

  it('generates a batch export and downloads it through a one-use grant', async () => {
    const exportedFile = {
      id: 61,
      revision_no: 1,
      file_sha256: 'a'.repeat(64),
      size_bytes: 13,
      mime_type: 'text/plain',
      suggested_filename: 'mzdy-2026-08.abo',
      created_at: '2026-08-04 08:00:00',
    }
    m.batches
      .mockResolvedValueOnce({
        period: '2026-08',
        items: [{
          id: 51,
          batch_reference: 'payroll-batch:synthetic',
          channel: 'bank',
          export_format: 'abo',
          planned_payment_date: '2026-08-15',
          currency_code: 'CZK',
          declared_total_minor: 4_250_000,
          declared_item_count: 1,
          settled_minor: 0,
          created_at: '2026-08-04 08:00:00',
          exports: [exportedFile],
        }],
      })
      .mockResolvedValue({
        period: '2026-08',
        items: [{
          id: 51,
          batch_reference: 'payroll-batch:synthetic',
          channel: 'bank',
          export_format: 'abo',
          planned_payment_date: '2026-08-15',
          currency_code: 'CZK',
          declared_total_minor: 4_250_000,
          declared_item_count: 1,
          settled_minor: 0,
          created_at: '2026-08-04 08:00:00',
          exports: [exportedFile],
        }],
      })
    const createObjectUrl = vi.fn(() => 'blob:synthetic-payroll-export')
    const revokeObjectUrl = vi.fn()
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: createObjectUrl,
    })
    Object.defineProperty(URL, 'revokeObjectURL', {
      configurable: true,
      value: revokeObjectUrl,
    })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(() => undefined)

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[1].trigger('click')

    const desktop = wrapper.get('[data-layout="batch-desktop"]')
    const mobile = wrapper.get('[data-layout="batch-mobile"]')
    expect(desktop.text()).toContain('payroll.payments.batch.download')
    expect(mobile.text()).toContain('payroll.payments.batch.download')

    const generateButton = desktop.findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.generate'))
    await generateButton!.trigger('click')
    await flushPromises()

    expect(m.generateExport).toHaveBeenCalledOnce()
    expect(m.generateExport).toHaveBeenCalledWith(
      51,
      expect.stringMatching(/^payroll-export-51-/),
    )

    const downloadButton = wrapper.get('[data-layout="batch-desktop"]')
      .findAll('button')
      .find(button => button.text().includes('payroll.payments.batch.download'))
    await downloadButton!.trigger('click')
    await flushPromises()

    expect(m.createDownloadGrant).toHaveBeenCalledWith(61)
    expect(m.downloadExport).toHaveBeenCalledWith('synthetic-one-use-token')
    expect(createObjectUrl).toHaveBeenCalledWith(expect.any(Blob))
    expect(click).toHaveBeenCalledOnce()
    expect(revokeObjectUrl).toHaveBeenCalledWith(
      'blob:synthetic-payroll-export',
    )
    click.mockRestore()
  })

  it('hides generate and download actions from a read-only user on both layouts', async () => {
    m.canWrite.mockReturnValue(false)
    m.batches.mockResolvedValue({
      period: '2026-08',
      items: [{
        id: 51,
        batch_reference: 'payroll-batch:synthetic',
        channel: 'bank',
        export_format: 'abo',
        planned_payment_date: '2026-08-15',
        currency_code: 'CZK',
        declared_total_minor: 4_250_000,
        declared_item_count: 1,
        settled_minor: 0,
        created_at: '2026-08-04 08:00:00',
        exports: [{
          id: 61,
          revision_no: 1,
          file_sha256: 'a'.repeat(64),
          size_bytes: 13,
          mime_type: 'text/plain',
          suggested_filename: 'mzdy-2026-08.abo',
          created_at: '2026-08-04 08:00:00',
        }],
      }],
    })

    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[1].trigger('click')

    const desktop = wrapper.get('[data-layout="batch-desktop"]')
    const mobile = wrapper.get('[data-layout="batch-mobile"]')
    for (const layout of [desktop, mobile]) {
      expect(layout.text()).not.toContain('payroll.payments.batch.generate')
      expect(layout.text()).not.toContain('payroll.payments.batch.download')
    }
    expect(m.generateExport).not.toHaveBeenCalled()
    expect(m.createDownloadGrant).not.toHaveBeenCalled()
    expect(m.downloadExport).not.toHaveBeenCalled()
  })

  it('renders settlement matching with standard searchable inputs and hides writes from read-only users', async () => {
    const wrapper = mount(PayrollPayments)
    await flushPromises()
    await wrapper.findAll('nav button')[2].trigger('click')

    expect(wrapper.text()).toContain('payroll.payments.settlements.new_match')
    expect(wrapper.text()).toContain('payroll.payments.settlements.new_reversal')
    expect(wrapper.findAllComponents({ name: 'SearchableSelect' })).toHaveLength(4)

    m.canWrite.mockReturnValue(false)
    const readonlyWrapper = mount(PayrollPayments)
    await flushPromises()
    await readonlyWrapper.findAll('nav button')[2].trigger('click')

    expect(readonlyWrapper.text()).not.toContain('payroll.payments.settlements.new_match')
    expect(readonlyWrapper.text()).not.toContain('payroll.payments.settlements.new_reversal')
    expect(readonlyWrapper.text()).toContain('payroll.payments.settlements.history')
  })
})
