import type { DocItem } from '@/api/documents'

export const MAX_TEXT_PREVIEW_BYTES = 5 * 1024 * 1024
export const MAX_TEXT_PREVIEW_LINES = 5000
export const MAX_CSV_PREVIEW_ROWS = 500
export const MAX_CSV_PREVIEW_COLUMNS = 100

export type DocumentPreviewKind = 'pdf' | 'image' | 'xml' | 'txt' | 'csv' | null

type PreviewDocument = Pick<DocItem, 'doc_type' | 'original_name' | 'mime_type'>

export interface XmlPreview {
  lines: string[]
  valid: boolean
  truncated: boolean
}

export interface CsvPreview {
  rows: string[][]
  delimiter: string
  truncated: boolean
  columnsTruncated: boolean
}

function extension(name: string): string {
  const clean = name.split(/[?#]/, 1)[0] ?? ''
  const at = clean.lastIndexOf('.')
  return at >= 0 ? clean.slice(at + 1).toLowerCase() : ''
}

export function documentPreviewKind(doc: PreviewDocument): DocumentPreviewKind {
  if (doc.doc_type === 'pdf') return 'pdf'
  if (doc.doc_type === 'image') return 'image'

  const ext = extension(doc.original_name)
  const mime = doc.mime_type.toLowerCase().split(';', 1)[0]?.trim() ?? ''

  if (
    ext !== 'isdocx'
    && (doc.doc_type === 'xml' || ext === 'xml' || ext === 'isdoc' || mime === 'application/xml' || mime === 'text/xml')
  ) return 'xml'

  if (ext === 'csv' || mime === 'text/csv' || mime === 'application/csv') return 'csv'
  if (['txt', 'gpc', 'abo'].includes(ext)) return 'txt'
  return null
}

export function canPreviewDocument(doc: PreviewDocument): boolean {
  return documentPreviewKind(doc) !== null
}

export function decodeTextPreview(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer)
  if (bytes.length >= 3 && bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf) {
    return new TextDecoder('utf-8').decode(bytes.subarray(3))
  }
  if (bytes.length >= 2 && bytes[0] === 0xff && bytes[1] === 0xfe) {
    return new TextDecoder('utf-16le').decode(bytes.subarray(2))
  }
  if (bytes.length >= 2 && bytes[0] === 0xfe && bytes[1] === 0xff) {
    return new TextDecoder('utf-16be').decode(bytes.subarray(2))
  }
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  } catch {
    return new TextDecoder('windows-1250').decode(bytes)
  }
}

function previewLines(text: string): { lines: string[]; truncated: boolean } {
  const normalized = text.replace(/\r\n?/g, '\n')
  const lines = normalized.split('\n')
  return {
    lines: lines.slice(0, MAX_TEXT_PREVIEW_LINES),
    truncated: lines.length > MAX_TEXT_PREVIEW_LINES,
  }
}

export function plainTextPreview(text: string): { lines: string[]; truncated: boolean } {
  return previewLines(text)
}

export function formatXmlPreview(source: string): XmlPreview {
  const declaration = source.match(/^\s*(<\?xml\b[^?]*\?>)/i)?.[1] ?? null
  const parser = new DOMParser()
  const parsed = parser.parseFromString(source, 'application/xml')
  if (parsed.getElementsByTagName('parsererror').length > 0) {
    return { ...previewLines(source), valid: false }
  }

  const serializer = new XMLSerializer()
  const lines: string[] = []

  const append = (node: Node, depth: number): void => {
    const indent = '  '.repeat(depth)

    if (node.nodeType === Node.DOCUMENT_NODE) {
      Array.from(node.childNodes).forEach(child => append(child, depth))
      return
    }

    if (node.nodeType !== Node.ELEMENT_NODE) {
      const serialized = serializer.serializeToString(node).trim()
      if (serialized !== '') lines.push(indent + serialized)
      return
    }

    const element = node as Element
    const significant = Array.from(element.childNodes).filter(
      child => child.nodeType !== Node.TEXT_NODE || (child.textContent ?? '').trim() !== '',
    )

    if (
      significant.length === 0
      || significant.every(child => child.nodeType === Node.TEXT_NODE || child.nodeType === Node.CDATA_SECTION_NODE)
    ) {
      lines.push(indent + serializer.serializeToString(element))
      return
    }

    const shallow = serializer.serializeToString(element.cloneNode(false))
    const openTag = shallow.endsWith('/>') ? shallow.slice(0, -2) + '>' : shallow
    lines.push(indent + openTag)
    significant.forEach(child => append(child, depth + 1))
    lines.push(indent + `</${element.nodeName}>`)
  }

  append(parsed, 0)
  if (declaration !== null && lines[0] !== declaration) lines.unshift(declaration)
  return {
    lines: lines.slice(0, MAX_TEXT_PREVIEW_LINES),
    valid: true,
    truncated: lines.length > MAX_TEXT_PREVIEW_LINES,
  }
}

function parseDelimited(
  source: string,
  delimiter: string,
  maxRows: number,
  maxColumns: number,
): CsvPreview {
  const rows: string[][] = []
  let row: string[] = []
  let field = ''
  let quoted = false
  let truncated = false
  let columnsTruncated = false

  const pushField = () => {
    if (row.length < maxColumns) row.push(field)
    else columnsTruncated = true
    field = ''
  }
  const pushRow = () => {
    pushField()
    if (rows.length < maxRows) rows.push(row)
    else truncated = true
    row = []
  }

  for (let i = 0; i < source.length; i++) {
    const char = source[i]!
    if (quoted) {
      if (char === '"' && source[i + 1] === '"') {
        field += '"'
        i++
      } else if (char === '"') {
        quoted = false
      } else {
        field += char
      }
      continue
    }

    if (char === '"' && field === '') {
      quoted = true
    } else if (char === delimiter) {
      pushField()
    } else if (char === '\n') {
      pushRow()
      if (rows.length >= maxRows && i < source.length - 1) {
        truncated = true
        break
      }
    } else if (char !== '\r') {
      field += char
    }
  }

  if (!truncated && (field !== '' || row.length > 0)) pushRow()
  return { rows, delimiter, truncated, columnsTruncated }
}

function detectDelimiter(source: string): string {
  const sample = source.slice(0, 100_000)
  const candidates = [';', ',', '\t']
  let best = ';'
  let bestScore = -1

  for (const delimiter of candidates) {
    const parsed = parseDelimited(sample, delimiter, 25, 500)
    const widths = parsed.rows.map(row => row.length).filter(width => width > 1)
    if (widths.length === 0) continue

    const counts = new Map<number, number>()
    widths.forEach(width => counts.set(width, (counts.get(width) ?? 0) + 1))
    const [modeWidth, consistency] = [...counts.entries()].sort((a, b) => b[1] - a[1])[0]!
    const score = consistency * 100 + modeWidth
    if (score > bestScore) {
      best = delimiter
      bestScore = score
    }
  }
  return best
}

export function parseCsvPreview(source: string): CsvPreview {
  const normalized = source.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n')
  return parseDelimited(
    normalized,
    detectDelimiter(normalized),
    MAX_CSV_PREVIEW_ROWS,
    MAX_CSV_PREVIEW_COLUMNS,
  )
}
