<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

enum DeductionAgreementStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Ended || $this === self::Cancelled;
    }

    /**
     * Jen aktivní dohoda vstupuje do zmrazeného vstupního snapshotu mzdového
     * běhu (PayrollRunSnapshotBuilder::deductionAgreements), takže pozastavení
     * i ukončení srážku zastaví, aniž by se sáhlo na historii ledgeru.
     */
    public function entersPayrollRun(): bool
    {
        return $this === self::Active;
    }
}
