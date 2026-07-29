<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets\Strategy;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Rovnoměrné daňové odpisy §31 ZDP (Epic F3, spec §2.3). Pořadí operací R7:
 * surový výpočet → půlení §26/7 → ceil na celé Kč → cap na daňovou ZC.
 * §30e (M1 > 2 mil.) krátí koeficientem jen `amount`; ZC a vzorce jedou
 * z nekráceného `full_amount` (R8). Přerušení §26/8 = potvrzený řádek
 * is_paused (R14) — ZC beze změny, plán se posouvá.
 */
final class TaxStraightLineStrategy implements TaxDepreciationStrategyInterface
{
    private const EPS = 0.005;
    private const M1_LIMIT = 2000000.0;

    // §31 odst. 1 písm. a) — základní: [1. rok, další roky, pro zvýšenou VC]
    private const RATES_BASIC = [
        1 => [20.0,  40.0,  33.3],
        2 => [11.0,  22.25, 20.0],
        3 => [5.5,   10.5,  10.0],
        4 => [2.15,  5.15,  5.0],
        5 => [1.4,   3.4,   3.4],
        6 => [1.02,  2.02,  2.0],
    ];
    // §31/1 b) +20 % (zemědělská a lesní výroba), c) +15 % (čistírny, ekolog. zařízení),
    // d) +10 % (ostatní HM sk. 1-3) — jen PRVNÍ ODPISOVATEL, jen skupiny 1-3:
    private const RATES_P20 = [1 => [40.0, 30.0, 33.3], 2 => [31.0, 17.25, 20.0], 3 => [24.4, 8.4, 10.0]];
    private const RATES_P15 = [1 => [35.0, 32.5, 33.3], 2 => [26.0, 18.5, 20.0], 3 => [19.0, 9.0, 10.0]];
    private const RATES_P10 = [1 => [30.0, 35.0, 33.3], 2 => [21.0, 19.75, 20.0], 3 => [15.4, 9.4, 10.0]];

    public function __construct(private readonly ?TaxConstantsRepository $constants = null) {}

    public function plan(DepreciationContext $ctx): array
    {
        return $this->buildPlan($ctx, null);
    }

