<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollPersonStatutoryEvidenceConflictException extends \RuntimeException
{
    public function __construct(
        public readonly string $collection,
        public readonly int $rowId,
        public readonly int $currentVersion,
    ) {
        parent::__construct('Zákonnou evidenci mezitím upravil jiný uživatel.');
    }
}
