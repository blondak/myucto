import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import PdfDropzone from './PdfDropzone.vue'

describe('PdfDropzone', () => {
  it('přijme ISDOC jen v editoru nového dokladu', async () => {
    const file = new File(['<Invoice/>'], 'synthetic.isdoc', { type: 'application/xml' })
    const attachmentOnly = mount(PdfDropzone)
    await attachmentOnly.trigger('drop', { dataTransfer: { files: [file] } })

    expect(attachmentOnly.emitted('file-dropped')).toBeUndefined()
    expect(attachmentOnly.emitted('error')?.[0]?.[0]).toBe('invalid_pdf')
    expect(attachmentOnly.get('input').attributes('accept')).not.toContain('.isdoc')

    const structured = mount(PdfDropzone, { props: { acceptStructured: true } })
    await structured.trigger('drop', { dataTransfer: { files: [file] } })

    expect(structured.emitted('file-dropped')).toEqual([[file]])
    expect(structured.emitted('error')).toBeUndefined()
    expect(structured.get('input').attributes('accept')).toContain('.isdocx')
  })

  it('ponechá PDF a fotografie dostupné i jako běžné přílohy', async () => {
    const wrapper = mount(PdfDropzone)
    const pdf = new File(['%PDF-1.7'], 'synthetic.pdf', { type: 'application/pdf' })
    const image = new File(['image'], 'synthetic.png', { type: 'image/png' })

    await wrapper.trigger('drop', { dataTransfer: { files: [pdf] } })
    await wrapper.trigger('drop', { dataTransfer: { files: [image] } })

    expect(wrapper.emitted('file-dropped')).toEqual([[pdf], [image]])
  })
})
