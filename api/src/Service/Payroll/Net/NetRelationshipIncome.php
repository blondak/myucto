<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use JsonSerializable;

final readonly class NetRelationshipIncome implements JsonSerializable
{
    public function __construct(
        public string $relationshipReference,
        public int $cashIncomeMinorUnits,
        public int $nonCashIncomeMinorUnits,
    ) {
        if ($relationshipReference === '') {
            throw new \InvalidArgumentException('Vztah musí mít neprázdný identifikátor.');
        }
        if ($cashIncomeMinorUnits < 0 || $nonCashIncomeMinorUnits < 0) {
            throw new \InvalidArgumentException('Příjem vztahu nesmí být záporný.');
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'relationship_reference' => $this->relationshipReference,
            'cash_income_minor_units' => $this->cashIncomeMinorUnits,
            'non_cash_income_minor_units' => $this->nonCashIncomeMinorUnits,
        ];
    }
}
