import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { clientEmailAttachmentName, clientEmailSubject, type ClientEmailFormatSample } from '@/utils/clientEmailFormat'

// Sdílené případy s api/tests/Unit/Service/Mail/ClientEmailFormatTest.php — náhled
// ve formuláři klienta musí ukazovat přesně to, co backend pošle v e-mailu.

interface FormatCase {
  label: string
  format: string | null
  sample?: Partial<ClientEmailFormatSample>
  expected: string | null
}

const fixture = JSON.parse(readFileSync(
  resolve(__dirname, '../../../../api/tests/Fixtures/client-email-format/cases.json'),
  'utf8',
)) as { sample: ClientEmailFormatSample, subject: FormatCase[], attachment: FormatCase[] }

const sampleFor = (c: FormatCase): ClientEmailFormatSample => ({ ...fixture.sample, ...c.sample })

describe('předmět e-mailu podle klienta', () => {
  it.each(fixture.subject.map(c => [c.label, c]))('%s', (_label, c) => {
    expect(clientEmailSubject(c.format, sampleFor(c))).toBe(c.expected)
  })
})

describe('název přiloženého PDF podle klienta', () => {
  it.each(fixture.attachment.map(c => [c.label, c]))('%s', (_label, c) => {
    expect(clientEmailAttachmentName(c.format, sampleFor(c))).toBe(c.expected)
  })
})
