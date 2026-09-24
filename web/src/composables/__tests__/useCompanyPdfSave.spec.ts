import { afterEach, describe, expect, it, vi } from 'vitest'
import { companyPickerId, pdfFileName, savePdfToCompanyFolder } from '../useCompanyPdfSave'

const w = window as unknown as { showSaveFilePicker?: unknown }

afterEach(() => {
  delete w.showSaveFilePicker
  vi.unstubAllGlobals()
})

describe('uložení PDF do složky firmy', () => {
  it('id dialogu je jedno pro každou firmu a projde pravidly prohlížeče', () => {
    expect(companyPickerId(5)).toBe('myucto-pdf-5')
    expect(companyPickerId('12')).not.toBe(companyPickerId('13'))
    expect(companyPickerId(null)).toBe('myucto-pdf-default')
    expect(companyPickerId(123456789012345678901234567890)).toMatch(/^[A-Za-z0-9_-]{1,32}$/)
  })

  it('název souboru z čísla dokladu', () => {
    expect(pdfFileName('2609008', 'faktura-1')).toBe('2609008.pdf')
    expect(pdfFileName('FV/2026/7', 'faktura-1')).toBe('FV-2026-7.pdf')
    expect(pdfFileName('', 'faktura-1')).toBe('faktura-1.pdf')
  })

  it('bez podpory v prohlížeči nic nestahuje a vrátí unsupported', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    await expect(savePdfToCompanyFolder('/api/x.pdf', 'a.pdf', 1)).resolves.toBe('unsupported')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('otevře dialog s id firmy, stáhne PDF a zapíše ho', async () => {
    const write = vi.fn().mockResolvedValue(undefined)
    const close = vi.fn().mockResolvedValue(undefined)
    const picker = vi.fn().mockResolvedValue({ createWritable: async () => ({ write, close }) })
    w.showSaveFilePicker = picker
    const blob = new Blob(['%PDF'], { type: 'application/pdf' })
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, blob: async () => blob }))

    await expect(savePdfToCompanyFolder('/api/x.pdf', '2609008.pdf', 2)).resolves.toBe('saved')

    expect(picker).toHaveBeenCalledWith(expect.objectContaining({ id: 'myucto-pdf-2', suggestedName: '2609008.pdf' }))
    expect(write).toHaveBeenCalledWith(blob)
    expect(close).toHaveBeenCalled()
  })

  it('zavřený dialog nic nestahuje', async () => {
    w.showSaveFilePicker = vi.fn().mockRejectedValue(Object.assign(new Error('abort'), { name: 'AbortError' }))
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    await expect(savePdfToCompanyFolder('/api/x.pdf', 'a.pdf', 2)).resolves.toBe('cancelled')
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
