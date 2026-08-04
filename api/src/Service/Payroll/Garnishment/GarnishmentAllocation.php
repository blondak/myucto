<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use JsonSerializable;

final readonly class GarnishmentAllocation implements JsonSerializable
{
    public int $totalMinorUnits;

    public function __construct(
        public string $claimId,
        public int $firstPoolMinorUnits,
        public int $secondPoolMinorUnits,
    ) {
        if (trim($claimId) === '') {
            throw new \InvalidArgumentException('Allocation claim ID cannot be blank.');
        }
        if ($firstPoolMinorUnits < 0 || $secondPoolMinorUnits < 0) {
            throw new \InvalidArgumentException('Allocation pools cannot be negative.');
        }
        if ($firstPoolMinorUnits > PHP_INT_MAX - $secondPoolMinorUnits) {
            throw new \OverflowException('Allocation total exceeds the integer range.');
        }
        $this->totalMinorUnits = $firstPoolMinorUnits + $secondPoolMinorUnits;
    }

    /** @return array{claim_id:string,first_pool_minor_units:int,second_pool_minor_units:int,total_minor_units:int} */
    public function jsonSerialize(): array
    {
        return [
            'claim_id' => $this->claimId,
            'first_pool_minor_units' => $this->firstPoolMinorUnits,
            'second_pool_minor_units' => $this->secondPoolMinorUnits,
            'total_minor_units' => $this->totalMinorUnits,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromCanonicalArray(array $data): self
    {
        $claimId = $data['claim_id'] ?? null;
        $first = $data['first_pool_minor_units'] ?? null;
        $second = $data['second_pool_minor_units'] ?? null;
        if (!is_string($claimId) || !is_int($first) || !is_int($second)) {
            throw new \InvalidArgumentException('Garnishment allocation snapshot is invalid.');
        }
        $allocation = new self($claimId, $first, $second);
        if (($data['total_minor_units'] ?? null) !== $allocation->totalMinorUnits) {
            throw new \InvalidArgumentException('Garnishment allocation total is invalid.');
        }
        return $allocation;
    }
}
