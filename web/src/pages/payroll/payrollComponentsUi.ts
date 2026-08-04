import type {
  PayrollInputImportIssue,
  PayrollInputImportPreview,
  PayrollPerson,
} from '@/api/payroll'

export interface PayrollImportFingerprintSource {
  period: string
  format: 'csv' | 'xlsx'
  source_name: string
  content_base64: string
}

export interface PayrollEmploymentOption {
  employee_id: number
  employment_id: number
  full_name: string
  code: string
  relation_type: string
  status: string
}

export function localPayrollPeriod(date = new Date()): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

export function payrollImportFingerprint(source: PayrollImportFingerprintSource): string {
  return JSON.stringify([
    source.period,
    source.format,
    source.source_name,
    source.content_base64,
  ])
}

export function canApplyPayrollImport(
  preview: PayrollInputImportPreview | null,
  previewFingerprint: string | null,
  currentFingerprint: string,
): boolean {
  return preview !== null
    && preview.accepted_count > 0
    && previewFingerprint !== null
    && previewFingerprint === currentFingerprint
}

export function payrollImportIssues(
  preview: PayrollInputImportPreview | null,
): Array<PayrollInputImportIssue & { kind: 'error' | 'duplicate' }> {
  if (preview === null) return []
  return [
    ...preview.errors.map(issue => ({ ...issue, kind: 'error' as const })),
    ...preview.duplicates.map(issue => ({ ...issue, kind: 'duplicate' as const })),
  ].sort((left, right) => left.row_number - right.row_number)
}

export function payrollEmploymentOptions(people: PayrollPerson[]): PayrollEmploymentOption[] {
  return people
    .flatMap(person => person.employments.map(employment => ({
      employee_id: person.id,
      employment_id: employment.id,
      full_name: person.full_name,
      code: employment.code,
      relation_type: employment.relation_type,
      status: employment.status,
    })))
    .sort((left, right) =>
      left.full_name.localeCompare(right.full_name, 'cs')
      || left.code.localeCompare(right.code, 'cs'))
}

export function parsePayrollAmountToMinor(value: string): number | null {
  const normalized = value.trim().replace(/\s/g, '').replace(',', '.')
  const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(normalized)
  if (!match) return null
  const whole = Number(match[2])
  const fraction = Number((match[3] ?? '').padEnd(2, '0'))
  if (!Number.isSafeInteger(whole)) return null
  const minor = whole * 100 + fraction
  if (!Number.isSafeInteger(minor)) return null
  return match[1] === '-' ? -minor : minor
}

export function parsePayrollHoursToMilli(value: string): number | null {
  const normalized = value.trim().replace(',', '.')
  const match = /^(\d+)(?:\.(\d{1,3}))?$/.exec(normalized)
  if (!match) return null
  const whole = Number(match[1])
  const fraction = Number((match[2] ?? '').padEnd(3, '0'))
  if (!Number.isSafeInteger(whole)) return null
  const milli = whole * 1000 + fraction
  return Number.isSafeInteger(milli) ? milli : null
}

export function payrollMinorToInput(value: number | null): string {
  if (value === null) return ''
  const sign = value < 0 ? '-' : ''
  const absolute = Math.abs(value)
  return `${sign}${Math.floor(absolute / 100)},${String(absolute % 100).padStart(2, '0')}`
}

export function formatPayrollMinor(value: number | null, locale = 'cs-CZ'): string {
  if (value === null) return '—'
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: 'CZK',
    minimumFractionDigits: 2,
  }).format(value / 100)
}

export function monthStart(period: string): string {
  return /^\d{4}-\d{2}$/.test(period) ? `${period}-01` : ''
}
