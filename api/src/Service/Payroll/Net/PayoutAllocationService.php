<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Service\Payroll\Calculation\Money;

final class PayoutAllocationService
{
    /**
     * @param list<PayoutAllocationRequest> $requests
     */
    public function allocate(int $netPayableMinorUnits, array $requests): PayoutAllocationResult
    {
        if ($netPayableMinorUnits < 0 || $requests === []) {
            throw new \InvalidArgumentException('Výplata musí být nezáporná a mít alokační pravidla.');
        }
        $references = [];
        $remainderCount = 0;
        foreach ($requests as $request) {
            if (isset($references[$request->allocationReference])) {
                throw new \InvalidArgumentException('Alokační pravidlo je uvedeno vícekrát.');
            }
            $references[$request->allocationReference] = true;
            $remainderCount += $request->allocationKind === 'remainder' ? 1 : 0;
        }
        if ($remainderCount !== 1) {
            throw new \InvalidArgumentException('Výplata musí mít právě jeden cílový účet pro zbytek.');
        }
        usort($requests, static fn (
            PayoutAllocationRequest $left,
            PayoutAllocationRequest $right,
        ): int => $left->priority <=> $right->priority
            ?: strcmp($left->allocationReference, $right->allocationReference));

        $allocated = new Money(0);
        $result = [];
        $remainderRequest = null;
        foreach ($requests as $request) {
            if ($request->allocationKind === 'remainder') {
                $remainderRequest = $request;
                continue;
            }
            $amount = $request->allocationKind === 'fixed'
                ? (int) $request->amountMinorUnits
                : $this->percentageAmount(
                    $netPayableMinorUnits,
                    (int) $request->basisPoints,
                );
            $allocated = $allocated->add(new Money($amount));
            if ($allocated->minorUnits > $netPayableMinorUnits) {
                throw new \DomainException('Pevné a procentní alokace překračují čistou výplatu.');
            }
            $result[] = new PayoutAllocation(
                $request->allocationReference,
                $request->destinationKind,
                $request->destinationReference,
                $request->allocationKind,
                $amount,
                $request->priority,
            );
        }
        if (!$remainderRequest instanceof PayoutAllocationRequest) {
            throw new \LogicException('Chybí alokace zbytku.');
        }
        $result[] = new PayoutAllocation(
            $remainderRequest->allocationReference,
            $remainderRequest->destinationKind,
            $remainderRequest->destinationReference,
            $remainderRequest->allocationKind,
            $netPayableMinorUnits - $allocated->minorUnits,
            $remainderRequest->priority,
        );
        usort($result, static fn (
            PayoutAllocation $left,
            PayoutAllocation $right,
        ): int => $left->priority <=> $right->priority
            ?: strcmp($left->allocationReference, $right->allocationReference));
        return new PayoutAllocationResult($netPayableMinorUnits, $result);
    }

    private function percentageAmount(int $amountMinorUnits, int $basisPoints): int
    {
        $whole = intdiv($amountMinorUnits, 10_000) * $basisPoints;
        $fraction = intdiv(($amountMinorUnits % 10_000) * $basisPoints, 10_000);
        return $whole + $fraction;
    }
}
