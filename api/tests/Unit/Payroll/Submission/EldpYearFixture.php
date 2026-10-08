<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Zmrazené revize roku (výchozí 2025) pro sestavovač evidenčního listu: osoba 11
 * s libovolnými vztahy, každý se svým druhem, kódem, trváním a měsíci účasti.
 * Syntetická data (firma 7).
 */
trait EldpYearFixture
{
    /**
     * @param list<array<string,mixed>> $employments
     * @return list<array<string,mixed>>
     */
    private function year(array $employments, int $year = 2025, int $lastMonth = 12): array
    {
        $revisions = [];
        for ($month = 1; $month <= $lastMonth; ++$month) {
            $revisions[] = $this->revision($month, $employments, $year);
        }

        return $revisions;
    }

    /**
     * @param list<array<string,mixed>> $employments
     * @return array<string,mixed>
     */
    private function revision(int $month, array $employments, int $year = 2025): array
    {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $entries = [];
        $relationships = [];
        $results = [];
        foreach ($employments as $employment) {
            if ($employment['start'] > $periodEnd
                || ($employment['end'] !== null && $employment['end'] < $periodStart)
            ) {
                continue;
            }
            $participatingMonths = $employment['participates'] ?? null;
            $participates = $participatingMonths === null || in_array($month, $participatingMonths, true);
            $base = $participates
                ? ($employment['participating_base'] ?? $employment['base'] ?? 1_000_000)
                : ($employment['base'] ?? 0);
            $entries[] = [
                'employment' => [
                    'id' => $employment['id'],
                    'employee_id' => 11,
                    'relation_type' => $employment['relation'],
                    'start_date' => $employment['start'],
                    'actual_start_date' => $employment['start'],
                    'end_date' => $employment['end'],
                ],
                'term' => [
                    'id' => 200 + $employment['id'],
                    'row_version' => 1,
                    'activity_code' => $employment['code'],
                    'jmhz_relationship_detail_code' => in_array($employment['relation'], ['dpc', 'dpp'], true) ? null : '1',
                ],
                'absences' => [],
                'inputs' => [],
            ];
            $results[] = ['employment_id' => $employment['id'], 'totals' => []];
            $relationships[] = [
                'relationship_id' => 'employment:' . $employment['id'],
                'kind' => match ($employment['relation']) {
                    'dpc' => 'dpc',
                    'dpp' => 'dpp',
                    default => 'employment',
                },
                'participation' => [
                    'relationship_id' => 'employment:' . $employment['id'],
                    'status' => $participates ? 'participates' : 'does_not_participate',
                    'reason_codes' => [],
                ],
                'assessment_base_minor_units' => $base,
                'capped_assessment_base_minor_units' => $participates ? $base : 0,
            ];
        }
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => $periodStart,
            'people' => [['employee' => ['id' => 11], 'employments' => $entries]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => 11,
                'employments' => $results,
                'statutory' => ['social_insurance' => ['status' => 'calculated', 'relationships' => $relationships]],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);

        return [
            'id' => 400 + $month,
            'run_id' => 500 + $month,
            'revision_no' => 1,
            'current_revision_no' => 1,
            'revision_kind' => 'regular',
            'status' => 'approved',
            'period_start' => $periodStart,
            'input_snapshot_json' => $inputJson,
            'input_snapshot_hash' => hash('sha256', $inputJson),
            'result_snapshot_json' => $resultJson,
            'result_snapshot_hash' => hash('sha256', $resultJson),
        ];
    }
}
