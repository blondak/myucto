<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Jeden list sešitu (nebo celé CSV) jako řídká mřížka neprázdných buněk.
 */
final class AttendanceSheet
{
    /**
     * @param array<int,array<int,AttendanceCell>> $rows řádek (od 1) → sloupec (od 1) → buňka
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $file,
        public readonly int $fileIndex,
        public readonly int $sheetIndex,
        public readonly string $name,
        public readonly array $rows,
        public readonly int $maxRow,
        public readonly int $maxColumn,
        public readonly array $warnings = [],
    ) {
    }

    public function id(): string
    {
        return $this->file . '#' . $this->name;
    }

    public function cell(int $row, int $column): AttendanceCell
    {
        return $this->rows[$row][$column] ?? AttendanceCell::empty();
    }

    public function source(int $row, int $column): string
    {
        return $this->file . '!' . $this->name . '!'
            . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column) . $row;
    }
}
