<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

final class PayrollEnforcementStoredResultIntegrity
{
    public static function assertConsistent(
        int $totalWithheldMinorUnits,
        int $employerFeeMinorUnits,
        int $allocatedMinorUnits,
        int $withheldLedgerMinorUnits,
        int $employerFeeLedgerMinorUnits,
        int $heldLedgerMinorUnits,
    ): void {
        if (
            $allocatedMinorUnits + $employerFeeMinorUnits
                !== $totalWithheldMinorUnits
            || $withheldLedgerMinorUnits !== $allocatedMinorUnits
            || $employerFeeLedgerMinorUnits !== $employerFeeMinorUnits
        ) {
            throw new \DomainException(
                'Uložené alokace a účetní pohyby neodpovídají výsledku srážek.',
            );
        }
        if ($heldLedgerMinorUnits > $withheldLedgerMinorUnits) {
            throw new \DomainException(
                'Uložená deponovaná částka převyšuje provedené srážky.',
            );
        }
    }
}
