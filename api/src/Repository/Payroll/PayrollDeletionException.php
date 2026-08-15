<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Smazání mzdového objektu selhalo na přeověření pod zámkem — mezi vykreslením
 * `can_delete` a kliknutím se stav změnil (vznikla vazba, přibyl pohyb).
 * Nese kód pro frontend a větu, podle které se dá jednat.
 */
final class PayrollDeletionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $employmentId = null,
        public readonly ?string $employmentCode = null,
    ) {
        parent::__construct($message);
    }
}
