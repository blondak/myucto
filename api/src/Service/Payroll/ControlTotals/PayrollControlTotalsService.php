<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\ControlTotals;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollControlTotalsService
{
    private readonly PayrollControlTotalsCalculator $calculator;

    public function __construct(
        private readonly Connection $db,
        ?PayrollControlTotalsCalculator $calculator = null,
    ) {
        $this->calculator = $calculator
            ?? new PayrollControlTotalsCalculator();
    }

    public function forApprovedRevision(
        int $supplierId,
        int $revisionId,
    ): PayrollControlTotals {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma i revize kontrolních součtů musí být kladné.',
            );
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.status, revision.approved_at,
                    revision.input_snapshot_json,
                    revision.input_snapshot_hash,
                    revision.result_snapshot_json,
                    revision.result_snapshot_hash
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.id = ?
              LIMIT 1',
        );
        $statement->execute([$supplierId, $revisionId]);
        $fetched = $statement->fetch(PDO::FETCH_ASSOC);
        if ($fetched === false) {
            throw new \DomainException(
                'Schválená mzdová revize pro firmu neexistuje.',
            );
        }
        $row = self::row($fetched);
        $status = self::string($row, 'status');
        if (!in_array($status, ['approved', 'superseded'], true)
            || self::nullableString($row, 'approved_at') === null
        ) {
            throw new \DomainException(
                'Mzdová revize ještě nebyla schválena.',
            );
        }

        [$input, $inputJson, $inputHash] = $this->snapshot(
            $row,
            'input_snapshot_json',
            'input_snapshot_hash',
            'vstupního',
        );
        [$result, $resultJson, $resultHash] = $this->snapshot(
            $row,
            'result_snapshot_json',
            'result_snapshot_hash',
            'výsledného',
        );
        if (($result['source_snapshot_hash'] ?? null) !== $inputHash) {
            throw new \DomainException(
                'Schválený výsledek neodpovídá zmrazenému vstupu.',
            );
        }
        if (!hash_equals($inputJson, CanonicalJson::encode($input))
            || !hash_equals($resultJson, CanonicalJson::encode($result))
        ) {
            throw new \DomainException(
                'Schválený snapshot není kanonicky serializovaný.',
            );
        }

        return $this->calculator->calculate(
            $supplierId,
            $revisionId,
            $input,
            $result,
            $resultHash,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array{array<string,mixed>,string,string}
     */
    private function snapshot(
        array $row,
        string $jsonField,
        string $hashField,
        string $label,
    ): array {
        $json = self::nullableString($row, $jsonField);
        $hash = self::nullableString($row, $hashField);
        if ($json === null || $hash === null
            || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
        ) {
            throw new \DomainException(
                "Schválená revize nemá úplný otisk {$label} snapshotu.",
            );
        }
        if (!hash_equals($hash, hash('sha256', $json))) {
            throw new \DomainException(
                "Otisk {$label} snapshotu schválené revize nesouhlasí.",
            );
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException(
                "Schválený {$label} snapshot není platný JSON.",
                previous: $exception,
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \DomainException(
                "Schválený {$label} snapshot musí být objekt.",
            );
        }
        $snapshot = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \DomainException(
                    "Schválený {$label} snapshot má neplatný klíč.",
                );
            }
            $snapshot[$key] = $value;
        }
        return [$snapshot, $json, $hash];
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatnou mzdovou revizi.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatný klíč mzdové revize.',
                );
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázové pole {$field} není text.",
            );
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(
        array $row,
        string $field,
    ): ?string {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázové pole {$field} není text.",
            );
        }
        return $value;
    }
}
