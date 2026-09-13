<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceCell;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceDecimal;
use MyInvoice\Service\Payroll\Import\Attendance\AttendancePersonAggregator;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRuleSuggester;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheet;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSheetAnalyzer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceWorkbookReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class AttendanceWorkbookReaderTest extends TestCase
{
    public function testReadsEverySheetOfTheWorkbook(): void
    {
        $sheets = $this->read('provoz.xlsx', AttendanceFixture::operationsWorkbook());

        self::assertSame(['vstup', 'výpočet'], array_map(static fn (AttendanceSheet $s): string => $s->name, $sheets));
        self::assertSame('provoz.xlsx#výpočet', $sheets[1]->id());
        self::assertSame('provoz.xlsx!výpočet!C2', $sheets[1]->source(2, 3));
    }

    /**
     * Uložená hodnota vzorce je 16 800, přepočet `=B2*100` by dal 1 600.
     * Import musí vzít to, co viděla účetní v sešitu.
     */
    public function testFormulaUsesStoredValueWithoutRecalculation(): void
    {
        $cell = $this->read('provoz.xlsx', AttendanceFixture::operationsWorkbook())[1]->cell(2, 3);

        self::assertSame(AttendanceCell::NUMBER, $cell->kind);
        self::assertTrue($cell->formula);
        self::assertSame(16800.0, $cell->number);
    }

    public function testFormulaErrorIsMissingValueNeverZero(): void
    {
        $cell = $this->read('provoz.xlsx', AttendanceFixture::operationsWorkbook())[1]->cell(3, 4);

        self::assertSame(AttendanceCell::ERROR, $cell->kind);
        self::assertSame('#REF!', $cell->text);
        self::assertArrayHasKey('error', AttendancePersonAggregator::amountMinor($cell));
        self::assertArrayHasKey('error', AttendancePersonAggregator::millihours($cell, 'hours'));
    }

    public function testFormulaWithoutStoredValueIsMissingValue(): void
    {
        $content = AttendanceFixture::xlsx([
            'List1' => ['rows' => [1 => ['A' => 'Jméno', 'B' => 'Odměna'], 2 => ['A' => 'Jana Testovací', 'B' => '=1+1']]],
        ]);
        $cell = $this->read('a.xlsx', $content)[0]->cell(2, 2);

        self::assertSame(AttendanceCell::ERROR, $cell->kind);
        self::assertSame(AttendanceCell::NO_CACHED_VALUE, $cell->text);
    }

    public function testEmptyCellIsNotZero(): void
    {
        $sheet = $this->read('provoz.xlsx', AttendanceFixture::operationsWorkbook())[0];

        self::assertSame(AttendanceCell::EMPTY, $sheet->cell(3, 4)->kind);
        self::assertArrayNotHasKey(4, $sheet->rows[3]);
    }

    public function testDurationOverTwentyFourHoursAndDateTimeFormat(): void
    {
        $sheet = $this->read('provoz.xlsx', AttendanceFixture::operationsWorkbook())[0];
        $worked = $sheet->cell(3, 2);
        $overtime = $sheet->cell(3, 5);

        self::assertTrue($worked->hasDurationFormat());
        self::assertSame(168000, AttendanceDecimal::durationMillihours((float) $worked->number));
        self::assertSame('168:00', $worked->display());
        self::assertTrue($overtime->hasDurationFormat(), 'Datum s časem v buňce s trváním je taky trvání.');
        self::assertSame(['value' => 36000], AttendancePersonAggregator::millihours($overtime, 'excel_duration'));
    }

    public function testUnitIsSuggestedFromNumberFormat(): void
    {
        $sheets = $this->read('provoz.xlsx', AttendanceFixture::operationsWorkbook());
        $analyzer = new AttendanceSheetAnalyzer(new AttendanceRuleSuggester());

        self::assertSame('excel_duration', $analyzer->suggestHoursUnit($sheets[0], $analyzer->analyze($sheets[0]), 3));
        self::assertSame('hours', $analyzer->suggestHoursUnit($sheets[1], $analyzer->analyze($sheets[1]), 2));
    }

    public function testShiftedHeaderIsFound(): void
    {
        $content = AttendanceFixture::xlsx([
            'List1' => ['rows' => [
                1 => ['A' => 'Docházka'],
                2 => ['A' => 'Období 06/2026'],
                3 => ['A' => 'Zaměstnanec', 'B' => 'Dovolená', 'C' => 'Nemoc'],
                4 => ['A' => 'Jana Testovací', 'B' => 8, 'C' => 4],
            ]],
        ]);
        $sheet = $this->read('a.xlsx', $content)[0];
        $layout = (new AttendanceSheetAnalyzer(new AttendanceRuleSuggester()))->analyze($sheet);

        self::assertSame(3, $layout->headerRow);
        self::assertSame(4, $layout->dataStartRow);
        self::assertSame(1, $layout->suggestedPersonColumn);
    }

    public function testRepeatedHeaderRowIsSkipped(): void
    {
        $sheet = $this->read('provoz.xlsx', AttendanceFixture::operationsWorkbook())[0];
        $layout = (new AttendanceSheetAnalyzer(new AttendanceRuleSuggester()))->analyze($sheet);

        self::assertSame(1, $layout->headerRow);
        self::assertSame(2, $layout->repeatedHeaderRow);
        self::assertSame(3, $layout->dataStartRow);
    }

    public function testPersonColumnFallsBackToColumnWithNames(): void
    {
        $content = AttendanceFixture::xlsx([
            'List1' => ['rows' => [
                1 => ['A' => 'Kód', 'B' => 'Kdo', 'C' => 'Dovolená'],
                2 => ['A' => 'X1', 'B' => 'Jana Testovací', 'C' => 8],
                3 => ['A' => 'X2', 'B' => 'Petr Zkušební', 'C' => 4],
            ]],
        ]);
        $sheet = $this->read('a.xlsx', $content)[0];

        self::assertSame(2, (new AttendanceSheetAnalyzer(new AttendanceRuleSuggester()))->analyze($sheet)->suggestedPersonColumn);
    }

    public function testCsvInWindows1250WithCzechNumbersAndBooleans(): void
    {
        $sheet = $this->read('mzdy.csv', AttendanceFixture::payrollCsv())[0];

        self::assertSame('CSV', $sheet->name);
        self::assertSame('Testovací Jana', $sheet->cell(2, 1)->textValue());
        self::assertSame('71 875,00 Kč', $sheet->cell(2, 6)->textValue());
        self::assertSame(['value' => 7187500], AttendancePersonAggregator::amountMinor($sheet->cell(2, 6)));
        self::assertSame(['value' => 4800000], AttendancePersonAggregator::amountMinor($sheet->cell(3, 6)));
        self::assertSame(['value' => 168000], AttendancePersonAggregator::millihours($sheet->cell(2, 5), 'hours'));
        self::assertSame(AttendanceCell::BOOL, $sheet->cell(2, 8)->kind);
    }

    /**
     * mbstring Windows-1250 nezná a `mb_convert_encoding` by skončil ValueError
     * (pád požadavku 500). Bajt, který Windows-1250 nedefinuje, musí dát
     * srozumitelné odmítnutí, ne pád.
     */
    public function testCsvWithBytesOutsideWindows1250IsRejectedWithMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Windows-1250');
        $this->read('a.csv', "Jm\x81no;Dovolen\xE1\nJana;8\n");
    }

    public function testSemicolonCsvInUtf8(): void
    {
        $sheet = $this->read('a.csv', "\xEF\xBB\xBFJméno;Dovolená\nJana Testovací;\"8,5\"\n")[0];

        self::assertSame(['value' => 8500], AttendancePersonAggregator::millihours($sheet->cell(2, 2), 'hours'));
    }

    public function testWorkbookWithMacroIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'attendance-macro-');
        self::assertIsString($path);
        try {
            file_put_contents($path, AttendanceFixture::mainWorkbook());
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            $zip->addFromString('xl/vbaProject.bin', 'macro');
            $zip->close();
            $content = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('makra');
        $this->read('podklady.xlsx', $content);
    }

    public function testNonWorkbookContentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->read('podklady.xlsx', 'není to sešit');
    }

    /** @return list<AttendanceSheet> */
    private function read(string $name, string $content): array
    {
        return (new AttendanceWorkbookReader())->read($name, 0, strtolower(pathinfo($name, PATHINFO_EXTENSION)), $content);
    }
}
