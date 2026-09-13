<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Export\PayrollInputExportFormatter;
use MyInvoice\Service\Payroll\Export\PayrollInputXlsxExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PHPUnit\Framework\TestCase;

/**
 * Sešit mzdových vstupů bez databáze: struktura, skryté klíče pro zpětný
 * import, zámek a neutralizace vzorců.
 */
final class PayrollInputXlsxExporterTest extends TestCase
{
    public function testWorkbookLayoutKeysAndTotals(): void
    {
        $book = $this->export([
            $this->row(11, 'Syntetická Alfa', 'SYN-1', 150_000, 7_500),
            $this->row(12, 'Syntetický Beta', 'SYN-2', 25_050, null),
        ]);
        $sheet = $book->getSheetByName(PayrollInputXlsxExporter::SHEET_INPUTS);
        self::assertNotNull($sheet);
        self::assertSame(0, $book->getActiveSheetIndex());

        foreach (PayrollInputXlsxExporter::HEADERS as $index => $header) {
            self::assertSame($header, $sheet->getCell([$index + 1, 1])->getValue());
        }
        self::assertSame('A2', $sheet->getFreezePane());
        self::assertSame('A1:N3', $sheet->getAutoFilter()->getRange());

        self::assertSame(11, (int) $sheet->getCell('M2')->getValue());
        self::assertSame(4, (int) $sheet->getCell('N2')->getValue());
        self::assertFalse($sheet->getColumnDimension('M')->getVisible());
        self::assertFalse($sheet->getColumnDimension('N')->getVisible());

        self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('I2')->getDataType());
        self::assertEqualsWithDelta(1500.0, $sheet->getCell('I2')->getValue(), 0.0001);
        self::assertEqualsWithDelta(7.5, $sheet->getCell('G2')->getValue(), 0.0001);
        self::assertEqualsWithDelta(200.0, $sheet->getCell('H2')->getValue(), 0.0001);
        self::assertNull($sheet->getCell('G3')->getValue(), 'Bez množství zůstává buňka prázdná, ne nula.');
        self::assertSame('#,##0.00', $sheet->getStyle('I2')->getNumberFormat()->getFormatCode());

        self::assertNull($sheet->getCell('M4')->getValue(), 'Mezi daty a součtem je prázdný řádek.');
        self::assertSame('Celkem (2 vstupů)', $sheet->getCell('A5')->getValue());
        self::assertEqualsWithDelta(1750.5, $sheet->getCell('I5')->getValue(), 0.0001);
        self::assertNull($sheet->getCell('M5')->getValue(), 'Součtový řádek nemá row_key, import ho přeskočí.');

        self::assertTrue((bool) $sheet->getProtection()->getSheet());
        self::assertSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('I2')->getProtection()->getLocked());
        self::assertSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('G2')->getProtection()->getLocked());
        self::assertNotSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('M2')->getProtection()->getLocked());

        $info = $book->getSheetByName(PayrollInputXlsxExporter::SHEET_INFO);
        self::assertNotNull($info);
        $pairs = [];
        foreach ($info->toArray(null, false, false) as $line) {
            $pairs[(string) $line[0]] = $line[1];
        }
        self::assertSame('Syntetická firma s.r.o.', $pairs['Firma']);
        self::assertSame('06/2026', $pairs['Období']);
        self::assertSame('Alfa', $pairs['Filtr: Hledaný text']);
        self::assertSame(2, (int) $pairs['Počet vstupů']);
        self::assertEqualsWithDelta(1750.5, (float) $pairs['Součet částek Kč'], 0.0001);
    }

    public function testFormulaLikeTextStaysLiteralText(): void
    {
        $book = $this->export([
            $this->row(21, '=HYPERLINK("http://example.invalid","x")', '+SYN-3', 1_000, null),
            $this->row(22, 'Syntetická osoba', 'SYN-4', 1_000, null),
        ]);
        $sheet = $book->getSheetByName(PayrollInputXlsxExporter::SHEET_INPUTS);
        self::assertNotNull($sheet);

        foreach (['A2' => '+SYN-3', 'B2' => '=HYPERLINK("http://example.invalid","x")'] as $cell => $value) {
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), $cell);
            self::assertSame($value, $sheet->getCell($cell)->getValue(), 'Obsah zůstává doslova kvůli zpětnému importu.');
            self::assertTrue($sheet->getStyle($cell)->getQuotePrefix(), $cell);
        }
        self::assertFalse($sheet->getStyle('B3')->getQuotePrefix());
    }

    public function testEmptyFilterStillProducesAValidWorkbook(): void
    {
        $book = $this->export([]);
        $sheet = $book->getSheetByName(PayrollInputXlsxExporter::SHEET_INPUTS);
        self::assertNotNull($sheet);
        self::assertSame('Celkem (0 vstupů)', $sheet->getCell('A3')->getValue());
        self::assertSame('A1:N1', $sheet->getAutoFilter()->getRange());
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function export(array $rows): Spreadsheet
    {
        $out = (new PayrollInputXlsxExporter())->export([
            'entity' => ['name' => 'Syntetická firma s.r.o.', 'ico' => '00000019', 'address' => 'Syntetická 1, 100 00 Praha'],
            'period_start' => '2026-06-01',
            'period_label' => '06/2026',
            'filter_lines' => [['label' => 'Hledaný text', 'value' => 'Alfa']],
            'row_count' => count($rows),
            'amount_total_minor' => 0,
            'exported_at' => '13.09.2026 10:00',
            'filename_xlsx' => 'mzdove-vstupy-2026-06.xlsx',
        ], $rows);
        self::assertSame('mzdove-vstupy-2026-06.xlsx', $out['filename']);
        self::assertSame(PayrollInputXlsxExporter::MIME, $out['mime']);
        self::assertSame(count($rows), $out['row_count']);

        $file = tempnam(sys_get_temp_dir(), 'payinp_test_') . '.xlsx';
        file_put_contents($file, $out['bytes']);
        try {
            return IOFactory::load($file);
        } finally {
            @unlink($file);
        }
    }

    /** @return array<string,mixed> */
    private function row(int $id, string $name, string $code, int $amountMinor, ?int $milli): array
    {
        return PayrollInputExportFormatter::row([
            'id' => $id,
            'employee_id' => $id,
            'row_version' => 4,
            'amount_minor' => $amountMinor,
            'quantity_milliunits' => $milli,
            'status' => 'draft',
            'source_kind' => 'manual',
            'import_id' => null,
            'employee_name' => $name,
            'employment_code' => $code,
            'relation_type' => 'employment',
            'component_code' => 'SYN_BONUS',
            'component_name' => 'Syntetická odměna',
            'component_kind' => 'bonus',
        ]);
    }
}
