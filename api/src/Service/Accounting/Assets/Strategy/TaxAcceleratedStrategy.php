<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets\Strategy;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Zrychlené daňové odpisy §32 ZDP (Epic F3, spec §2.4). n = počet let, po která
 * již bylo odpisováno (opening + nepauzové roky s odpisem; pauzy §26/8 n
 * nezvyšují, R14). TZ (§32/3): rok dokončení = 2×ZZC/k₃, dále 2×ZC/(k₃−n′),
 * n′ = roky odpisování ze zvýšené ZC; opakované TZ resetuje n′. Pořadí operací
 * R7 (surový → půlení §26/7 → ceil → cap), §30e krátí jen `amount` (R8).
 */
final class TaxAcceleratedStrategy implements TaxDepreciationStrategyInterface
{
    private const EPS = 0.005;
    private const M1_LIMIT = 2000000.0;

    // §32 odst. 1 — koeficienty [k1 (1. rok), k2 (další roky), k3 (pro zvýšenou ZC)]
    private const COEF = [
        1 => [3, 4, 3], 2 => [5, 6, 5], 3 => [10, 11, 10],
        4 => [20, 21, 20], 5 => [30, 31, 30], 6 => [50, 51, 50],
    ];

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
        $coefTable = (array) ($this->constants?->forYear($y1)['depreciation_accelerated_coefficients'] ?? self::COEF);
        $coef = $coefTable[$ctx->taxGroup] ?? null;
        if ($coef === null) {
            return [];
        }
        [$k1, $k2, $k3] = $coef;

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
        $n = $ctx->openingTaxYears; // počet let, po která již bylo odpisováno
        $tzMode = false;
        $nPrime = 0; // počet let odpisování ze zvýšené ZC
        $m1Active = $ctx->isM1Vehicle && !$ctx->m1LimitException;

        for ($y = $y1, $i = 0; $i < 300; $y++, $i++) {
            $coefTable = (array) ($this->constants?->forYear($y)['depreciation_accelerated_coefficients'] ?? self::COEF);
            [$k1, $k2, $k3] = $coefTable[$ctx->taxGroup] ?? $coef;
            $m1Limit = (float) ($this->constants?->forYear($y)['m1_depreciation_limit'] ?? self::M1_LIMIT);
            $tzThisYear = false;
            if ($y > $y1 && isset($tzByYear[$y])) {
                $vc += $tzByYear[$y];
                $tzMode = true;
                $nPrime = 0;
                $tzThisYear = true;
            }
            $zc = $vc - $ctx->openingTaxAmount - $sumFull;

            if ($y < $y1 + $ctx->openingTaxYears) {
                if ($tzMode) {
                    $nPrime++;
                }
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
                if (empty($entry['is_paused'])) {
                    if ($full > 0 || !empty($entry['is_half'])) {
                        $n++;
                    }
                    if ($tzMode) {
                        $nPrime++;
                    }
                }
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
                $raw = $baseVc / $k1;
            } elseif ($tzThisYear) {
                $raw = 2 * $zc / $k3; // ZZC = ZC na počátku roku + TZ letoška (§32/3)
            } elseif ($tzMode) {
                $raw = 2 * $zc / max(1, $k3 - $nPrime);
            } else {
                $raw = 2 * $zc / max(1, $k2 - $n);
            }

            $isHalf = false;
            if ($disposalYear === $y) {
                $raw /= 2; // půlodpis §26 odst. 7 písm. a); rok se počítá do n
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
            $n++;
            if ($tzMode) {
                $nPrime++;
            }

            if ($disposalYear === $y) {
                break;
            }
            if ($zc - $full <= self::EPS && $lastTzYear <= $y) {
                break;
            }
        }

        return $rows;
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
