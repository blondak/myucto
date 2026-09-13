<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Rozvržení listu: kde je hlavička, odkud začínají data a jak se sloupce jmenují.
 */
final class AttendanceSheetLayout
{
    /**
     * @param array<int,string> $headers sloupec (od 1) → text hlavičky
     */
    public function __construct(
        public readonly ?int $headerRow,
        public readonly int $dataStartRow,
        public readonly array $headers,
        public readonly ?int $suggestedPersonColumn,
        public readonly ?int $repeatedHeaderRow,
    ) {
    }
}
