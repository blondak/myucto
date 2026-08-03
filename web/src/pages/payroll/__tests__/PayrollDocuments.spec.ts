import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listDocuments: vi.fn(),
  generateMonthlyBundle: vi.fn(),
  downloadDocument: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    listDocuments: m.listDocuments,
    generateMonthlyBundle: m.generateMonthlyBundle,
    downloadDocument: m.downloadDocument,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (permission: string) => permission === 'payroll.documents',
  }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import PayrollDocuments from '@/pages/payroll/PayrollDocuments.vue'

function deferred<T>(): {
  promise: Promise<T>
  resolve: (value: T) => void
} {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((resolver) => {
    resolve = resolver
  })
  return { promise, resolve }
}

describe('PayrollDocuments', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listDocuments.mockResolvedValue({
      period: '2026-07',
      revisions: [{
        run_id: 11,
        revision_id: 12,
        revision_no: 2,
        status: 'approved',
        office_id: null,
        office_name: 'Praha',
      }],
      items: [
        {
          id: 21,
          run_id: 11,
          revision_id: 12,
          revision_no: 2,
          employee_id: 31,
          employee_name: 'Testovací Zaměstnanec',
          office_name: 'Praha',
          document_kind: 'payslip',
          document_revision_no: 1,
          mime_type: 'application/pdf',
          suggested_filename: 'vyplatni-paska-2026-07-abcdef123456.pdf',
          file_sha256: 'a'.repeat(64),
          size_bytes: 4567,
          created_at: '2026-08-01 08:00:00',
        },
        {
          id: 22,
          run_id: 11,
          revision_id: 12,
          revision_no: 2,
          employee_id: null,
          employee_name: null,
          office_name: 'Praha',
          document_kind: 'monthly_bundle',
          document_revision_no: 2,
          mime_type: 'application/zip',
          suggested_filename: 'mzdovy-balicek-2026-07-bcdef1234567.zip',
          file_sha256: 'b'.repeat(64),
          size_bytes: 9876,
          created_at: '2026-08-01 08:05:00',
        },
      ],
    })
    m.generateMonthlyBundle.mockResolvedValue({
      id: 22,
      document_kind: 'monthly_bundle',
      revision_id: 12,
      file_sha256: 'b'.repeat(64),
      size_bytes: 9876,
    })
    m.downloadDocument.mockResolvedValue(undefined)
  })

  it('renders responsive document cards and a desktop table without exposing hashes as names', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    expect(m.listDocuments).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-test="documents-table"]').classes()).toContain('md:block')
    expect(wrapper.get('[data-test="documents-cards"]').classes()).toContain('md:hidden')
    expect(wrapper.text()).toContain('Testovací Zaměstnanec')
    expect(wrapper.text()).toContain('Praha')
    expect(wrapper.text()).toContain('payroll.documents.document_revision')
    expect(wrapper.text()).toContain('payroll.documents.company')
    expect(wrapper.text()).toContain('payroll.documents.kind.payslip')
    expect(wrapper.text()).not.toContain('a'.repeat(64))
  })

  it('creates an idempotent monthly bundle and downloads individual artifacts', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    await wrapper.get('[data-test="generate-bundle"]').trigger('click')
    expect(m.generateMonthlyBundle).toHaveBeenCalledWith(11, 12, expect.any(String))

    const buttons = wrapper.findAll('[data-test="download-document"]')
    await buttons[0].trigger('click')
    expect(m.downloadDocument).toHaveBeenCalledWith(
      expect.objectContaining({ id: 21, mime_type: 'application/pdf' }),
    )
  })

  it('ignores an older period response that arrives after a newer request', async () => {
    const first = deferred<Awaited<ReturnType<typeof m.listDocuments>>>()
    const second = deferred<Awaited<ReturnType<typeof m.listDocuments>>>()
    m.listDocuments
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise)

    const wrapper = mount(PayrollDocuments)
    const periodInput = wrapper.get('input[type="month"]')
    expect(m.listDocuments).toHaveBeenCalledTimes(1)
    await periodInput.setValue('2026-08')
    expect(m.listDocuments).toHaveBeenCalledTimes(2)

    second.resolve({
      period: '2026-08',
      revisions: [],
      items: [{
        id: 81,
        employee_name: 'Novější období',
        document_kind: 'payslip',
        size_bytes: 1,
        created_at: '2026-08-01 08:00:00',
      }],
    })
    await flushPromises()
    first.resolve({
      period: '2026-07',
      revisions: [],
      items: [{
        id: 71,
        employee_name: 'Starší období',
        document_kind: 'payslip',
        size_bytes: 1,
        created_at: '2026-07-01 08:00:00',
      }],
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Novější období')
    expect(wrapper.text()).not.toContain('Starší období')
  })
})
