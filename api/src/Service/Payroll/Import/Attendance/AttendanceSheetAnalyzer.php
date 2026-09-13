<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Najde v listu řádek hlaviček, zopakovanou hlavičku a sloupec se jménem osoby.
 *
 * Mapuje se podle hlaviček, ne podle pozic: verze šablony se liší tím, zda
 * hlavička sedí na řádku 1, 2 nebo 3, a vstupní listy ji často opakují hned
 * pod sebou. Obojí nesmí posunout data ani vyrobit „osobu" jménem hlavičky.
 */
final class AttendanceSheetAnalyzer
{
    /** Hlavička sloupce osob, který v sešitu žádný popisek nemá. */
    public const PERSON_PLACEHOLDER = '(osoba)';

    private const HEADER_SCAN_ROWS = 10;
    private const PERSON_SCAN_ROWS = 300;

    public function __construct(private readonly AttendanceRuleSuggester $suggester)
    {
    }

    public function analyze(AttendanceSheet $sheet): AttendanceSheetLayout
    {
        $headerRow = null;
        $bestScore = 0;
        for ($row = 1; $row <= min(self::HEADER_SCAN_ROWS, $sheet->maxRow); ++$row) {
            $texts = 0;
            $personHeader = false;
            foreach ($sheet->rows[$row] ?? [] as $cell) {
                if ($cell->kind !== AttendanceCell::STRING || !self::isLabel($cell->textValue())) {
                    continue;
                }
                ++$texts;
                $personHeader = $personHeader || $this->suggester->isPersonHeader($cell->textValue());
            }
            if ($texts < 2) {
                continue;
            }
            $score = $texts + ($personHeader ? 3 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $headerRow = $row;
            }
        }
        if ($headerRow === null) {
            return new AttendanceSheetLayout(null, 1, [], null, null);
        }

        $headers = [];
        foreach ($sheet->rows[$headerRow] ?? [] as $column => $cell) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $cell->textValue()));
            if ($text !== '') {
                $headers[$column] = $text;
            }
        }

        $dataStart = $headerRow + 1;
        $repeated = null;
        if ($this->repeatsHeader($sheet, $headerRow + 1, $headers)) {
            $repeated = $headerRow + 1;
            $dataStart = $headerRow + 2;
        }

        /*
         * Exporty docházky mívají v buňce nad jmény datum exportu místo popisku.
         * Sloupec osob se pak najde podle obsahu a dostane zástupnou hlavičku,
         * aby na něj šlo mířit pravidlem profilu a aby vůbec vznikly osoby.
         */
        $personColumn = $this->personColumn($sheet, $headers, $dataStart);
        if ($personColumn !== null && (!isset($headers[$personColumn]) || !self::isLabel($headers[$personColumn]))) {
            $headers[$personColumn] = self::PERSON_PLACEHOLDER;
            ksort($headers);
        }

        return new AttendanceSheetLayout(
            $headerRow,
            $dataStart,
            $headers,
            $personColumn,
            $repeated,
        );
    }

    /**
     * Jednotka podle formátu čísla: většina číselných buněk ve formátu
     * trvání nebo data s časem = excelové trvání, jinak desetinné hodiny.
     */
    public function suggestHoursUnit(AttendanceSheet $sheet, AttendanceSheetLayout $layout, int $column): string
    {
        $numbers = 0;
        $durations = 0;
        for ($row = $layout->dataStartRow; $row <= $sheet->maxRow; ++$row) {
            $cell = $sheet->cell($row, $column);
            if ($cell->kind !== AttendanceCell::NUMBER) {
                continue;
            }
            ++$numbers;
            if ($cell->hasDurationFormat()) {
                ++$durations;
            }
        }

        return $numbers > 0 && $durations * 2 > $numbers
            ? AttendanceMeaning::UNIT_DURATION
            : AttendanceMeaning::UNIT_HOURS;
    }

    /** @param array<int,string> $headers */
    private function repeatsHeader(AttendanceSheet $sheet, int $row, array $headers): bool
    {
        $cells = $sheet->rows[$row] ?? [];
        if ($cells === []) {
            return false;
        }
        $same = 0;
        foreach ($cells as $column => $cell) {
            if (isset($headers[$column])
                && AttendanceText::normalize($cell->textValue()) === AttendanceText::normalize($headers[$column])) {
                ++$same;
            }
        }

        return $same >= 2 && $same * 10 >= count($cells) * 6;
    }

    /** @param array<int,string> $headers */
    private function personColumn(AttendanceSheet $sheet, array $headers, int $dataStart): ?int
    {
        foreach ($headers as $column => $header) {
            if ($this->suggester->isPersonHeader($header)) {
                return $column;
            }
        }
        $best = null;
        $bestCount = 0;
        $last = min($sheet->maxRow, $dataStart + self::PERSON_SCAN_ROWS);
        for ($column = 1; $column <= $sheet->maxColumn; ++$column) {
            $names = 0;
            $filled = 0;
            for ($row = $dataStart; $row <= $last; ++$row) {
                $cell = $sheet->cell($row, $column);
                if ($cell->isEmpty()) {
                    continue;
                }
                ++$filled;
                if ($cell->kind === AttendanceCell::STRING && AttendanceText::looksLikePersonName($cell->textValue())) {
                    ++$names;
                }
            }
            if ($names >= 2 && $names * 10 >= $filled * 3 && $names > $bestCount) {
                $best = $column;
                $bestCount = $names;
            }
        }

        return $best;
    }

    private static function isLabel(string $text): bool
    {
        $text = trim($text);

        return $text !== ''
            && AttendanceDecimal::parseNumber($text) === null
            && AttendanceDecimal::parseClockMillihours($text) === null
            && preg_match('/\p{L}/u', $text) === 1;
    }
}
