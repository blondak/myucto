<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class InsolvencyInstruction
{
    public function __construct(
        public InsolvencyMode $mode,
        public bool $decisionVerified,
        public bool $recipientVerified,
        public ?int $courtDeterminedAmountMinorUnits = null,
    ) {
        if ($courtDeterminedAmountMinorUnits !== null && $courtDeterminedAmountMinorUnits < 0) {
            throw new InvalidArgumentException('Court-determined insolvency amount cannot be negative.');
        }
    }

    public static function none(): self
    {
        return new self(InsolvencyMode::None, false, false);
    }
}
