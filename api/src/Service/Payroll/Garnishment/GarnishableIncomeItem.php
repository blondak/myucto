<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class GarnishableIncomeItem
{
    public function __construct(
        public string $id,
        public GarnishableIncomeKind $kind,
        public int $netMinorUnits,
        public string $payerId,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Income item ID is required.');
        }
        if ($netMinorUnits < 0) {
            throw new InvalidArgumentException('Income item amount cannot be negative.');
        }
        if (trim($payerId) === '') {
            throw new InvalidArgumentException('Income payer ID is required.');
        }
    }
}
