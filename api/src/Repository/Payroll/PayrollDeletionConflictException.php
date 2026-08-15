<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Mazaný záznam se mezi vykreslením a kliknutím změnil — `row_version` nesedí.
 * Nese aktuální verzi, aby si klient mohl kartu znovu načíst.
 */
final class PayrollDeletionConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion, string $message)
    {
        parent::__construct($message);
    }
}
