import type {
  PayrollRegistrationMissingItem,
  PayrollRegistrationProblemTarget,
} from '@/api/payroll'

/**
 * Chybějící údaje z odmítnuté přípravy registrace (`error.problems`).
 *
 * Server je posílá všechny najednou (kód `registration_data_incomplete`),
 * každý s adresou pole. Cokoli nečekaného se zahodí — seznam slouží k prokliku
 * a položka bez pole by vedla naprázdno.
 */
export function registrationMissingItems(exception: unknown): PayrollRegistrationMissingItem[] {
  const raw = (exception as { response?: { data?: { error?: { problems?: unknown } } } })
    ?.response?.data?.error?.problems
  if (!Array.isArray(raw)) return []

  const items: PayrollRegistrationMissingItem[] = []
  for (const entry of raw) {
    if (entry === null || typeof entry !== 'object') continue
    const item = entry as Record<string, unknown>
    if (typeof item.field !== 'string' || item.field === '') continue
    const target: PayrollRegistrationProblemTarget = item.target === 'employer_settings'
      ? 'employer_settings'
      : 'person'
    items.push({
      field: item.field,
      label: typeof item.label === 'string' ? item.label : item.field,
      message: typeof item.message === 'string' ? item.message : '',
      panel: typeof item.panel === 'string' && item.panel !== '' ? item.panel : null,
      target,
    })
  }

  return items
}

/**
 * Překladový klíč názvu údaje. Klíče jsou vypsané doslova, ne skládané —
 * generátor jmenných prostorů i18n čte jen literály.
 */
const LABEL_KEYS: Record<string, string> = {
  'identity.first_name': 'payroll.people.registration.missing.fields.first_name',
  'identity.last_name': 'payroll.people.registration.missing.fields.last_name',
  'identity.birth_surname': 'payroll.people.registration.missing.fields.birth_surname',
  'identity.birth_place': 'payroll.people.registration.missing.fields.birth_place',
  'identity.citizenship_country_code': 'payroll.people.registration.missing.fields.citizenship_country_code',
  'identity.sex': 'payroll.people.registration.missing.fields.sex',
  'identity.birth_date': 'payroll.people.registration.missing.fields.birth_date',
  'identity.birth_country_code': 'payroll.people.registration.missing.fields.birth_country_code',
  'identifier.value': 'payroll.people.registration.missing.fields.identifier',
  employer_variable_symbol: 'payroll.people.registration.missing.fields.employer_variable_symbol',
  cssz_workplace_code: 'payroll.people.registration.missing.fields.cssz_workplace_code',
}

/** Lidský název údaje v jazyce aplikace; neznámé pole si nechá název ze serveru. */
export function registrationItemLabel(
  item: Pick<PayrollRegistrationMissingItem, 'field' | 'label'>,
  t: (key: string) => string,
): string {
  const key = LABEL_KEYS[item.field]

  return key === undefined ? item.label : t(key)
}
