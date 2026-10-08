/**
 * Kam vede náprava blokátoru registrace zaměstnance (REGZEC/PREZEC).
 *
 * Server píše v hlášce, co je špatně a kde to opravit; tahle mapa k tomu
 * přidává proklik. `employer_settings` = Nastavení mezd → Zaměstnavatel
 * a účtárny (variabilní symbol, kód správy), `a1_profile` = profil
 * registrace A1 na téže kartě.
 */
export type RegistrationRemediationKind = 'employer_settings' | 'a1_profile'

export const registrationRemediationCodes: Record<string, RegistrationRemediationKind> = {
  registration_employer_variable_symbol_missing: 'employer_settings',
  registration_employer_variable_symbol_invalid: 'employer_settings',
  registration_cssz_workplace_code_missing: 'employer_settings',
  registration_cssz_workplace_code_invalid: 'employer_settings',
  registration_regzec_a1_profile_missing: 'a1_profile',
  registration_regzec_a1_foreign_insurance_missing: 'a1_profile',
  registration_event_foreign_insurance_missing: 'a1_profile',
}

export function registrationRemediation(code: string | null | undefined): RegistrationRemediationKind | null {
  if (!code || !Object.hasOwn(registrationRemediationCodes, code)) return null
  return registrationRemediationCodes[code]!
}
