<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/** Platba zálohy se páruje s konečnou fakturou místo se zálohou ({@see AdvanceFinalMatchGuard}). */
final class AdvanceFinalMatchException extends \RuntimeException
{
    /** @param 'purchase_invoice'|'invoice' $advanceType */
    public function __construct(
        public readonly int $advanceId,
        public readonly string $advanceType,
        string $message,
    ) {
        parent::__construct($message);
    }
}
