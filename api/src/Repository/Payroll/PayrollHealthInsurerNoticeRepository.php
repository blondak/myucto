<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Sdělení zdravotní pojišťovny zaměstnancem a písemné potvrzení zaměstnavatele
 * (§ 12 písm. b) zákona č. 48/1997 Sb.).
 *
 * Obě data jsou sloupce věty historie pojišťovny osoby
 * (`payroll_person_health_coverage_history`), protože popisují právě tu větu:
 * od kdy je osoba u pojišťovny a kdy o tom zaměstnavatele informovala. Čtou
 * a zapisují se mimo editor zákonné evidence a mimo snímek mzdového běhu, takže
 * nemění jeho otisk ani `row_version` věty (editor jinak hlásil falešný konflikt).
 */
final readonly class PayrollHealthInsurerNoticeRepository
{
    public function __construct(private Connection $db) {}

    /**
     * Věty historie pojišťovny osoby, od nejnovější. Věta bez kódu pojišťovny
     * (stav „nepoužije se") sdělení nevyvolává, proto se nevrací.
     *
     * @return list<array<string,mixed>>
     */
    public function listForEmployee(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, insurer_code, insurer_status, effective_from, effective_to,
                    employee_notified_on, employer_confirmed_on
               FROM payroll_person_health_coverage_history
              WHERE supplier_id = ?
                AND employee_id = ?
                AND insurer_code IS NOT NULL
              ORDER BY effective_from DESC, id DESC'
        );
        $statement->execute([$supplierId, $employeeId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $employeeId, int $coverageId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, insurer_code, insurer_status, effective_from, effective_to,
                    employee_notified_on, employer_confirmed_on
               FROM payroll_person_health_coverage_history
              WHERE supplier_id = ?
                AND employee_id = ?
                AND id = ?
                AND insurer_code IS NOT NULL'
        );
        $statement->execute([$supplierId, $employeeId, $coverageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function record(
        int $supplierId,
        int $employeeId,
        int $coverageId,
        ?string $employeeNotifiedOn,
        ?string $employerConfirmedOn,
        ?int $userId,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_person_health_coverage_history
                SET employee_notified_on = ?,
                    employer_confirmed_on = ?,
                    updated_by = ?
              WHERE supplier_id = ?
                AND employee_id = ?
                AND id = ?
                AND insurer_code IS NOT NULL'
        );
        $statement->execute([
            $employeeNotifiedOn,
            $employerConfirmedOn,
            $userId,
            $supplierId,
            $employeeId,
            $coverageId,
        ]);

        // MariaDB vrací 0 změněných řádků i při zápisu stejné hodnoty, takže
        // existence věty se ověřuje zvlášť.
        return $statement->rowCount() > 0
            || $this->find($supplierId, $employeeId, $coverageId) !== null;
    }

    /**
     * Údaje pro hlavičku potvrzení: zaměstnavatel a jméno osoby v den platnosti věty.
     *
     * @return array{employer_name:string,employer_ic:?string,employee_name:string}|null
     */
    public function confirmationParties(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(supplier.display_name, ""), supplier.company_name) AS employer_name,
                    supplier.ic AS employer_ic,
                    employee.full_name AS legacy_name,
                    (SELECT CONCAT_WS(" ", history.first_name, history.last_name)
                       FROM payroll_person_identity_history history
                      WHERE history.supplier_id = employee.supplier_id
                        AND history.employee_id = employee.id
                        AND history.effective_from <= ?
                        AND (history.effective_to IS NULL OR history.effective_to >= ?)
                      ORDER BY history.effective_from DESC, history.id DESC
                      LIMIT 1) AS identity_name
               FROM payroll_employees employee
               JOIN supplier ON supplier.id = employee.supplier_id
              WHERE employee.supplier_id = ?
                AND employee.id = ?'
        );
        $statement->execute([$onDate, $onDate, $supplierId, $employeeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $identity = is_string($row['identity_name'] ?? null) ? trim($row['identity_name']) : '';
        $name = $identity !== '' ? $identity : trim((string) ($row['legacy_name'] ?? ''));

        return [
            'employer_name' => trim((string) ($row['employer_name'] ?? '')),
            'employer_ic' => is_string($row['employer_ic'] ?? null) && trim($row['employer_ic']) !== ''
                ? trim($row['employer_ic'])
                : null,
            'employee_name' => $name,
        ];
    }
}
