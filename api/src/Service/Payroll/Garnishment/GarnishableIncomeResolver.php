<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;
use OverflowException;

final class GarnishableIncomeResolver
{
    /** @param list<GarnishableIncomeItem> $items */
    public function resolve(array $items, bool $evidenceComplete = false): GarnishableIncomeResult
    {
        $seen = [];
        $garnishable = 0;
        $excluded = 0;
        $issues = [];
        $trace = [];
        $payers = [];
        if (!$evidenceComplete) {
            $issues[] = 'income_register_evidence_incomplete';
        }

        usort($items, static fn (GarnishableIncomeItem $a, GarnishableIncomeItem $b): int =>
            $a->id <=> $b->id);

        foreach ($items as $item) {
            if (isset($seen[$item->id])) {
                throw new InvalidArgumentException("Duplicate income item ID {$item->id}.");
            }
            $seen[$item->id] = true;
            $payers[$item->payerId] = true;
            if ($item->kind === GarnishableIncomeKind::Severance) {
                $issues[] = "income:{$item->id}:severance_period_split_required";
                $trace[] = [
                    'id' => $item->id,
                    'kind' => $item->kind->value,
                    'amount_minor_units' => $item->netMinorUnits,
                    'payer_id' => $item->payerId,
                    'treatment' => 'manual_review',
                ];
                continue;
            }
            $treatment = $item->kind->isGarnishable();
            if ($treatment === null) {
                $issues[] = "income:{$item->id}:kind_requires_manual_review";
                $trace[] = [
                    'id' => $item->id,
                    'kind' => $item->kind->value,
                    'amount_minor_units' => $item->netMinorUnits,
                    'payer_id' => $item->payerId,
                    'treatment' => 'manual_review',
                ];
                continue;
            }

            if ($treatment) {
                $garnishable = self::addExactly($garnishable, $item->netMinorUnits);
            } else {
                $excluded = self::addExactly($excluded, $item->netMinorUnits);
            }
            $trace[] = [
                'id' => $item->id,
                'kind' => $item->kind->value,
                'amount_minor_units' => $item->netMinorUnits,
                'payer_id' => $item->payerId,
                'treatment' => $treatment ? 'garnishable' : 'excluded',
            ];
        }
        if (count($payers) > 1) {
            $issues[] = 'multiple_income_payers_require_separate_calculation';
        }

        if ($issues !== []) {
            sort($issues, SORT_STRING);

            return new GarnishableIncomeResult(
                GarnishmentStatus::ManualReview,
                0,
                $excluded,
                $issues,
                $trace,
            );
        }

        return new GarnishableIncomeResult(
            GarnishmentStatus::Supported,
            $garnishable,
            $excluded,
            [],
            $trace,
        );
    }

    private static function addExactly(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new OverflowException('Garnishable income accumulation exceeds the integer range.');
        }

        return $left + $right;
    }
}
