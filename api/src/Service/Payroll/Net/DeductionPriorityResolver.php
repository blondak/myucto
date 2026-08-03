<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

final class DeductionPriorityResolver
{
    /**
     * @param list<PayrollDeductionRequest> $deductions
     * @return list<PayrollDeductionResult>
     */
    public function resolve(array $deductions, int $capacityMinorUnits): array
    {
        if ($capacityMinorUnits < 0) {
            throw new \InvalidArgumentException('Kapacita dobrovolných srážek nesmí být záporná.');
        }
        usort($deductions, static fn (
            PayrollDeductionRequest $left,
            PayrollDeductionRequest $right,
        ): int => $left->priority <=> $right->priority
            ?: strcmp($left->deductionReference, $right->deductionReference));

        $remaining = $capacityMinorUnits;
        $results = [];
        foreach ($deductions as $deduction) {
            $eligible = $deduction->active
                ? min(
                    $deduction->requestedMinorUnits,
                    $deduction->remainingLimitMinorUnits
                        ?? $deduction->requestedMinorUnits,
                )
                : 0;
            $applied = min($eligible, $remaining);
            $remaining -= $applied;
            $results[] = new PayrollDeductionResult(
                $deduction->deductionReference,
                $deduction->priority,
                $deduction->requestedMinorUnits,
                $applied,
                $deduction->requestedMinorUnits - $applied,
                $deduction->active,
            );
        }
        return $results;
    }
}
