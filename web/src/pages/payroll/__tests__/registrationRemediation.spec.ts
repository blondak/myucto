import { describe, expect, it } from 'vitest'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import { registrationRemediation, registrationRemediationCodes } from '../registrationRemediation'

describe('registrationRemediation', () => {
  it('posílá neplatný variabilní symbol do nastavení zaměstnavatele', () => {
    expect(registrationRemediation('registration_employer_variable_symbol_invalid')).toBe('employer_settings')
    expect(registrationRemediation('registration_cssz_workplace_code_invalid')).toBe('employer_settings')
  })

  it('posílá chybějícího cizozemského nositele do profilu A1', () => {
    expect(registrationRemediation('registration_event_foreign_insurance_missing')).toBe('a1_profile')
    expect(registrationRemediation('registration_regzec_a1_profile_missing')).toBe('a1_profile')
  })

  it('neznámý kód nápravu nemá', () => {
    expect(registrationRemediation('registration_unknown')).toBeNull()
    expect(registrationRemediation('toString')).toBeNull()
    expect(registrationRemediation('')).toBeNull()
    expect(registrationRemediation(null)).toBeNull()
  })

  it('každý mapovaný kód má překlad v obou jazycích', () => {
    const codes = Object.keys(registrationRemediationCodes)
    for (const messages of [cs, en] as Array<{ payroll: { server_codes: Record<string, string> } }>) {
      for (const code of codes) {
        expect(messages.payroll.server_codes[code], code).toBeTruthy()
      }
    }
  })
})
