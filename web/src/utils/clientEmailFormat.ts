// Náhled předmětu e-mailu a názvu přiloženého PDF podle klienta (#277) ve formuláři
// klienta. Pravidlo drží backend (api/src/Service/Mail/ClientEmailFormat.php) — tady
// je jen jeho zrcadlo pro živou ukázku; obě strany ověřují tytéž testovací případy.

export const CLIENT_EMAIL_FORMAT_TOKENS = [
  'VS', 'TYP', 'KLIENT', 'DODAVATEL', 'MM', 'YYYY', 'YY', 'DUZP_MM', 'DUZP_YYYY', 'DUZP_YY',
] as const

// Výchozí předmět a název přílohy, jak je skládá backend (InvoiceEmailVarsBuilder::
// buildSubject, InvoicePdfRenderer::cachePath) — v jazyce dokladů klienta, proto
// nejde o texty UI. Ve formuláři slouží jako placeholder prázdného pole.
export const CLIENT_EMAIL_DEFAULT_SUBJECT: Record<'cs' | 'en', string> = {
  cs: 'Faktura {VS} — {DODAVATEL}',
  en: 'Invoice {VS} — {DODAVATEL}',
}
export const CLIENT_EMAIL_DEFAULT_ATTACHMENT_NAME = 'Faktura-{VS}'
export const CLIENT_EMAIL_INVOICE_LABEL: Record<'cs' | 'en', string> = { cs: 'Faktura', en: 'Invoice' }

export interface ClientEmailFormatSample {
  varsymbol: string
  /** YYYY-MM-DD */
  issueDate: string
  /** YYYY-MM-DD; null = zálohová faktura bez DUZP, bere se datum vystavení */
  taxDate: string | null
  client: string
  supplier: string
  typeLabel: string
}

const TOKEN_RE = new RegExp(`\\{(${CLIENT_EMAIL_FORMAT_TOKENS.join('|')})\\}`, 'g')
// eslint-disable-next-line no-control-regex
const FILE_NAME_FORBIDDEN = /[\\/:*?"<>|\x00-\x1F\x7F]/g
// eslint-disable-next-line no-control-regex
const CONTROL = /[\x00-\x1F\x7F]+/g
const ATTACHMENT_NAME_MAX_LENGTH = 120

function values(sample: ClientEmailFormatSample): Record<string, string> {
  const [iy = '', im = ''] = sample.issueDate.split('-')
  const [ty = '', tm = ''] = (sample.taxDate || sample.issueDate).split('-')
  return {
    VS: sample.varsymbol,
    TYP: sample.typeLabel,
    KLIENT: sample.client,
    DODAVATEL: sample.supplier,
    MM: im,
    YYYY: iy,
    YY: iy.slice(2),
    DUZP_MM: tm,
    DUZP_YYYY: ty,
    DUZP_YY: ty.slice(2),
  }
}

function render(format: string, sample: ClientEmailFormatSample): string {
  const v = values(sample)
  return format.replace(TOKEN_RE, (_, token: string) => v[token] ?? '')
}

function normalize(format: string | null | undefined): string | null {
  const v = (format ?? '').trim()
  return v === '' ? null : v
}

/** Předmět e-mailu; null = nevyplněno nebo po dosazení prázdné (platí výchozí). */
export function clientEmailSubject(format: string | null | undefined, sample: ClientEmailFormatSample): string | null {
  const f = normalize(format)
  if (f === null) return null
  const subject = render(f, sample).replace(CONTROL, ' ').replace(/\s{2,}/g, ' ').trim()
  return subject === '' ? null : subject
}

/** Název přílohy včetně `.pdf`; null = nevyplněno nebo po dosazení prázdné. */
export function clientEmailAttachmentName(format: string | null | undefined, sample: ClientEmailFormatSample): string | null {
  const f = normalize(format)
  if (f === null) return null
  const name = render(f, sample)
    .replace(FILE_NAME_FORBIDDEN, '_')
    .replace(/\s{2,}/g, ' ')
    .replace(/^[\s.]+|[\s.]+$/g, '')
  if (name === '') return null
  return `${Array.from(name).slice(0, ATTACHMENT_NAME_MAX_LENGTH).join('')}.pdf`
}
