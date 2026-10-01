<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/**
 * Zvolené datum úhrady je po splatnosti a uživatel ho výslovně nepotvrdil.
 */
final class PayrollPaymentLateDateException extends \DomainException
{
    public function __construct(
        public readonly string $requestedDate,
        public readonly string $latestOnTimeDate,
    ) {
        parent::__construct(sprintf(
            'Datum úhrady %s je po splatnosti (poslední včasné datum příkazu je %s).'
            . ' Platba po splatnosti znamená penále nebo úrok z prodlení;'
            . ' pokud ji tak chcete zadat, potvrďte to výslovně.',
            $requestedDate,
            $latestOnTimeDate,
        ));
    }
}
