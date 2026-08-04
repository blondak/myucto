<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

final readonly class PayrollDeductionRequest
{
    public function __construct(
        public string $deductionReference,
        public int $priority,
        public int $requestedMinorUnits,
        public ?int $remainingLimitMinorUnits,
        public bool $active,
    ) {
        if ($deductionReference === '') {
            throw new \InvalidArgumentException('Srážka musí mít neprázdný identifikátor.');
        }
        if ($priority < 0 || $requestedMinorUnits < 0) {
            throw new \InvalidArgumentException('Pořadí ani požadovaná srážka nesmí být záporné.');
        }
        if ($remainingLimitMinorUnits !== null && $remainingLimitMinorUnits < 0) {
            throw new \InvalidArgumentException('Zbývající limit srážky nesmí být záporný.');
        }
    }
}
