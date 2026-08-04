import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PayrollFileDropzone from './PayrollFileDropzone.vue'

function mountDropzone(maxSizeBytes = 5_000_000) {
  return mount(PayrollFileDropzone, {
    props: {
      dropHint: 'Drop file',
      dropActiveHint: 'Release file',
      fileHint: 'CSV or XLSX',
      maxSizeBytes,
      dropzoneTestId: 'dropzone',
      inputTestId: 'input',
      selectedTestId: 'selected',
    },
  })
}

describe('PayrollFileDropzone', () => {
  it('emits an accepted file from drag and drop', async () => {
    const wrapper = mountDropzone()
    const file = new File(['employment_code\nSYN-HPP'], 'attendance.csv', {
      type: 'text/csv',
    })

    await wrapper.get('[data-testid="dropzone"]').trigger('drop', {
      dataTransfer: { files: [file] },
    })

    expect(wrapper.emitted('selected')).toEqual([[file]])
    expect(wrapper.emitted('rejected')).toBeUndefined()
  })

  it('opens the native picker from Enter and Space', async () => {
    const wrapper = mountDropzone()
    const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})

    await wrapper.get('[data-testid="dropzone"]').trigger('keydown', { key: 'Enter' })
    await wrapper.get('[data-testid="dropzone"]').trigger('keydown', { key: ' ' })

    expect(click).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-testid="dropzone"]').classes())
      .toContain('focus:ring-payroll-500/30')
    click.mockRestore()
  })

  it('rejects unsupported and oversized files before the page reads them', async () => {
    const wrapper = mountDropzone(4)
    const unsupported = new File(['data'], 'attendance.txt', { type: 'text/plain' })
    const oversized = new File(['12345'], 'attendance.csv', { type: 'text/csv' })

    await wrapper.get('[data-testid="dropzone"]').trigger('drop', {
      dataTransfer: { files: [unsupported] },
    })
    await wrapper.get('[data-testid="dropzone"]').trigger('drop', {
      dataTransfer: { files: [oversized] },
    })

    expect(wrapper.emitted('rejected')).toEqual([
      ['unsupported_file', unsupported],
      ['file_too_large', oversized],
    ])
    expect(wrapper.emitted('selected')).toBeUndefined()
  })

  it('renders a selected file and disables all interaction while busy', async () => {
    const wrapper = mount(PayrollFileDropzone, {
      props: {
        dropHint: 'Drop file',
        dropActiveHint: 'Release file',
        fileHint: 'CSV or XLSX',
        selectedFileName: 'attendance.csv',
        selectedText: 'Selected: attendance.csv',
        selectedTestId: 'selected',
        disabled: true,
      },
    })

    expect(wrapper.get('[data-testid="selected"]').text()).toBe('Selected: attendance.csv')
    expect(wrapper.attributes('aria-disabled')).toBe('true')
    expect(wrapper.attributes('tabindex')).toBe('-1')

    const file = new File(['data'], 'other.csv', { type: 'text/csv' })
    await wrapper.trigger('drop', { dataTransfer: { files: [file] } })
    expect(wrapper.emitted('selected')).toBeUndefined()
  })

  it('exposes a visible and accessible error state', () => {
    const wrapper = mount(PayrollFileDropzone, {
      props: {
        dropHint: 'Drop file',
        dropActiveHint: 'Release file',
        fileHint: 'CSV or XLSX',
        error: 'Unsupported file',
      },
    })

    expect(wrapper.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('[role="alert"]').text()).toBe('Unsupported file')
    expect(wrapper.classes()).toContain('border-danger-500')
  })
})
