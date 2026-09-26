<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kandidáti na dohlášení údajů (REGZEC A3) a jejich stav.
 *
 * Kandidát = pracovní vztah s přiděleným ID PPV v daném prostředí, který
 * u ČSSZ neprošel přihláškou REGZEC A1 z aplikace (byl tedy přihlášený dřív,
 * typicky přes ONZ). Vztahy skončené před 1. 1. 2026 se nenabízejí — údaje
 * se dohlašují za zaměstnance ze zaměstnání trvajícího v roce 2026.
 */
final class PayrollRegistrationCompletionRepository
{
    /** Zdroj dohlášení v `source_reference` události A3. */
    public const SOURCE_PREFIX = 'dohlaseni:';

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{
     *   employment_id:int,employee_id:int,employee_name:string,code:string,
     *   relation_type:string,status:string,start_date:?string,end_date:?string,
     *   profile_status:?string,profile_effective_on:?string,
     *   completion_event_id:?int,completion_effective_on:?string,
     *   completion_submission_id:?int,completion_submission_status:?string
     * }>
     */
    public function candidates(int $supplierId, string $environment): array
    {
        $statement = $this->db->pdo()->prepare(
            'WITH latest_profile AS (
                SELECT profile.employment_id, profile.status, profile.effective_on,
                       ROW_NUMBER() OVER (
                           PARTITION BY profile.employment_id
                           ORDER BY profile.row_version DESC, profile.id DESC
                       ) AS rn
                  FROM payroll_registration_a1_profiles profile
                 WHERE profile.supplier_id = ?
            ),
            completion AS (
                SELECT event.employment_id, event.id, event.effective_on,
                       submission.id AS submission_id,
                       submission.status AS submission_status,
                       ROW_NUMBER() OVER (
                           PARTITION BY event.employment_id
                           ORDER BY event.effective_on DESC, event.id DESC
                       ) AS rn
                  FROM payroll_registration_event_snapshots event
                  LEFT JOIN payroll_submission_parts part
                    ON part.supplier_id = event.supplier_id
                   AND part.environment = event.environment
                   AND part.source_entity_type = "payroll_registration_event"
                   AND part.source_entity_reference =
                       CONCAT("payroll_registration_event:", event.id)
                  LEFT JOIN payroll_submissions submission
                    ON submission.supplier_id = part.supplier_id
                   AND submission.environment = part.environment
                   AND submission.id = part.submission_id
                 WHERE event.supplier_id = ?
                   AND event.environment = ?
                   AND event.action_code = 3
                   AND event.source_reference LIKE ?
            )
            SELECT employment.id AS employment_id, employment.employee_id,
                   employee.full_name AS employee_name, employment.code,
                   employment.relation_type, employment.status,
                   COALESCE(employment.actual_start_date, employment.start_date)
                       AS start_date,
                   employment.end_date,
                   latest_profile.status AS profile_status,
                   latest_profile.effective_on AS profile_effective_on,
                   completion.id AS completion_event_id,
                   completion.effective_on AS completion_effective_on,
                   completion.submission_id AS completion_submission_id,
                   completion.submission_status AS completion_submission_status
              FROM payroll_employments employment
              JOIN payroll_employees employee
                ON employee.supplier_id = employment.supplier_id
               AND employee.id = employment.employee_id
              JOIN payroll_employment_external_ids external_id
                ON external_id.supplier_id = employment.supplier_id
               AND external_id.employment_id = employment.id
               AND external_id.environment = ?
               AND external_id.identifier_type = "id_ppv"
               AND external_id.valid_to IS NULL
              LEFT JOIN latest_profile
                ON latest_profile.employment_id = employment.id
               AND latest_profile.rn = 1
              LEFT JOIN completion
                ON completion.employment_id = employment.id
               AND completion.rn = 1
             WHERE employment.supplier_id = ?
               AND (employment.end_date IS NULL OR employment.end_date >= "2026-01-01")
               AND NOT EXISTS (
                   SELECT 1
                     FROM payroll_submission_parts a1_part
                     JOIN payroll_submissions a1_submission
                       ON a1_submission.supplier_id = a1_part.supplier_id
                      AND a1_submission.environment = a1_part.environment
                      AND a1_submission.id = a1_part.submission_id
                    WHERE a1_part.supplier_id = employment.supplier_id
                      AND a1_part.environment = ?
                      AND a1_part.agenda_code = "REGZEC25"
                      AND a1_part.source_entity_type = "payroll_employment"
                      AND a1_part.source_entity_reference =
                          CONCAT("payroll_employment_registration:", employment.id)
                      AND a1_submission.status NOT IN (
                          "rejected", "superseded", "cancelled_in_time"
                      )
               )
             ORDER BY employee.full_name, employment.id'
        );
        $statement->execute([
            $supplierId,
            $supplierId,
            $environment,
            self::SOURCE_PREFIX . '%',
            $environment,
            $supplierId,
            $environment,
        ]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'employment_id' => (int) $row['employment_id'],
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => (string) $row['employee_name'],
                'code' => (string) $row['code'],
                'relation_type' => (string) $row['relation_type'],
                'status' => (string) $row['status'],
                'start_date' => self::nullable($row['start_date']),
                'end_date' => self::nullable($row['end_date']),
                'profile_status' => self::nullable($row['profile_status']),
                'profile_effective_on' => self::nullable($row['profile_effective_on']),
                'completion_event_id' => $row['completion_event_id'] === null
                    ? null
                    : (int) $row['completion_event_id'],
                'completion_effective_on' => self::nullable($row['completion_effective_on']),
                'completion_submission_id' => $row['completion_submission_id'] === null
                    ? null
                    : (int) $row['completion_submission_id'],
                'completion_submission_status' => self::nullable(
                    $row['completion_submission_status'],
                ),
            ];
        }

        return $rows;
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
