<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Report;

use MyInvoice\Repository\Payroll\PayrollDimensionCostReportRepository;

/**
 * Náklady na zaměstnance po dimenzi (středisko, firemní dimenze).
 *
 * Deník nese mzdový předpis za firmu, ne po zaměstnanci — analytika po
 * osobách by z deníku udělala mzdový list. Náklad osoby proto vzniká nad
 * cílovými alokacemi účetního můstku (`payroll_posting_allocations`): každá
 * nákladová alokace nese pracovní vztah v klíči a středisko i firemní dimenze
 * ve vlastních sloupcích. Součet reportu tak sedí na nákladové řádky deníku.
 *
 * Alokace bez pracovního vztahu (jedna firemní dvojice 524 u revizí, kde se
 * pojistné na vztahy nerozpadlo) jdou do řádku „nerozděleno", aby se
 * nepotratily.
 */
final class PayrollDimensionCostReportService
{
    public function __construct(
        private readonly PayrollDimensionCostReportRepository $repository,
    ) {}

    /**
     * @return array{
     *   year:int,
     *   rows:list<array{
     *     employment_id:?int,
     *     employee_id:?int,
     *     employee_name:?string,
     *     employment_code:?string,
     *     cost_center:?string,
     *     dimensions:list<array{type_id:int,type_name:string,value_id:int,code:string,name:string}>,
     *     wages_minor:int,
     *     insurance_minor:int,
     *     other_minor:int,
     *     total_minor:int
     *   }>,
     *   by_dimension:list<array{type_id:?int,type_name:?string,value_id:?int,code:string,name:string,total_minor:int}>,
     *   totals:array{wages_minor:int,insurance_minor:int,other_minor:int,total_minor:int}
     * }
     */
    public function report(int $supplierId, int $year): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být zvolená.');
        }
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Mzdový rok musí být v rozsahu 2000 až 2200.');
        }

        $groups = [];
        $employmentIds = [];
        $valueIds = [];
        foreach ($this->repository->costAllocations($supplierId, $year) as $allocation) {
            $employmentId = preg_match('/(?:^|:)employment:(\d+)(?::|$)/', $allocation['allocation_key'], $match) === 1
                ? (int) $match[1]
                : null;
            $dimensions = self::dimensions($allocation['dimensions']);
            $key = ($employmentId ?? 0) . '|' . ($allocation['cost_center'] ?? '') . '|'
                . json_encode($dimensions, JSON_THROW_ON_ERROR);
            $groups[$key] ??= [
                'employment_id' => $employmentId,
                'cost_center' => $allocation['cost_center'],
                'dimension_ids' => $dimensions,
                'wages_minor' => 0,
                'insurance_minor' => 0,
                'other_minor' => 0,
            ];
            $bucket = match (true) {
                str_starts_with($allocation['allocation_key'], 'gross:') => 'wages_minor',
                str_starts_with($allocation['allocation_key'], 'employer-insurance:') => 'insurance_minor',
                default => 'other_minor',
            };
            $groups[$key][$bucket] += $allocation['signed_minor'];
            if ($employmentId !== null) {
                $employmentIds[] = $employmentId;
            }
            foreach ($dimensions as $valueId) {
                $valueIds[] = $valueId;
            }
        }

        $employments = $this->repository->employments($supplierId, $employmentIds);
        $values = $this->repository->dimensionValues($supplierId, $valueIds);

        $rows = [];
        $byDimension = [];
        $totals = ['wages_minor' => 0, 'insurance_minor' => 0, 'other_minor' => 0, 'total_minor' => 0];
        foreach ($groups as $group) {
            $employment = $group['employment_id'] === null ? null : ($employments[$group['employment_id']] ?? null);
            $dimensionRows = [];
            foreach ($group['dimension_ids'] as $typeId => $valueId) {
                $value = $values[$valueId] ?? null;
                if ($value === null) {
                    continue;
                }
                $dimensionRows[] = [
                    'type_id' => $typeId,
                    'type_name' => $value['type_name'],
                    'value_id' => $valueId,
                    'code' => $value['code'],
                    'name' => $value['name'],
                ];
            }
            $total = $group['wages_minor'] + $group['insurance_minor'] + $group['other_minor'];
            $rows[] = [
                'employment_id' => $group['employment_id'],
                'employee_id' => $employment['employee_id'] ?? null,
                'employee_name' => $employment['employee_name'] ?? null,
                'employment_code' => $employment['employment_code'] ?? null,
                'cost_center' => $group['cost_center'],
                'dimensions' => $dimensionRows,
                'wages_minor' => $group['wages_minor'],
                'insurance_minor' => $group['insurance_minor'],
                'other_minor' => $group['other_minor'],
                'total_minor' => $total,
            ];
            foreach (['wages_minor', 'insurance_minor', 'other_minor'] as $field) {
                $totals[$field] += $group[$field];
            }
            $totals['total_minor'] += $total;

            // Souhrn po hodnotě: firemní dimenze, jinak textové středisko,
            // jinak „bez dimenze". Alokace s víc typy se započte u každého.
            $summaryKeys = [];
            foreach ($dimensionRows as $dimension) {
                $summaryKeys['v' . $dimension['value_id']] = [
                    'type_id' => $dimension['type_id'],
                    'type_name' => $dimension['type_name'],
                    'value_id' => $dimension['value_id'],
                    'code' => $dimension['code'],
                    'name' => $dimension['name'],
                ];
            }
            if ($summaryKeys === []) {
                $code = $group['cost_center'] ?? '';
                $summaryKeys['c' . $code] = [
                    'type_id' => null,
                    'type_name' => null,
                    'value_id' => null,
                    'code' => $code,
                    'name' => $code,
                ];
            }
            foreach ($summaryKeys as $summaryKey => $summary) {
                $byDimension[$summaryKey] ??= [...$summary, 'total_minor' => 0];
                $byDimension[$summaryKey]['total_minor'] += $total;
            }
        }

        usort($rows, static fn (array $left, array $right): int =>
            [($left['employee_name'] ?? "\u{10FFFF}"), $left['employment_id'] ?? 0, $left['cost_center'] ?? '']
            <=> [($right['employee_name'] ?? "\u{10FFFF}"), $right['employment_id'] ?? 0, $right['cost_center'] ?? '']);
        $byDimension = array_values($byDimension);
        usort($byDimension, static fn (array $left, array $right): int =>
            [$left['type_name'] ?? "\u{10FFFF}", $left['code']] <=> [$right['type_name'] ?? "\u{10FFFF}", $right['code']]);

        return [
            'year' => $year,
            'rows' => $rows,
            'by_dimension' => $byDimension,
            'totals' => $totals,
        ];
    }

    /** @return array<int,int> typ → hodnota */
    private static function dimensions(?string $json): array
    {
        if ($json === null) {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Alokace mzdového můstku má neplatné dimenze.');
        }
        $result = [];
        foreach ($decoded as $typeId => $valueId) {
            $result[(int) $typeId] = (int) $valueId;
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }
}
