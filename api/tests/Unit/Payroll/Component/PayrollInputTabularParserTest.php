<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollInputTabularParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class PayrollInputTabularParserTest extends TestCase
{
    public function testCsvSupportsSemicolonAndKeepsRowErrorsSeparate(): void
    {
        $csv = implode("\n", [
            'employment_id;employment_code;component_code;amount_minor;external_id',
            '10;HPP-1;BONUS;25000;ext-1',
            '10;HPP-1;BONUS',
        ]);

        $parsed = (new PayrollInputTabularParser())->parse('csv', $csv);

        self::assertCount(1, $parsed['rows']);
        self::assertCount(1, $parsed['errors']);
        self::assertSame(2, $parsed['rows'][0]['row_number']);
        self::assertSame('column_count', $parsed['errors'][0]['error_code']);
    }

    public function testXlsxReadsStaticValues(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['employment_id', 'employment_code', 'component_code', 'amount_minor', 'external_id'],
            [10, 'HPP-1', 'BONUS', 25000, 'ext-1'],
        ]);
        $content = $this->xlsx($spreadsheet);

        $parsed = (new PayrollInputTabularParser())->parse('xlsx', $content);

        self::assertCount(1, $parsed['rows']);
        self::assertSame('25000', $parsed['rows'][0]['amount_minor']);
    }

    public function testXlsxRejectsFormulaInsteadOfEvaluatingIt(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['employment_id', 'employment_code', 'component_code', 'amount_minor', 'external_id'],
            [10, 'HPP-1', 'BONUS', '=10000+15000', 'ext-1'],
        ]);
        $content = $this->xlsx($spreadsheet);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vzorec');
        (new PayrollInputTabularParser())->parse('xlsx', $content);
    }

    private function xlsx(Spreadsheet $spreadsheet): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'payroll-input-test-');
        if ($tmp === false) {
            throw new \RuntimeException('Nelze vytvořit syntetický XLSX.');
        }
        try {
            (new Xlsx($spreadsheet))->save($tmp);
            $content = file_get_contents($tmp);
            if ($content === false) {
                throw new \RuntimeException('Syntetický XLSX nelze načíst.');
            }
            return $content;
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($tmp);
        }
    }
}
