<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Dávky importu docházky, jejich řádky hodin a čtení vztahů pro párování osob.
 */
final class PayrollAttendanceImportRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Pracovní vztahy platné aspoň jeden den v období. Legacy projekce se
     * bere jen u osoby, která jiný vztah v období nemá — jinak by táž osoba
     * nabízela dva vztahy a párování by bylo zbytečně nejednoznačné.
     *
     * @return list<array{employment_id:int,employee_id:int,employee_name:string,code:string,relation_type:string,status:string}>
     */
    public function employmentsInPeriod(int $supplierId, string $periodStart, string $periodEnd): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.code,
                    employment.relation_type, employment.status, employment.is_legacy_projection,
                    ' . PayrollPeopleRepository::fullNameExpression('employee') . ' AS employee_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.status IN ("planned", "preregistered", "active", "suspended", "ended")
                AND COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1 THEN "1900-01-01" ELSE NULL END
                    ) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
              ORDER BY employee_name, employment.is_primary DESC, employment.id'
        );
        $stmt->execute([$supplierId, $periodEnd, $periodStart]);
        $rows = PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'attendance_employments');
        $hasRegular = [];
        foreach ($rows as $row) {
            if ((int) $row['is_legacy_projection'] === 0) {
                $hasRegular[(int) $row['employee_id']] = true;
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $employeeId = PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id');
            if ((int) $row['is_legacy_projection'] === 1 && isset($hasRegular[$employeeId])) {
                continue;
            }
            $result[] = [
                'employment_id' => PayrollTimeValue::int($row['id'] ?? null, 'employment_id'),
                'employee_id' => $employeeId,
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'code' => (string) ($row['code'] ?? ''),
                'relation_type' => (string) ($row['relation_type'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Všechna jména, pod kterými osoba v evidenci vystupuje: karta, historická
     * identita i strukturované jméno a příjmení.
     *
     * @param list<int> $employeeIds
     * @return list<array{employee_id:int,name:string}>
     */
    public function personNames(int $supplierId, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn (int $id): bool => $id > 0)));
        if ($employeeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employee.id AS employee_id, employee.full_name AS name
               FROM payroll_employees employee
              WHERE employee.supplier_id = ? AND employee.id IN ({$placeholders})
             UNION ALL
             SELECT identity.employee_id, identity.full_name
               FROM payroll_person_identity_history identity
              WHERE identity.supplier_id = ? AND identity.employee_id IN ({$placeholders})
             UNION ALL
             SELECT identity.employee_id, CONCAT_WS(' ', identity.first_name, identity.last_name)
               FROM payroll_person_identity_history identity
              WHERE identity.supplier_id = ? AND identity.employee_id IN ({$placeholders})
                AND identity.first_name IS NOT NULL AND identity.last_name IS NOT NULL"
        );
        $stmt->execute([
            $supplierId, ...$employeeIds,
            $supplierId, ...$employeeIds,
            $supplierId, ...$employeeIds,
        ]);
        $result = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'attendance_person_names') as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $result[] = [
                    'employee_id' => PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id'),
                    'name' => $name,
                ];
            }
        }

        return $result;
    }

    /** @return list<int> */
    public function employeeIdsByBirthNumberHash(int $supplierId, string $lookupHash): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee_id
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND identifier_type = "birth_number" AND value_hash = ?'
        );
        $stmt->execute([$supplierId, $lookupHash]);

        return array_map(
            static fn (mixed $id): int => PayrollTimeValue::int($id, 'employee_id'),
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /** @return array{id:int,row_version:int,status:string}|null */
    public function latestEmploymentOfEmployee(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, row_version, status
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = PayrollTimeValue::row($row, 'attendance_employment');

        return [
            'id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
            'row_version' => PayrollTimeValue::int($row['row_version'] ?? null, 'row_version'),
            'status' => (string) ($row['status'] ?? ''),
        ];
    }

    /** @return array<string,mixed>|null */
    public function findBatchByHash(int $supplierId, string $periodStart, string $contentSha256): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_attendance_imports
              WHERE supplier_id = ? AND period_start = ? AND content_sha256 = ?'
        );
        $stmt->execute([$supplierId, $periodStart, $contentSha256]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->batch($supplierId, PayrollTimeValue::int($id, 'import_id'));
    }

    /**
     * Založí dávku s řádky. Souběžný druhý požadavek se stejným obsahem
     * narazí na unikátní klíč a vrátí null — volající pak načte dávku, která
     * vyhrála.
     *
     * @param list<array{name:string,sha256:string}> $files
     * @param list<array<string,mixed>> $rules
     * @param list<array{employment_id:int,meaning:string,component_code:string,quantity_millihours:?int,amount_minor:?int,source_ref:string}> $rows
     */
    public function insertBatch(
        int $supplierId,
        string $periodStart,
        string $sourceSystem,
        string $contentSha256,
        array $files,
        array $rules,
        int $personCount,
        array $rows,
        ?int $userId,
    ): ?int {
        $pdo = $this->db->pdo();
        $metricCount = count(array_filter($rows, static fn (array $row): bool => $row['quantity_millihours'] !== null));
        try {
            $pdo->prepare(
                'INSERT INTO payroll_attendance_imports
                    (supplier_id, period_start, source_system, content_sha256, files_json,
                     rules_json, person_count, metric_count, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId,
                $periodStart,
                $sourceSystem,
                $contentSha256,
                json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($rules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $personCount,
                $metricCount,
                $userId,
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 || ($e->errorInfo[1] ?? null) === '1062') {
                return null;
            }
            throw $e;
        }
        $importId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO payroll_attendance_import_rows
                (supplier_id, import_id, employment_id, meaning, component_code,
                 quantity_millihours, amount_minor, source_ref)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $insert->execute([
                $supplierId,
                $importId,
                $row['employment_id'],
                $row['meaning'],
                $row['component_code'],
                $row['quantity_millihours'],
                $row['amount_minor'],
                mb_substr($row['source_ref'], 0, 191),
            ]);
        }

        return $importId;
    }

    public function attachInputImport(int $supplierId, int $importId, int $inputImportId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_attendance_imports
                SET input_import_id = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$inputImportId, $supplierId, $importId]);
    }

    /** @return list<array<string,mixed>> */
    public function batches(int $supplierId, string $sourceSystem, ?string $periodStart, int $limit = 100): array
    {
        $sql = 'SELECT batch.id FROM payroll_attendance_imports batch
                 WHERE batch.supplier_id = ? AND batch.source_system = ?'
            . ($periodStart === null ? '' : ' AND batch.period_start = ?')
            . ' ORDER BY batch.period_start DESC, batch.id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($periodStart === null ? [$supplierId, $sourceSystem] : [$supplierId, $sourceSystem, $periodStart]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $batch = $this->batch($supplierId, PayrollTimeValue::int($id, 'import_id'));
            if ($batch !== null) {
                $result[] = $batch;
            }
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function batch(int $supplierId, int $importId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT batch.id, batch.period_start, batch.source_system, batch.created_at,
                    batch.files_json, batch.person_count, batch.metric_count, batch.input_import_id,
                    creator.name AS created_by_name,
                    COALESCE(input_import.accepted_count, 0) AS input_count
               FROM payroll_attendance_imports batch
               LEFT JOIN users creator ON creator.id = batch.created_by
               LEFT JOIN payroll_input_imports input_import
                 ON input_import.supplier_id = batch.supplier_id
                AND input_import.id = batch.input_import_id
              WHERE batch.supplier_id = ? AND batch.id = ?'
        );
        $stmt->execute([$supplierId, $importId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = PayrollTimeValue::row($row, 'attendance_import');
        $files = json_decode((string) ($row['files_json'] ?? '[]'), true, 16, JSON_THROW_ON_ERROR);

        return [
            'id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
            'period' => substr((string) $row['period_start'], 0, 7),
            'source_system' => (string) $row['source_system'],
            'created_at' => (string) $row['created_at'],
            'created_by_name' => $row['created_by_name'] === null ? null : (string) $row['created_by_name'],
            'files' => is_array($files) ? array_values($files) : [],
            'person_count' => PayrollTimeValue::int($row['person_count'] ?? null, 'person_count'),
            'metric_count' => PayrollTimeValue::int($row['metric_count'] ?? null, 'metric_count'),
            'input_count' => PayrollTimeValue::int($row['input_count'] ?? null, 'input_count'),
            'input_import_id' => $row['input_import_id'] === null
                ? null
                : PayrollTimeValue::int($row['input_import_id'], 'input_import_id'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function batchRows(int $supplierId, int $importId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_item.employment_id, row_item.meaning, row_item.component_code,
                    row_item.quantity_millihours, row_item.amount_minor, row_item.source_ref,
                    employment.code AS employment_code,
                    ' . PayrollPeopleRepository::fullNameExpression('employee') . ' AS employee_name
               FROM payroll_attendance_import_rows row_item
               JOIN payroll_employments employment
                 ON employment.supplier_id = row_item.supplier_id
                AND employment.id = row_item.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE row_item.supplier_id = ? AND row_item.import_id = ?
              ORDER BY employee_name, row_item.employment_id, row_item.id'
        );
        $stmt->execute([$supplierId, $importId]);
        $result = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'attendance_import_rows') as $row) {
            $result[] = [
                'employment_id' => PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id'),
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'employment_code' => (string) ($row['employment_code'] ?? ''),
                'meaning' => (string) $row['meaning'],
                'component_code' => $row['component_code'] === '' ? null : (string) $row['component_code'],
                'quantity_millihours' => $row['quantity_millihours'] === null
                    ? null
                    : PayrollTimeValue::int($row['quantity_millihours'], 'quantity_millihours'),
                'amount_minor' => $row['amount_minor'] === null
                    ? null
                    : PayrollTimeValue::int($row['amount_minor'], 'amount_minor'),
                'source' => (string) $row['source_ref'],
            ];
        }

        return $result;
    }
}
