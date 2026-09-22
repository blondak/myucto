<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Výplatní účet osoby z předchozího mzdového systému. Neaktivní účet (mzda na něj
 * nechodila) se zapíše bez podílu výplaty a neověřuje se.
 */
final readonly class PayrollTakeoverPayoutAccount
{
    public function __construct(
        public string $account,
        public string $bankCode,
        public bool $active = true,
    ) {}
}
