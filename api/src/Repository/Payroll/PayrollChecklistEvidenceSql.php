<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Doklad, který položku checklistu pracovního vztahu odškrtne sám.
 *
 * Jediné místo pro dvě čtecí cesty: kartu vztahu
 * ({@see PayrollEmploymentRepository}) a přehled termínů
 * ({@see PayrollDeadlineOverviewRepository}). Dřív měla každá vlastní kopii
 * a rozešly se — přehled hledal registraci ČSSZ pod referencí
 * `payroll_employment:{id}`, kterou podání nikdy nezakládá, takže položku
 * připomínal i po odeslání.
 *
 * U podání (ČSSZ i zdravotní pojišťovna) rozhoduje STAV podání, ne jeho
 * vznik. Povinnost se zakládá už při přípravě hlášení; kdyby stačila její
 * existence, checklist by hlásil „odhlášeno" ve chvíli, kdy odhláška leží
 * nepodaná v úložišti a lhůta běží. Splněno je až `submitted` (odesláno)
 * nebo `fulfilled` (přijato). Počítá se jen ostré prostředí — testovací
 * podání ČSSZ ani pojišťovně nic neoznamuje.
 *
 * Výraz předpokládá alias `item` pro `payroll_employment_checklist_items`
 * a vrací 1/0.
 */
final class PayrollChecklistEvidenceSql
{
    /** @var array<string,string> klíč položky → druh dokladu */
    public const EVIDENCE_KINDS = [
        'eldp_submission' => 'eldp_statement',
        'taxable_income_confirmation' => 'taxable_income_document',
        'social_jmhz_registration' => 'registration_obligation',
        'social_jmhz_deregistration' => 'deregistration_obligation',
        'health_insurance_registration' => 'health_start_obligation',
        'health_insurance_deregistration' => 'health_end_obligation',
        'enforcement_insolvency_review' => 'enforcement_termination_notice',
    ];

    private const DONE = "obligation.status IN ('submitted', 'fulfilled')
                           AND obligation.environment = 'production'";

    public static function evidencePresent(): string
    {
        $done = self::DONE;

        return "CASE item.item_key
          WHEN 'eldp_submission' THEN EXISTS (
            SELECT 1 FROM payroll_eldp_statements statement
             WHERE statement.supplier_id = item.supplier_id
               AND statement.employment_id = item.employment_id
          )
          WHEN 'taxable_income_confirmation' THEN EXISTS (
            SELECT 1
              FROM payroll_generated_documents document
              JOIN payroll_employments evidence_employment
                ON evidence_employment.supplier_id = item.supplier_id
               AND evidence_employment.id = item.employment_id
             WHERE document.supplier_id = item.supplier_id
               AND document.employee_id = evidence_employment.employee_id
               AND document.document_kind IN (
                     'taxable_income_advance_certificate',
                     'taxable_income_withholding_certificate'
                   )
          )
          WHEN 'social_jmhz_registration' THEN EXISTS (
            SELECT 1 FROM payroll_obligations obligation
             WHERE obligation.supplier_id = item.supplier_id
               AND obligation.source_event_type = 'payroll_employment_registration'
               AND obligation.source_event_reference
                     = CONCAT('payroll_employment_registration:', item.employment_id)
               AND {$done}
          )
          WHEN 'social_jmhz_deregistration' THEN EXISTS (
            SELECT 1
              FROM payroll_registration_event_snapshots event
              JOIN payroll_obligations obligation
                ON obligation.supplier_id = event.supplier_id
               AND obligation.environment = event.environment
               AND obligation.source_event_type = 'payroll_employment_registration'
               AND obligation.source_event_reference
                     = CONCAT('payroll_registration_event:', event.id)
             WHERE event.supplier_id = item.supplier_id
               AND event.employment_id = item.employment_id
               AND event.interaction_code IN ('termination', 'cancellation')
               AND {$done}
          )
          WHEN 'health_insurance_registration' THEN EXISTS (
            SELECT 1 FROM payroll_obligations obligation
             WHERE obligation.supplier_id = item.supplier_id
               AND obligation.source_event_type = 'payroll_health_notification'
               AND obligation.source_event_reference LIKE CONCAT(
                     'payroll_health_notification:', item.employment_id, ':employment_start:%'
                   )
               AND {$done}
          )
          WHEN 'health_insurance_deregistration' THEN EXISTS (
            SELECT 1 FROM payroll_obligations obligation
             WHERE obligation.supplier_id = item.supplier_id
               AND obligation.source_event_type = 'payroll_health_notification'
               AND obligation.source_event_reference LIKE CONCAT(
                     'payroll_health_notification:', item.employment_id, ':employment_end:%'
                   )
               AND {$done}
          )
          -- § 295 odst. 2 o. s. ř.: ke každému případu, který u osoby ke dni
          -- skončení běžel, je vystavené oznámení soudu / exekutorovi. Bez
          -- jediného případu se položka sama neodškrtne — insolvenci posoudí člověk.
          WHEN 'enforcement_insolvency_review' THEN (
            SELECT COUNT(*) > 0
                   AND SUM(NOT EXISTS (
                     SELECT 1
                       FROM payroll_enforcement_termination_notices notice
                      WHERE notice.supplier_id = enforcement_case.supplier_id
                        AND notice.case_id = enforcement_case.id
                        AND notice.employment_ended_on = evidence_employment.end_date
                   )) = 0
              FROM payroll_employments evidence_employment
              JOIN payroll_enforcement_cases enforcement_case
                ON enforcement_case.supplier_id = evidence_employment.supplier_id
               AND enforcement_case.employee_id = evidence_employment.employee_id
             WHERE evidence_employment.supplier_id = item.supplier_id
               AND evidence_employment.id = item.employment_id
               AND evidence_employment.end_date IS NOT NULL
               AND enforcement_case.effective_from <= evidence_employment.end_date
               AND enforcement_case.status IN (
                     'received', 'withhold_and_hold', 'remit',
                     'deferred_no_withholding', 'deferred_hold',
                     'ended_at_payer'
                   )
          )
          ELSE 0
        END";
    }
}
