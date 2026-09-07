<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/** Kanonická pečeť úplného tříúrovňového zákonného výsledku. */
final class PayrollStatutoryResultSetHash
{
    /**
     * @param array<string,mixed> $inputSnapshot
     * @param list<array<string,mixed>> $people
     * @param array<string,mixed> $resultSnapshot
     */
    public static function calculate(
        string $calculationKind,
        array $inputSnapshot,
        array $people,
        array $resultSnapshot,
        string $resultStatus,
        string $rulesetHash,
        string $rulesetId,
        string $schemaVersion,
    ): string {
        return hash('sha256', self::canonical(
            $calculationKind,
            $inputSnapshot,
            $people,
            $resultSnapshot,
            $resultStatus,
            $rulesetHash,
            $rulesetId,
            $schemaVersion,
        ));
    }

    /**
     * @param array<string,mixed> $inputSnapshot
     * @param list<array<string,mixed>> $people
     * @param array<string,mixed> $resultSnapshot
     */
    public static function canonical(
        string $calculationKind,
        array $inputSnapshot,
        array $people,
        array $resultSnapshot,
        string $resultStatus,
        string $rulesetHash,
        string $rulesetId,
        string $schemaVersion,
    ): string {
        return CanonicalJson::encode([
            'calculation_kind' => $calculationKind,
            'input_snapshot' => $inputSnapshot,
            'people' => $people,
            'result_snapshot' => $resultSnapshot,
            'result_status' => $resultStatus,
            'ruleset_hash' => $rulesetHash,
            'ruleset_id' => $rulesetId,
            'schema_version' => $schemaVersion,
        ]);
    }
}
