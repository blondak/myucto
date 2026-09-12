export type PayrollManualChapterRule = [RegExp, string]

export const PAYROLL_MANUAL_CHAPTERS: PayrollManualChapterRule[] = [
  [/^\/payroll\/absences(?:\/|$)/, '62_Absence_a_dovolena'],
  [/^\/payroll\/time(?:\/|$)/, '63_Dochazka_a_smeny'],
  [/^\/payroll\/travel(?:\/|$)/, '64_Cestovni_nahrady'],
  [/^\/payroll\/quick-inputs(?:\/|$)/, '65_Rychly_mesicni_vstup'],
  [/^\/payroll\/runs(?:\/|$)/, '66_Mzdove_behy'],
  [/^\/payroll\/posting-reconciliation(?:\/|$)/, '67_Shoda_uctovani_mezd'],
  [/^\/payroll\/payments(?:\/|$)/, '68_Platby_a_uhrady'],
  [/^\/payroll\/documents(?:\/|$)/, '69_Dokumenty_a_vystupy'],
  [/^\/payroll\/annual-settlement(?:\/|$)/, '70_Rocni_zuctovani'],
  [/^\/payroll\/submissions(?:\/|$)/, '71_Podani_a_hlaseni'],
  [/^\/payroll\/people(?:\/|$)/, '72_Zamestnanci'],
  [/^\/payroll\/deduction-agreements(?:\/|$)/, '73_Dohody_o_srazkach'],
  [/^\/payroll\/enforcement\/cooperation(?:\/|$)/, '74_Srazky_a_exekuce'],
  [/^\/payroll\/enforcement(?:\/|$)/, '74_Srazky_a_exekuce'],
  [/^\/payroll\/insolvency(?:\/|$)/, '74_Srazky_a_exekuce'],
  [/^\/payroll\/benefit-baskets(?:\/|$)/, '75_Kose_benefitu'],
  [/^\/payroll\/settings(?:\/|$)/, '76_Nastaveni_mezd'],
  [/^\/payroll\/components(?:\/|$)/, '77_Mzdove_slozky_a_vstupy'],
  [/^\/payroll\/rulesets(?:\/|$)/, '78_Legislativni_pravidla_mezd'],
  [/^\/payroll\/retention(?:\/|$)/, '79_Retencni_lhuty'],
  [/^\/payroll\/erasure(?:\/|$)/, '80_Vymaz_osobnich_udaju'],
  [/^\/payroll(?:\/|$)/, '61_Uplne_mzdy'],
]

export function payrollManualChapter(path: string): string | undefined {
  return PAYROLL_MANUAL_CHAPTERS.find(([pattern]) => pattern.test(path))?.[1]
}
