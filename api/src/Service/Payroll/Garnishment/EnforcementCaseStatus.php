<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum EnforcementCaseStatus: string
{
    case Received = 'received';
    case WithholdAndHold = 'withhold_and_hold';
    case Remit = 'remit';
    case DeferredNoWithholding = 'deferred_no_withholding';
    case DeferredHold = 'deferred_hold';
    case Paid = 'paid';
    case Stopped = 'stopped';

    public function isTerminal(): bool
    {
        return $this === self::Paid || $this === self::Stopped;
    }
}
