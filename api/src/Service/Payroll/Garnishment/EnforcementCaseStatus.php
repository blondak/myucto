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
    /**
     * Srážení u TOHOTO plátce skončilo, protože povinnému skončil pracovní
     * poměr. Exekuce tím zastavená není — pokračuje u dalšího plátce, kterého
     * soud vyrozumí (§ 294 odst. 3 o. s. ř.).
     */
    case EndedAtPayer = 'ended_at_payer';

    public function isTerminal(): bool
    {
        return $this === self::Paid
            || $this === self::Stopped
            || $this === self::EndedAtPayer;
    }
}
