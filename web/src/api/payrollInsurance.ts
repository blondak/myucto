import { api } from './client'

/**
 * MZ-10-W07 / MZ-11-W07 — rozklad sociálního a zdravotního pojistného.
 *
 * Všechno tady je jen přenesený tvar NEMĚNNÉHO zákonného výsledku (tabulka
 * `payroll_statutory_results`) z toho běhu, který částku vydal. Frontend nesmí
 * z těchto dat nic dopočítávat: kdyby si sazbu nebo zaokrouhlení odvodil sám,
 * ukazoval by jiný výpočet než ten, kterým vznikla výsledná částka.
 */

/** Proč rozklad není k dispozici. Nikdy to není prázdno — vždycky je to věta. */
export type PayrollInsuranceUnavailableReason =
  | 'result_set_missing'
  | 'schema_unsupported'
  | 'person_missing'

/**
 * Odkud pochází sazba zdravotního pojistného.
 *
 * `persisted` = mezikrok uložil ten běh, který částku vydal.
 * `reconstructed` = mezikrok uložený není, ale je DOLOŽENÝ: sazba se vzala ze
 * sady pravidel zmrazené v té revizi (shoda otisku bajt na bajt) a po
 * zaokrouhlení dá tutéž uloženou částku. Musí být na obrazovce odlišené od
 * `persisted` — je to důkaz, ne uložený záznam.
 * `not_recorded` = mezikrok chybí a doložit ho nejde. Dopočet z dnešní sady
 * pravidel by popisoval jiný výpočet než ten, který dal uloženou částku.
 * `not_applicable` = pojistné nevzniklo (bez účasti, cizí režim). Krok chybí
 * právem; hlásit ho jako chybějící by byl planý poplach.
 */
export type PayrollInsuranceRateSource =
  | 'persisted'
  | 'reconstructed'
  | 'not_recorded'
  | 'not_applicable'

/**
 * Metoda rozdělení pojistného zaměstnavatele na osobu. `not_allocatable` není
 * metoda, ale přiznání, že rozdělit nejde — vždy s důvodem.
 */
export type PayrollEmployerAllocationMethod =
  | 'capped_assessment_base_share'
  | 'not_allocatable'

/** Proč rozdělení pojistného zaměstnavatele nevzniklo. Vždy věta, nikdy prázdno. */
export type PayrollEmployerAllocationBlocker =
  | 'amounts_missing'
  | 'assessment_base_missing'
  | 'company_total_mismatch'
  | 'discount_unattributable'
  | 'discount_exceeds_person_share'

export interface PayrollInsuranceRate {
  decimal: string
  numerator: number
  denominator: number
  scale: number
}

export interface PayrollInsuranceStep {
  label: string
  input_minor_units: number
  rate: PayrollInsuranceRate
  unrounded_numerator: number
  unrounded_denominator: number
  rounding_mode: string
  output_minor_units: number
}

export interface PayrollInsuranceUnavailable {
  available: false
  unavailable_reason: PayrollInsuranceUnavailableReason
}

export interface PayrollSocialRelationshipBreakdown {
  employment_id: number
  relationship_reference: string
  kind: string
  result_status: string
  participation_status: string
  participation_income_minor: number
  group_income_minor: number
  threshold_minor: number | null
  reason_codes: string[]
  assessment_base_minor: number
  capped_assessment_base_minor: number
  included_participation_components: string[]
  excluded_participation_components: string[]
  included_assessment_base_components: string[]
  excluded_assessment_base_components: string[]
  part_time_employer_discount: string
  employer_rate_category: string
  annual_maximum_allocation_order: number | null
}

export interface PayrollSocialBreakdown {
  available: true
  unavailable_reason: null
  status: string
  calculation_date: string
  ruleset_id: string
  ruleset_hash: string
  jurisdiction: string
  jurisdiction_evidence_reference: string | null
  working_pensioner_discount_evidence_reference: string | null
  assessment_base: {
    participating_minor: number
    capped_minor: number
    year_to_date_before_month_minor: number
    annual_maximum_reduction_minor: number
    annual_maximum_applied: boolean
  }
  employee: {
    contribution_step: PayrollInsuranceStep | null
    before_discount_minor: number | null
    discount_step: PayrollInsuranceStep | null
    working_pensioner_discount_minor: number | null
    contribution_minor: number | null
  }
  /**
   * `scope: 'company_month'` — pojistné zaměstnavatele není osobní veličina.
   * Počítá se ze součtu vyměřovacích základů všech zaměstnanců (§ 5a odst. 1
   * z. č. 589/1992 Sb.) a zaokrouhluje se až na tom součtu.
   *
   * `allocation` je proto ROZDĚLENÍ firemní částky, ne zákonná osobní částka —
   * a tak se to musí i vypsat. `is_statutory_personal_amount` je vždy `false`.
   */
  employer: {
    scope: 'company_month'
    allocation: PayrollEmployerSocialAllocation
    contribution_step: PayrollInsuranceStep | null
    assessment_base_minor: number | null
    contribution_before_discount_minor: number | null
    part_time_discount_base_minor: number | null
    part_time_discount_step: PayrollInsuranceStep | null
    part_time_discount_minor: number | null
    contribution_minor: number | null
  }
  relationships: PayrollSocialRelationshipBreakdown[]
  issues: string[]
}

