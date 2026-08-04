<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

final class PayrollInputImportRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{employee_id:int,employment_id:int}|null */
    public function resolveEmployment(
        int $supplierId,
        int $employmentId,
        string $employmentCode,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee_id, id
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ? AND code = ?'
        );
        $stmt->execute([$supplierId, $employmentId, $employmentCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $resolved = PayrollTimeValue::row($row, 'resolved_employment');
        return [
            'employee_id' => PayrollTimeValue::int(
                $resolved['employee_id'] ?? null,
                'employee_id',
            ),
            'employment_id' => PayrollTimeValue::int($resolved['id'] ?? null, 'employment_id'),
        ];
    }

    /** @return array<string,mixed>|null */
    public function resolveComponent(
        int $supplierId,
        string $componentCode,
        string $periodStart,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC
              LIMIT 2'
        );
        $stmt->execute([$supplierId, $componentCode, $periodStart, $periodStart]);
        $rows = PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'resolved_components',
        );
        if (count($rows) !== 1) {
            return null;
        }
        $row = $rows[0];
        foreach (['id', 'supplier_id', 'annual_limit_minor', 'row_version'] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = PayrollTimeValue::int($row[$key], $key);
            }
        }
        $row['is_active'] = PayrollTimeValue::bool($row['is_active'] ?? null, 'is_active');
        return $row;
    }

    public function existingInputId(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $externalId,
    ): ?int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND source_kind = "import" AND external_id = ?
                AND status <> "cancelled"
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart, $externalId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : PayrollTimeValue::int($id, 'input_id');
    }

    /** @return array<string,mixed>|null */
    public function findByHash(int $supplierId, string $periodStart, string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_input_imports
              WHERE supplier_id = ? AND period_start = ? AND content_hash = ?'
        );
        $stmt->execute([$supplierId, $periodStart, $hash]);
        $id = $stmt->fetchColumn();
        return $id === false
            ? null
            : $this->detail($supplierId, PayrollTimeValue::int($id, 'import_id'));
    }

    /**
     * @param list<array{row_number:int,payload:array<string,mixed>,impact:array<string,mixed>}> $validRows
     * @param list<array{row_number:int,error_code:string,field_name:?string,error_message:string,payload:array<string,mixed>}> $errors
     * @param list<array{row_number:int,error_code:string,field_name:?string,error_message:string,payload:array<string,mixed>,input_id:?int}> $duplicates
     * @return array<string,mixed>
     */
    public function store(
        int $supplierId,
        string $periodStart,
        string $format,
        string $sourceName,
        string $contentHash,
        array $validRows,
        array $errors,
        array $duplicates,
        ?int $userId,
    ): array {
        $existing = $this->findByHash($supplierId, $periodStart, $contentHash);
        if ($existing !== null) {
            $existing['replayed'] = true;
            return $existing;
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO payroll_input_imports
                        (supplier_id, period_start, source_kind, source_name,
                         content_hash, status, row_count, created_by)
                     VALUES (?, ?, ?, ?, ?, "preview", ?, ?)'
                );
                $stmt->execute([
                    $supplierId,
                    $periodStart,
                    $format,
                    $sourceName,
                    $contentHash,
                    count($validRows) + count($errors) + count($duplicates),
                    $userId,
                ]);
                $importId = (int) $pdo->lastInsertId();
            } catch (PDOException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }
                $this->rollbackOwned($pdo, $ownsTransaction);
                $replayed = $this->findByHash($supplierId, $periodStart, $contentHash);
                if ($replayed === null) {
                    throw $e;
                }
                $replayed['replayed'] = true;
                return $replayed;
            }

            $accepted = 0;
            $duplicateCount = count($duplicates);
            foreach ($validRows as $row) {
                $payload = $row['payload'];
                try {
                    $input = $pdo->prepare(
                        'INSERT INTO payroll_inputs
                            (supplier_id, employee_id, employment_id, component_id,
                             period_start, source_period_start, amount_minor,
                             quantity_milliunits, source_kind, external_id,
                             import_id, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "import", ?, ?, ?)'
                    );
                    $input->execute([
                        $supplierId,
                        $payload['employee_id'],
                        $payload['employment_id'],
                        $payload['component_id'],
                        $periodStart,
                        $payload['source_period_start'],
                        $payload['amount_minor'],
                        $payload['quantity_milliunits'],
                        $payload['external_id'],
                        $importId,
                        $userId,
                    ]);
                    $inputId = (int) $pdo->lastInsertId();
                    ++$accepted;
                    $this->insertRow(
                        $supplierId,
                        $importId,
                        $row['row_number'],
                        $payload,
                        [],
                        'accepted',
                        $inputId,
                    );
                } catch (PDOException $e) {
                    if (!$this->isDuplicateKey($e)) {
                        throw $e;
                    }
                    ++$duplicateCount;
                    $inputId = $this->existingInputId(
                        $supplierId,
                        PayrollTimeValue::int(
                            $payload['employment_id'] ?? null,
                            'employment_id',
                        ),
                        $periodStart,
                        PayrollTimeValue::string(
                            $payload['external_id'] ?? null,
                            'external_id',
                        ),
                    );
                    $this->insertRow(
                        $supplierId,
                        $importId,
                        $row['row_number'],
                        $payload,
                        [[
                            'code' => 'duplicate_external_id',
                            'field' => 'external_id',
                            'message' => 'Externí vstup už v tomto vztahu a měsíci existuje.',
                        ]],
                        'duplicate',
                        $inputId,
                    );
                }
            }
            foreach ($errors as $row) {
                $this->insertRow(
                    $supplierId,
                    $importId,
                    $row['row_number'],
                    $row['payload'],
                    [[
                        'code' => $row['error_code'],
                        'field' => $row['field_name'],
                        'message' => $row['error_message'],
                    ]],
                    'error',
                    null,
                );
            }
            foreach ($duplicates as $row) {
                $this->insertRow(
                    $supplierId,
                    $importId,
                    $row['row_number'],
                    $row['payload'],
                    [[
                        'code' => $row['error_code'],
                        'field' => $row['field_name'],
                        'message' => $row['error_message'],
                    ]],
                    'duplicate',
                    $row['input_id'],
                );
            }

            $rejected = count($errors);
            $status = $accepted === 0
                ? 'rejected'
                : ($rejected > 0 || $duplicateCount > 0 ? 'partial' : 'accepted');
            $update = $pdo->prepare(
                'UPDATE payroll_input_imports
                    SET status = ?, accepted_count = ?, rejected_count = ?,
                        duplicate_count = ?, accepted_at = NOW(),
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ?'
            );
            $update->execute([
                $status,
                $accepted,
                $rejected,
                $duplicateCount,
                $supplierId,
                $importId,
            ]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        $stored = $this->detail($supplierId, $importId)
            ?? throw new \RuntimeException('Importní protokol nelze načíst.');
        $stored['replayed'] = false;
        return $stored;
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $importId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_input_imports
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $importId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            return null;
        }
        $result = $this->castImport(
            PayrollTimeValue::row($header, 'payroll_input_import'),
        );
        $rows = $this->db->pdo()->prepare(
            'SELECT id, source_row_number, external_id, status, input_id,
                    normalized_payload, errors_json, created_at
               FROM payroll_input_import_rows
              WHERE supplier_id = ? AND import_id = ?
              ORDER BY source_row_number, id'
        );
        $rows->execute([$supplierId, $importId]);
        $result['rows'] = array_map(
            function (array $row): array {
                $row = PayrollTimeValue::row($row, 'payroll_input_import_row');
                foreach (['id', 'source_row_number', 'input_id'] as $key) {
                    if (($row[$key] ?? null) !== null) {
                        $row[$key] = PayrollTimeValue::int($row[$key], $key);
                    }
                }
                $row['normalized_payload'] = $this->decodeJson(
                    $row['normalized_payload'] ?? null,
                    'normalized_payload',
                );
                $row['errors'] = $this->decodeJson(
                    $row['errors_json'] ?? null,
                    'errors_json',
                );
                unset($row['errors_json']);
                return $row;
            },
            PayrollTimeValue::rows(
                $rows->fetchAll(PDO::FETCH_ASSOC),
                'payroll_input_import_rows',
            ),
        );
        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<array{code:string,field:?string,message:string}> $errors
     */
    private function insertRow(
        int $supplierId,
        int $importId,
        int $rowNumber,
        array $payload,
        array $errors,
        string $status,
        ?int $inputId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_input_import_rows
                (supplier_id, import_id, input_id, source_row_number,
                 external_id, status, normalized_payload, errors_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $externalId = $payload['external_id'] ?? null;
        $stmt->execute([
            $supplierId,
            $importId,
            $inputId,
            $rowNumber,
            is_string($externalId) && $externalId !== '' ? $externalId : null,
            $status,
            CanonicalJson::encode($payload),
            $this->encodeList($errors),
        ]);
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function castImport(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'row_count',
            'accepted_count',
            'rejected_count',
            'duplicate_count',
            'row_version',
            'created_by',
        ] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = PayrollTimeValue::int($row[$key], $key);
            }
        }
        $hash = $row['content_hash'] ?? null;
        if (!is_string($hash)) {
            throw new \UnexpectedValueException('Import nemá obsahový hash.');
        }
        $row['content_hash'] = bin2hex($hash);
        return $row;
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value, string $field): array
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException("{$field} není JSON.");
        }
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException("{$field} není JSON objekt nebo pole.");
        }
        return $decoded;
    }

    /** @param list<array{code:string,field:?string,message:string}> $value */
    private function encodeList(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function rollbackOwned(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;
        return $driverCode === 1062 || $driverCode === '1062';
    }
}
