<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollStatutoryResultSetHash;

/** Rekonstrukce kořenové mzdové pečeti z normalizovaných fyzických řádků. */
final class CompanyBackupPayrollStatutoryResultSetAssembler
{
    private const REGISTRY_KEY = 'table:payroll_statutory_results';
    private const HASH_COLUMN = 'result_set_hash';

    /**
     * @param array<string,mixed> $header
     * @param list<array<string,mixed>> $personRows
     * @param list<array<string,mixed>> $relationshipRows
     */
    public static function assertSource(
        array $header,
        array $personRows,
        array $relationshipRows,
    ): void {
        $stored = $header[self::HASH_COLUMN] ?? null;
        if (!self::validHash($stored)
            || !hash_equals(
                $stored,
                self::calculate($header, $personRows, $relationshipRows),
            )
        ) {
            throw self::error('data_aggregate_hash_value_invalid');
        }
    }

    /**
     * @param array<string,mixed> $header
     * @param list<array<string,mixed>> $personRows
     * @param list<array<string,mixed>> $relationshipRows
     */
    public static function calculate(
        array $header,
        array $personRows,
        array $relationshipRows,
    ): string {
        $headerId = self::positiveInt($header, 'id');
        $supplierId = self::positiveInt($header, 'supplier_id');
        $revisionId = self::positiveInt($header, 'revision_id');
        $calculationKind = self::text($header, 'calculation_kind');

        /**
         * @var array<int,array{
         *   employee_id:int,
         *   input_snapshot:array<string,mixed>,
         *   relationships:list<array<string,mixed>>,
         *   result_snapshot:array<string,mixed>,
         *   result_status:string
         * }> $peopleById
         */
        $peopleById = [];
        $employeeIds = [];
        foreach ($personRows as $row) {
            self::assertRootScope(
                $row,
                $headerId,
                $supplierId,
                $revisionId,
                $calculationKind,
            );
            $personId = self::positiveInt($row, 'id');
            $employeeId = self::positiveInt($row, 'employee_id');
            if (isset($peopleById[$personId]) || isset($employeeIds[$employeeId])) {
                throw self::error('data_aggregate_hash_graph_invalid');
            }
            $employeeIds[$employeeId] = true;
            $peopleById[$personId] = [
                'employee_id' => $employeeId,
                'input_snapshot' => self::snapshot($row, 'input_snapshot_json'),
                'relationships' => [],
                'result_snapshot' => self::snapshot(
                    $row,
                    'result_snapshot_json',
                ),
                'result_status' => self::text($row, 'result_status'),
            ];
        }

        $relationshipIds = [];
        /** @var array<int,array<int,true>> $employmentsByPerson */
        $employmentsByPerson = [];
        foreach ($relationshipRows as $row) {
            self::assertRootScope(
                $row,
                $headerId,
                $supplierId,
                $revisionId,
                $calculationKind,
            );
            $relationshipId = self::positiveInt($row, 'id');
            $personId = self::positiveInt($row, 'person_result_id');
            $employeeId = self::positiveInt($row, 'employee_id');
            $employmentId = self::positiveInt($row, 'employment_id');
            $person = $peopleById[$personId] ?? null;
            if ($person === null
                || $person['employee_id'] !== $employeeId
                || isset($relationshipIds[$relationshipId])
                || isset($employmentsByPerson[$personId][$employmentId])
            ) {
                throw self::error('data_aggregate_hash_graph_invalid');
            }
            $relationshipIds[$relationshipId] = true;
            $employmentsByPerson[$personId][$employmentId] = true;
            $peopleById[$personId]['relationships'][] = [
                'employment_id' => $employmentId,
                'input_snapshot' => self::snapshot($row, 'input_snapshot_json'),
                'result_snapshot' => self::snapshot(
                    $row,
                    'result_snapshot_json',
                ),
                'result_status' => self::text($row, 'result_status'),
            ];
        }

        $people = array_values($peopleById);
        foreach ($people as &$person) {
            usort(
                $person['relationships'],
                static fn (array $left, array $right): int =>
                    $left['employment_id'] <=> $right['employment_id'],
            );
        }
        unset($person);
        usort(
            $people,
            static fn (array $left, array $right): int =>
                $left['employee_id'] <=> $right['employee_id'],
        );

        return PayrollStatutoryResultSetHash::calculate(
            $calculationKind,
            self::snapshot($header, 'input_snapshot_json'),
            $people,
            self::snapshot($header, 'result_snapshot_json'),
            self::text($header, 'result_status'),
            self::hash($header, 'ruleset_hash'),
            self::text($header, 'ruleset_id'),
            self::text($header, 'schema_version'),
        );
    }

    /** @param array<string,mixed> $row */
    private static function assertRootScope(
        array $row,
        int $headerId,
        int $supplierId,
        int $revisionId,
        string $calculationKind,
    ): void {
        if (self::positiveInt($row, 'statutory_result_id') !== $headerId
            || self::positiveInt($row, 'supplier_id') !== $supplierId
            || self::positiveInt($row, 'revision_id') !== $revisionId
            || self::text($row, 'calculation_kind') !== $calculationKind
        ) {
            throw self::error('data_aggregate_hash_graph_invalid');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function snapshot(array $row, string $column): array
    {
        $json = $row[$column] ?? null;
        if (!is_string($json)) {
            throw self::error('data_aggregate_hash_value_invalid');
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded)
                || !hash_equals(CanonicalJson::encode($decoded), $json)
            ) {
                throw new \UnexpectedValueException(
                    'Snapshot není kanonický objekt.',
                );
            }
            return $decoded;
        } catch (\Throwable $e) {
            throw self::error('data_aggregate_hash_value_invalid', $e);
        }
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (!is_int($value) || $value < 1) {
            throw self::error('data_aggregate_hash_graph_invalid');
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value) || $value === '') {
            throw self::error('data_aggregate_hash_value_invalid');
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!self::validHash($value)) {
            throw self::error('data_aggregate_hash_value_invalid');
        }
        return $value;
    }

    private static function validHash(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    private static function error(
        string $code,
        ?\Throwable $previous = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            $code,
            self::REGISTRY_KEY,
            self::HASH_COLUMN,
            $previous,
        );
    }
}