    public function yearRow(DepreciationContext $ctx, int $fiscalYear): ?array
    {
        foreach ($this->buildPlan($ctx, $fiscalYear) as $row) {
            if ($row['fiscal_year'] === $fiscalYear) {
                return $row;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function buildPlan(DepreciationContext $ctx, ?int $forceComputeYear): array
    {
        if ($ctx->putIntoUseDate === null || $ctx->taxGroup === null || $ctx->inputPrice <= 0) {
            return [];
        }
        $y1 = $ctx->calendar->fiscalYearOfDate($ctx->putIntoUseDate);
        $disposalYear = $ctx->disposalDate !== null ? $ctx->calendar->fiscalYearOfDate($ctx->disposalDate) : null;
        if ($disposalYear !== null && $disposalYear < $y1) {
            return [];
        }

        $tzByYear = [];
        foreach ($ctx->improvements as $imp) {
            $year = $ctx->calendar->fiscalYearOfDate((string) $imp['completed_on']);
            $tzByYear[$year] = ($tzByYear[$year] ?? 0.0) + (float) $imp['amount'];
        }
        $baseVc = $ctx->inputPrice;
        $lastTzYear = 0;
        foreach ($tzByYear as $year => $amount) {
            if ($year <= $y1) {
                $baseVc += $amount; // TZ v 1. roce odpisování je součástí VC (§29/1, R15)
            } else {
                $lastTzYear = max($lastTzYear, $year);
            }
        }

        $confirmed = [];
        foreach ($ctx->confirmedEntries as $entry) {
            if (($entry['kind'] ?? '') === 'tax') {
                $confirmed[(int) $entry['fiscal_year']] = $entry;
            }
        }

        if ($disposalYear === $y1) {
            $zc = $baseVc - $ctx->openingTaxAmount;
            return [$this->row($y1, 0.0, 0.0, $zc, $zc, false, false, 'computed',
                'Zařazení i vyřazení v témže zdaňovacím období — daňový odpis 0 (§26 odst. 7 ZDP).')];
        }

        $rows = [];
        $sumFull = 0.0;
        $sumClaimed = 0.0;
        $vc = $baseVc;
        $hasTz = false;
        $m1Active = $ctx->isM1Vehicle && !$ctx->m1LimitException;

        for ($y = $y1, $i = 0; $i < 300; $y++, $i++) {
            $rates = $this->rates($ctx, $y);
            if ($rates === null) {
                return [];
            }
            [$rateFirst, $rateNext, $rateIncreased] = $rates;
            $m1Limit = (float) ($this->constants?->forYear($y)['m1_depreciation_limit'] ?? self::M1_LIMIT);
            if ($y > $y1 && isset($tzByYear[$y])) {
                $vc += $tzByYear[$y];
                $hasTz = true;
            }
            $zc = $vc - $ctx->openingTaxAmount - $sumFull;

            if ($y < $y1 + $ctx->openingTaxYears) {
                continue; // roky odepsané před evidencí v systému (R23) — bez řádku
            }

            $entry = $confirmed[$y] ?? null;
            if ($forceComputeYear === $y && $entry !== null && empty($entry['is_paused'])) {
                $entry = null;
            }

            if ($entry !== null) {
                $full = (float) $entry['full_amount'];
                $amount = (float) $entry['amount'];
                $rows[] = $this->row($y, $amount, $full, $zc, $zc - $full,
                    (bool) $entry['is_half'], (bool) $entry['is_paused'], 'confirmed', null);
                $sumFull += $full;
                $sumClaimed += $amount;
                if ($disposalYear === $y) {
                    break;
                }
                if ($zc - $full <= self::EPS && $lastTzYear <= $y) {
                    break;
                }
                continue;
            }

            if ($zc <= self::EPS) {
                if ($lastTzYear > $y) {
                    continue; // plně odepsáno, čeká se na budoucí TZ
                }
                break;
            }

            if ($y === $y1 && $ctx->openingTaxYears === 0) {
                $raw = $baseVc * $rateFirst / 100;
            } elseif ($hasTz) {
                $raw = $vc * $rateIncreased / 100; // sazba pro zvýšenou VC (§31/8)
            } else {
                $raw = $baseVc * $rateNext / 100;
            }

            $isHalf = false;
            if ($disposalYear === $y) {
                $raw /= 2; // půlodpis §26 odst. 7 písm. a)
                $isHalf = true;
            }

            $full = min((float) ceil(round($raw, 6)), $zc);
            $amount = $full;
            $note = null;
            if ($m1Active && $vc > $m1Limit) {
                $k = $m1Limit / $vc;
                $amount = min((float) ceil(round($full * $k, 6)), max(0.0, $m1Limit - $sumClaimed));
                $note = '§30e — uplatněná část odpisu koeficientem ' . number_format($m1Limit, 0, ',', ' ') . ' / VC.';
            }

            $rows[] = $this->row($y, $amount, $full, $zc, $zc - $full, $isHalf, false, 'computed', $note);
            $sumFull += $full;
            $sumClaimed += $amount;

            if ($disposalYear === $y) {
                break;
            }
            if ($zc - $full <= self::EPS && $lastTzYear <= $y) {
                break;
            }
        }

        return $rows;
    }

    /** @return array{0:float,1:float,2:float}|null */
    private function rates(DepreciationContext $ctx, int $year): ?array
    {
        $group = $ctx->taxGroup;
        $configured = (array) ($this->constants?->forYear($year)['depreciation_straight_rates'] ?? []);
        $basic = (array) ($configured['basic'] ?? self::RATES_BASIC);
        if ($ctx->firstYearIncrease !== 'none' && $group >= 1 && $group <= 3) {
            $table = match ($ctx->firstYearIncrease) {
                'p20' => (array) ($configured['p20'] ?? self::RATES_P20),
                'p15' => (array) ($configured['p15'] ?? self::RATES_P15),
                'p10' => (array) ($configured['p10'] ?? self::RATES_P10),
                default => null,
            };
            if ($table !== null && isset($table[$group])) {
                return $table[$group];
            }
        }
        return $basic[$group] ?? null;
    }

    /** @return array<string,mixed> */
    private function row(
        int $year,
        float $amount,
        float $full,
        float $residualStart,
        float $residualEnd,
        bool $isHalf,
        bool $isPaused,
        string $source,
        ?string $note,
    ): array {
        return [
            'fiscal_year' => $year,
            'amount' => $amount,
            'full_amount' => $full,
            'residual_start' => $residualStart,
            'residual_end' => $residualEnd,
            'is_half' => $isHalf,
            'is_paused' => $isPaused,
            'months_count' => null,
            'months' => null,
            'source' => $source,
            'note' => $note,
        ];
    }
}