export interface PayrollEmployerSocialAllocation {
  method: PayrollEmployerAllocationMethod
  not_allocatable_reason: PayrollEmployerAllocationBlocker | null
  residual_rule: string | null
  is_statutory_personal_amount: false
  people_count: number
  company_assessment_base_minor: number
  company_contribution_minor: number | null
  person_assessment_base_minor: number | null
  person_minor: number | null
}

/** Čím je rekonstruovaná sazba doložená. `null`, když se nic nerekonstruovalo. */
export interface PayrollInsuranceRateReconstruction {
  ruleset_id: string
  ruleset_version: string
  ruleset_hash: string
  parameter_key: string
  proof: string
  standard_reconstructed: boolean
  top_up_reconstructed: boolean
}

export interface PayrollHealthRelationshipBreakdown {
  employment_id: number
  relationship_reference: string
  kind: string
  result_status: string
  participation_status: string
  relationship_income_minor: number
  group_income_minor: number
  threshold_minor: number | null
  reason_codes: string[]
  assessment_base_minor: number
  participating_assessment_base_minor: number
  included_participation_components: string[]
  excluded_participation_components: string[]
  included_assessment_base_components: string[]
  excluded_assessment_base_components: string[]
}

export interface PayrollHealthInsurerLiability {
  insurer_code: string
  is_person_insurer: boolean
  person_count: number
  assessment_base_minor: number
  employee_minor: number
  employer_minor: number
  total_minor: number
}

export interface PayrollHealthMinimumReduction {
  from: string
  to: string
  reason: string
  evidence_reference: string | null
}

export interface PayrollHealthOtherEmployer {
  employer_reference: string
  assessment_base_minor_units: number
  employment_from: string
  employment_to: string | null
  evidence_reference: string
}

export interface PayrollHealthBreakdown {
  available: true
  unavailable_reason: null
  status: string
  calculation_date: string
  ruleset_id: string
  ruleset_hash: string
  jurisdiction: string
  jurisdiction_evidence_reference: string | null
  insurer: { status: string; code: string | null; evidence_reference: string | null }
  assessment_base: {
    this_employer_minor: number
    other_employers_minor: number
    combined_minor: number
  }
  minimum: {
    statutory_monthly_minor: number
    effective_minor: number
    employment_calendar_days: number
    excluded_calendar_days: number
    applicable_calendar_days: number
    top_up_applied: boolean
    top_up_base_minor: number | null
    top_up_responsibility: string
    /**
     * Odkud plátce doplatku pochází: `declared` = někdo ho prohlásil evidencí,
     * `statutory_default` = odvodil se ze zákona (§ 3 odst. 10 z. č. 592/1992
     * Sb.), protože evidence nebyla potřeba. Prázdné u revizí spočítaných dřív,
     * než klíč vznikl — tam se nezobrazuje nic.
     */
    top_up_responsibility_source: string
    top_up_employer_selection: string
    top_up_responsibility_evidence_reference: string | null
    selected_top_up_employer_evidence_reference: string | null
    reduction_evidence: PayrollHealthMinimumReduction[]
    ppz_counted: boolean
  }
  contribution: {
    /** Viz {@link PayrollInsuranceRateSource} — `reconstructed` se NESMÍ zobrazit jako uložené. */
    rate_source: PayrollInsuranceRateSource
    rate_reconstruction: PayrollInsuranceRateReconstruction | null
    standard_step: PayrollInsuranceStep | null
    standard_minor: number | null
    employee_standard_minor: number | null
    employer_standard_minor: number | null
    top_up_step: PayrollInsuranceStep | null
    employee_top_up_minor: number | null
    employer_top_up_minor: number | null
    employee_minor: number | null
    employer_minor: number | null
    total_minor: number | null
  }
  relationships: PayrollHealthRelationshipBreakdown[]
  other_employer_evidence: PayrollHealthOtherEmployer[]
  insurer_liabilities: PayrollHealthInsurerLiability[]
  issues: string[]
}

export interface PayrollInsuranceBreakdown {
  revision: {
    id: number
    run_id: number
    revision_no: number
    revision_kind: string
    status: string
  }
  person: { employee_id: number; full_name: string }
  social: PayrollSocialBreakdown | PayrollInsuranceUnavailable
  health: PayrollHealthBreakdown | PayrollInsuranceUnavailable
}

export const payrollInsuranceApi = {
  breakdown: (revisionId: number, employeeId: number) =>
    api.get<{ insurance_breakdown: PayrollInsuranceBreakdown }>(
      `/payroll/revisions/${revisionId}/insurance-breakdowns/${employeeId}`,
    ).then(response => response.data.insurance_breakdown),
}
