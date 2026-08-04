<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

final class SicknessCompensationCalculator
{
    public function __construct(private readonly ?PayrollRulesetProvider $rulesets = null) {}

    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     */
    public function calculate(
        string $date,
        int $averageHourlyMinor,
        array $segments,
    ): SicknessCompensationResult {
        if ($averageHourlyMinor <= 0) {
            throw new InvalidArgumentException('DPN náhrada vyžaduje kladný hodinový průměr.');
        }
        if ($segments === []) {
            throw new InvalidArgumentException('DPN náhrada vyžaduje alespoň jednu publikovanou směnu.');
        }

        $ruleset = ($this->rulesets ?? CzechPayrollRulesets2026::provider())
            ->forDate(PayrollRulesetDomain::CompensationAverages, $date);
        $boundary1 = $this->integerParameter($ruleset->parameters, 'wage_compensation.hourly_boundary_1_minor');
        $boundary2 = $this->integerParameter($ruleset->parameters, 'wage_compensation.hourly_boundary_2_minor');
        $boundary3 = $this->integerParameter($ruleset->parameters, 'wage_compensation.hourly_boundary_3_minor');
        $bandRate1 = $this->rateBasisPoints($ruleset->parameters, 'wage_compensation.reduction_band_1_rate');
        $bandRate2 = $this->rateBasisPoints($ruleset->parameters, 'wage_compensation.reduction_band_2_rate');
        $bandRate3 = $this->rateBasisPoints($ruleset->parameters, 'wage_compensation.reduction_band_3_rate');
        $compensationRate = $this->rateBasisPoints(
            $ruleset->parameters,
            'wage_compensation.compensation_rate',
        );

        $band1 = min($averageHourlyMinor, $boundary1);
        $band2 = min(max($averageHourlyMinor - $boundary1, 0), $boundary2 - $boundary1);
        $band3 = min(max($averageHourlyMinor - $boundary2, 0), $boundary3 - $boundary2);
        $reducedHourlyMinor = RoundingMode::HalfUp->roundFraction(
            $band1 * $bandRate1 + $band2 * $bandRate2 + $band3 * $bandRate3,
            10_000,
        );

        $calculatedSegments = [];
        $totalMinor = 0;
        foreach ($segments as $segment) {
            $planned = $segment['planned_minutes'];
            $eligible = $segment['eligible_minutes'];
            if ($planned <= 0 || $eligible <= 0 || $eligible > $planned) {
                throw new InvalidArgumentException('Minuty DPN směny nejsou platné.');
            }
            $amountMinor = RoundingMode::HalfUp->roundFraction(
                $reducedHourlyMinor * $compensationRate * $eligible,
                10_000 * 60,
            );
            $totalMinor += $amountMinor;
            $calculatedSegments[] = [
                'shift_id' => $segment['shift_id'],
                'local_date' => $segment['local_date'],
                'planned_minutes' => $planned,
                'eligible_minutes' => $eligible,
                'hourly_average_minor' => $averageHourlyMinor,
                'reduced_hourly_minor' => $reducedHourlyMinor,
                'compensation_minor' => $amountMinor,
                'rounding' => 'half-up-to-minor-unit',
            ];
        }

        return new SicknessCompensationResult(
            $reducedHourlyMinor,
            $totalMinor,
            'manual_review',
            $ruleset->id,
            $ruleset->canonicalHash,
            $calculatedSegments,
            [
                'average_hourly_minor' => $averageHourlyMinor,
                'hourly_boundary_1_minor' => $boundary1,
                'hourly_boundary_2_minor' => $boundary2,
                'hourly_boundary_3_minor' => $boundary3,
                'band_1_basis_points' => $bandRate1,
                'band_2_basis_points' => $bandRate2,
                'band_3_basis_points' => $bandRate3,
                'compensation_basis_points' => $compensationRate,
                'segment_count' => count($calculatedSegments),
                'compensation_minor' => $totalMinor,
                'support_status' => 'manual_review',
            ],
        );
    }

    /**
     * @param array<string,\MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue> $parameters
     */
    private function integerParameter(array $parameters, string $key): int
    {
        $value = $parameters[$key] ?? null;
        if ($value === null || !is_int($value->value) || $value->value <= 0) {
            throw new \LogicException("Ruleset neobsahuje kladný celočíselný parametr {$key}.");
        }
        return $value->value;
    }

    /**
     * @param array<string,\MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue> $parameters
     */
    private function rateBasisPoints(array $parameters, string $key): int
    {
        $value = $parameters[$key] ?? null;
        $text = $value?->value;
        if (!is_string($text) || preg_match('/^0\.([0-9]{1,4})$/', $text, $match) !== 1) {
            throw new \LogicException("Ruleset neobsahuje kanonickou desetinnou sazbu {$key}.");
        }
        $basisPoints = (int) str_pad($match[1], 4, '0');
        if ($basisPoints <= 0 || $basisPoints > 10_000) {
            throw new \LogicException("Ruleset obsahuje neplatnou sazbu {$key}.");
        }
        return $basisPoints;
    }
}
