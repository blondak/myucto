<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Evidence záměrů uplatňovat slevu na pojistném (§ 23e, e-podání OZUSPOJ).
 *
 * Repozitář vrací HOLÁ FAKTA. Jestli záměr slevu za měsíc zakládá, rozhoduje
 * `OzuspojDiscountEligibility` — pravidlo kontroly 291 se musí dát otestovat
 * bez databáze.
 */
final readonly class PayrollDiscountIntentRepository
{
    public function __construct(private Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(
        int $supplierId,
        string $environment,
        int $intentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT intent.*, employee.full_name
               FROM payroll_discount_intents intent
               JOIN payroll_employees employee
                 ON employee.supplier_id = intent.supplier_id
                AND employee.id = intent.employee_id
              WHERE intent.supplier_id = ?
                AND intent.environment = ?
                AND intent.id = ?'
        );
        $statement->execute([$supplierId, $environment, $intentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(
        int $supplierId,
        string $environment,
        ?int $employmentId = null,
    ): array {
        $sql =
            'SELECT intent.*, employee.full_name,
                    employment.code AS employment_code,
                    employment.start_date AS employment_start_date,
                    employment.end_date AS employment_end_date
               FROM payroll_discount_intents intent
               JOIN payroll_employees employee
                 ON employee.supplier_id = intent.supplier_id
                AND employee.id = intent.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = intent.supplier_id
                AND employment.id = intent.employment_id
              WHERE intent.supplier_id = ?
                AND intent.environment = ?';
        $params = [$supplierId, $environment];
        if ($employmentId !== null) {
            $sql .= ' AND intent.employment_id = ?';
            $params[] = $employmentId;
        }
        $sql .= ' ORDER BY intent.intent_from DESC, intent.id DESC';
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Fakta pracovního vztahu, ze kterých se oznámení sestaví.
     *
     * `social_part_time_discount_reason` se čte z podmínek účinných ke dni,
     * od kterého má záměr platit — důvod podle § 7a odst. 1 se v čase mění
     * (zaměstnanci je 21 let, dítěti 10) a evidence podle § 23d odst. 1 písm. b)
     * musí držet ten, který nárok zakládal.
     *
     * @return array<string,mixed>|null
     */
    public function findEmploymentContext(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id AS employment_id,
                    employment.employee_id,
                    employment.relation_type,
                    employment.start_date,
                    employment.actual_start_date,
                    employment.end_date,
                    employee.full_name,
                    terms.social_part_time_discount_reason,
                    terms.social_part_time_discount_evidence,
                    supplier.company_name AS employer_name,
                    supplier.ic AS employer_business_id,'
                    . PayrollEmployerIdentifierSql::SELECT . '
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN supplier
                 ON supplier.id = employment.supplier_id'
                . PayrollEmployerIdentifierSql::JOINS . '
          LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= ?
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              WHERE employment.supplier_id = ?
                AND employment.id = ?
              ORDER BY terms.effective_from DESC, terms.id DESC
              LIMIT 1'
        );
        $statement->execute([$onDate, $onDate, $supplierId, $employmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Záměry, které se překrývají s obdobím, ale patří JINÉMU vztahu téže
     * osoby. § 7a odst. 2 věta druhá dovoluje slevu jen z jednoho zaměstnání
     * u téhož zaměstnavatele, takže druhý souběžný záměr za tutéž osobu je
     * chyba, kterou musí aplikace zachytit dřív, než ji vrátí ČSSZ.
     *
     * @return list<array<string,mixed>>
     */
    public function overlappingForEmployee(
        int $supplierId,
        string $environment,
        int $employeeId,
        string $intentFrom,
        ?string $intentTo,
        ?int $excludeIntentId = null,
    ): array {
        $sql =
            'SELECT id, employment_id, intent_from, intent_to, status
               FROM payroll_discount_intents
              WHERE supplier_id = ?
                AND environment = ?
                AND employee_id = ?
                AND status IN ("draft", "submitted", "accepted", "ended")
                AND (intent_to IS NULL OR intent_to >= ?)
                AND (? IS NULL OR intent_from <= ?)';
        $params = [
            $supplierId,
            $environment,
            $employeeId,
            $intentFrom,
            $intentTo,
            $intentTo,
        ];
        if ($excludeIntentId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeIntentId;
        }
        $sql .= ' ORDER BY intent_from, id';
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Den podání přihlášky zaměstnance (PREZEC P1 nebo REGZEC A1) podle
     * vztahu, dolní mez oznámení záměru podle § 7a odst. 5 věty druhé
     * zákona č. 589/1992 Sb. („ne však dříve než dnem podání oznámení
     * o nástupu").
     *
     * Bere se nejdřívější doložené podání: vlastní základní registrace
     * s vyplněným `submitted_at` (zamítnutá, nahrazená ani včas zrušená se
     * nepočítá) a přihláška podaná předchozím programem, převzatá s dnem
     * podání. Vztah bez obojího ve výsledku chybí: nevíme, ne „nebylo".
     *
     * @param list<int> $employmentIds
     * @return array<int,string> `employment_id` => `YYYY-MM-DD`
     */
    public function registrationSubmittedOn(
        int $supplierId,
        string $environment,
        array $employmentIds,
    ): array {
        $ids = array_values(array_unique(array_filter(
            $employmentIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $references = array_map(
            static fn (int $id): string => 'payroll_employment:' . $id,
            $ids,
        );
        $statement = $this->db->pdo()->prepare(
            'WITH registrations AS (
                SELECT CAST(SUBSTRING(part.subject_reference, 20) AS UNSIGNED) AS employment_id,
                       DATE(submission.submitted_at) AS submitted_on
                  FROM payroll_submission_parts part
                  JOIN payroll_submissions submission
                    ON submission.supplier_id = part.supplier_id
                   AND submission.environment = part.environment
                   AND submission.id = part.submission_id
                 WHERE part.supplier_id = ?
                   AND part.environment = ?
                   AND part.source_entity_type = "payroll_employment"
                   AND part.agenda_code IN ("PREZEC26", "REGZEC25")
                   AND part.subject_reference IN (' . $placeholders . ')
                   AND submission.submitted_at IS NOT NULL
                   AND submission.status NOT IN ("rejected", "superseded", "cancelled_in_time")
                UNION ALL
                SELECT form.employment_id,
                       DATE(external.submitted_at) AS submitted_on
                  FROM payroll_external_jmhz_submissions external
                  JOIN payroll_external_jmhz_submission_forms form
                    ON form.supplier_id = external.supplier_id
                   AND form.submission_id = external.id
                 WHERE external.supplier_id = ?
                   AND external.environment = ?
                   AND external.document_kind = "registration"
                   AND external.status = "sent"
                   AND external.submitted_at IS NOT NULL
                   AND form.form_type = "A1"
                   AND form.employment_id IN (' . $placeholders . ')
            )
            SELECT employment_id, MIN(submitted_on) AS submitted_on
              FROM registrations
             GROUP BY employment_id'
        );
        $statement->execute([
            $supplierId,
            $environment,
            ...$references,
            $supplierId,
            $environment,
            ...$ids,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']] = (string) $row['submitted_on'];
        }

        return $result;
    }

    public function insert(
        int $supplierId,
        string $environment,
        int $employeeId,
        int $employmentId,
        string $discountReason,
        string $intentFrom,
        int $osszCode,
        ?string $employeeInformedOn,
        int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_discount_intents
                 (supplier_id, environment, employee_id, employment_id,
                  discount_reason, intent_from, ossz_code,
                  employee_informed_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $employeeId,
            $employmentId,
            $discountReason,
            $intentFrom,
            $osszCode,
            $employeeInformedOn,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Záměr přijatý ČSSZ od předchozího mzdového programu. Zakládá se rovnou
     * jako `accepted` s dnem doručení z protokolu. Podání z MyÚčta k němu
     * nevzniká, takže ani povinnost s lhůtou oznámení.
     */
    public function insertPredecessorAccepted(
        int $supplierId,
        string $environment,
        int $employeeId,
        int $employmentId,
        string $discountReason,
        string $intentFrom,
        int $osszCode,
        string $acceptedOn,
        string $predecessorSource,
        string $predecessorReference,
        int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_discount_intents
                 (supplier_id, environment, employee_id, employment_id,
                  discount_reason, intent_from, status, accepted_on, ossz_code,
                  predecessor_source, predecessor_reference, created_by)
             VALUES (?, ?, ?, ?, ?, ?, "accepted", ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $employeeId,
            $employmentId,
            $discountReason,
            $intentFrom,
            $acceptedOn,
            $osszCode,
            $predecessorSource,
            mb_substr($predecessorReference, 0, 190),
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findByScope(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $intentFrom,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT intent.*, employee.full_name
               FROM payroll_discount_intents intent
               JOIN payroll_employees employee
                 ON employee.supplier_id = intent.supplier_id
                AND employee.id = intent.employee_id
              WHERE intent.supplier_id = ?
                AND intent.environment = ?
                AND intent.employment_id = ?
                AND intent.intent_from = ?'
        );
        $statement->execute([$supplierId, $environment, $employmentId, $intentFrom]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Přijatý záměr vztahu, který platil ke dni `onDate`, protějšek oznámení
     * o skončení (typ 2), které den zahájení nenese.
     *
     * @return list<array<string,mixed>>
     */
    public function acceptedCovering(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $onDate,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_discount_intents
              WHERE supplier_id = ?
                AND environment = ?
                AND employment_id = ?
                AND status = "accepted"
                AND intent_from <= ?
                AND (intent_to IS NULL OR intent_to >= ?)
              ORDER BY intent_from, id'
        );
        $statement->execute([$supplierId, $environment, $employmentId, $onDate, $onDate]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Optimistický zápis stavu. Bez `row_version` v podmínce by dva souběžné
     * požadavky mohly z odmítnutého záměru udělat přijatý.
     *
     * @param array<string,mixed> $changes
     */
    public function update(
        int $supplierId,
        string $environment,
        int $intentId,
        int $rowVersion,
        array $changes,
    ): bool {
        $assignments = ['row_version = row_version + 1'];
        $params = [];
        foreach ($changes as $column => $value) {
            $assignments[] = $column . ' = ?';
            $params[] = $value;
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_discount_intents
                SET ' . implode(', ', $assignments) . '
              WHERE supplier_id = ?
                AND environment = ?
                AND id = ?
                AND row_version = ?'
        );
        $statement->execute([
            ...$params,
            $supplierId,
            $environment,
            $intentId,
            $rowVersion,
        ]);

        return $statement->rowCount() === 1;
    }
}
