import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  timeMonth: vi.fn(),
  previewTimeImport: vi.fn(),
  importTime: vi.fn(),
  canWrite: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    timeMonth: m.timeMonth,
    previewTimeImport: m.previewTimeImport,
    importTime: m.importTime,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: m.toastError }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, values?: Record<string, unknown>) =>
      values?.name ? `${key}:${values.name}` : key,
  }),
}))

import TimeAttendance from '@/pages/payroll/TimeAttendance.vue'

describe('TimeAttendance import', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.timeMonth.mockResolvedValue({ items: [] })
    m.previewTimeImport.mockResolvedValue({
      supported: true,
      total_rows: 1,
      accepted_rows: 1,
      rejected_rows: 0,
      duplicate_rows: 0,
      rows: [],
      errors: [],
    })
  })

  it('loads attendance CSV through the shared drag-and-drop control', async () => {
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    const importButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.import.button')
    await importButton!.trigger('click')

    const file = new File(
      ['employment_code,starts_at,ends_at\nSYN-HPP,2026-08-03T08:00,2026-08-03T16:00'],
      'attendance.csv',
      { type: 'text/csv' },
    )
    Object.defineProperty(file, 'text', {
      value: vi.fn().mockResolvedValue('employment_code,starts_at,ends_at'),
    })
    await wrapper.get('[data-testid="payroll-time-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [file] },
    })

    await vi.waitFor(() => {
      expect(wrapper.get('[data-testid="payroll-time-import-selected"]').attributes('title'))
        .toBe('attendance.csv')
      const preview = wrapper.findAll('button')
        .find(button => button.text() === 'payroll.time.import.preview')
      expect(preview!.attributes('disabled')).toBeUndefined()
    })

    const preview = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.import.preview')
    await preview!.trigger('click')
    await flushPromises()
    expect(m.previewTimeImport).toHaveBeenCalledWith(expect.objectContaining({
      format: 'csv',
      original_name: 'attendance.csv',
    }))
  })

  it('shows a payroll-styled error and clears a previous selection after rejection', async () => {
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    const importButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.import.button')
    await importButton!.trigger('click')

    const unsupported = new File(['data'], 'attendance.txt', { type: 'text/plain' })
    await wrapper.get('[data-testid="payroll-time-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [unsupported] },
    })

    expect(wrapper.get('[role="alert"]').text())
      .toBe('payroll.time.import.unsupported_file')
    expect(wrapper.find('[data-testid="payroll-time-import-selected"]').exists()).toBe(false)
    expect(m.toastError).toHaveBeenCalledWith('payroll.time.import.unsupported_file')
  })
})
