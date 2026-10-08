/*
 * Číselníky ČSSZ pro kódované prvky žádosti o dávku v NEMPRI25.
 *
 * Hodnoty musí souhlasit s `NempriCodebook` na serveru — hlídá to
 * `NempriCodebookContractTest`. Server kód mimo číselník odmítne, takže
 * rozjetý seznam by nabízel volbu, kterou nejde uložit.
 */
import type { PayrollSicknessBenefitKind } from '@/api/payrollSicknessCases'

/** `duvodOtcovske` — DV NEMPRI25 v1 (20260309). */
export const PATERNITY_REASONS = ['OTC', 'ZEM', 'PEC'] as const

/** `duvodPece` — CIS_DUVPREVZETI. */
export const MATERNITY_CARE_REASONS = ['DOH', 'ONE', 'ROZ', 'UMR'] as const

/** `kodRodVztah` u ošetřovného — CIS_RODVZTAH. */
export const FAMILY_RELATIONSHIPS = ['PL', 'MA', 'RP', 'SDO', 'SO', 'TCH', 'JIN'] as const

/** `druhDuchodu` u NEM, VPM a PPM — CIS_DRUHDUCH_NEM. */
export const PENSION_KINDS = ['A', 'B', 'C', 'I1', 'I3', 'N', 'S'] as const

/** `kodVztah` u dlouhodobého ošetřovného — CIS_VZTAH. */
export const CARE_RELATIONSHIPS = [
  '1', '2', '3', '4', '5', '6', '7', '8', '9', '10',
  '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
  '21', '22', '23', '24', '25', '26', '27', '28', '29',
] as const

export interface CodebookList {
  /** Podklíč v `payroll.sicknessCases.codebooks`. */
  labelGroup: 'familyRelationships' | 'careRelationships'
  codes: readonly string[]
}

/**
 * Seznam vztahů podle druhu dávky. Ošetřovné a dlouhodobé ošetřovné sdílejí
 * jedno pole, ale každé má jiný číselník: „1" je u DLO manžel, u ošetřovného nic.
 */
export function relationshipCodebook(kind: PayrollSicknessBenefitKind | null): CodebookList | null {
  if (kind === 'OSE') return { labelGroup: 'familyRelationships', codes: FAMILY_RELATIONSHIPS }
  if (kind === 'DLO') return { labelGroup: 'careRelationships', codes: CARE_RELATIONSHIPS }
  return null
}

/** Uložená hodnota, která v nabídce chybí (starý ručně zadaný kód). */
export function isOutsideCodebook(value: string | null | undefined, codes: readonly string[]): value is string {
  return typeof value === 'string' && value !== '' && !codes.includes(value)
}
