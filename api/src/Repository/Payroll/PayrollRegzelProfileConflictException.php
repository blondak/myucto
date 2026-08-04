<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollRegzelProfileConflictException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct(
            'REGZEL profil mezitím změnil jiný uživatel. Načti aktuální data.',
        );
    }
}
