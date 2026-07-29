import { describe, expect, it } from 'vitest'
import {
  decodeTextPreview,
  documentPreviewKind,
  formatXmlPreview,
  parseCsvPreview,
  plainTextPreview,
} from '../textPreview'

describe('documentPreviewKind', () => {
  it.each([
    ['xml', 'data.xml', 'application/xml', 'xml'],
    ['xml', 'invoice.isdoc', 'text/xml', 'xml'],
    ['other', 'notes.txt', 'text/plain', 'txt'],
    ['other', 'bank-statement.gpc', 'text/plain', 'txt'],
    ['other', 'payment-orders.abo', 'application/octet-stream', 'txt'],
    ['other', 'export.csv', 'text/plain', 'csv'],
    ['pdf', 'file.pdf', 'application/pdf', 'pdf'],
    ['image', 'scan.png', 'image/png', 'image'],
  ] as const)('%s %s → %s', (doc_type, original_name, mime_type, expected) => {
    expect(documentPreviewKind({ doc_type, original_name, mime_type })).toBe(expected)
  })

  it('does not treat ISDOCX ZIP content as plain XML', () => {
    expect(documentPreviewKind({
      doc_type: 'xml',
      original_name: 'invoice.isdocx',
      mime_type: 'application/zip',
    })).toBeNull()
  })

  it('does not preview arbitrary text/plain files as TXT', () => {
    expect(documentPreviewKind({
      doc_type: 'other',
      original_name: 'bank-statement.sta',
      mime_type: 'text/plain',
    })).toBeNull()
  })
})

describe('text decoding and formatting', () => {
  it('decodes UTF-8 BOM', () => {
    const bytes = new Uint8Array([0xef, 0xbb, 0xbf, ...new TextEncoder().encode('Příliš žluťoučký kůň')])
    expect(decodeTextPreview(bytes.buffer)).toBe('Příliš žluťoučký kůň')
  })

  it('formats XML into indented lines', () => {
    const preview = formatXmlPreview('<?xml version="1.0"?><root><item id="1">Text</item><empty/></root>')
    expect(preview.valid).toBe(true)
    expect(preview.lines).toEqual([
      '<?xml version="1.0"?>',
      '<root>',
      '  <item id="1">Text</item>',
      '  <empty/>',
      '</root>',
    ])
  })

  it('falls back to raw lines for invalid XML', () => {
    const preview = formatXmlPreview('<root><broken></root>')
    expect(preview.valid).toBe(false)
    expect(preview.lines).toEqual(['<root><broken></root>'])
  })

  it('normalizes text line endings', () => {
    expect(plainTextPreview('one\r\ntwo\rthree').lines).toEqual(['one', 'two', 'three'])
  })
})

describe('CSV preview', () => {
  it('detects Czech semicolon CSV and respects quoted delimiters', () => {
    const preview = parseCsvPreview('Název;Částka;Poznámka\r\nA;1200,50;"text; uvnitř"\r\n')
    expect(preview.delimiter).toBe(';')
    expect(preview.rows).toEqual([
      ['Název', 'Částka', 'Poznámka'],
      ['A', '1200,50', 'text; uvnitř'],
    ])
  })

  it('parses commas, escaped quotes and multiline cells', () => {
    const preview = parseCsvPreview('name,note\r\nA,"first\r\nsecond"\r\nB,"a ""quote"""')
    expect(preview.delimiter).toBe(',')
    expect(preview.rows).toEqual([
      ['name', 'note'],
      ['A', 'first\nsecond'],
      ['B', 'a "quote"'],
    ])
  })
})
