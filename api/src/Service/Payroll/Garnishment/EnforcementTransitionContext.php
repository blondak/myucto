<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class EnforcementTransitionContext
{
    /**
     * @param int $heldDepositMinorUnits deponovaná a dosud nevydaná částka případu
     * @param bool $employmentExitSettled povinnému skončily všechny pracovní
     *        vztahy, poslední mzda je schválená a soudu/exekutorovi je vystavené
     *        oznámení podle § 295 odst. 2 o. s. ř.
     */
    public function __construct(
        public bool $evidenceComplete,
        public bool $recipientVerified,
        public int $outstandingMinorUnits,
        public bool $decisionVerified,
        public ?string $reason,
        public int $heldDepositMinorUnits = 0,
        public bool $employmentExitSettled = false,
    ) {
        if ($outstandingMinorUnits < 0) {
            throw new InvalidArgumentException('Outstanding enforcement balance cannot be negative.');
        }
        if ($heldDepositMinorUnits < 0) {
            throw new InvalidArgumentException('Held enforcement deposit cannot be negative.');
        }
    }
}
