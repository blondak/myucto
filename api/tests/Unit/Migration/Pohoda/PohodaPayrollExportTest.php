<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use PHPUnit\Framework\TestCase;

/**
 * Přehled exportu s mzdami: agenda jen se mzdami (například z programu PAMICA) se v náhledu
 * průvodce ukáže a ZIP s ní projde rozbalením.
 */
final class PohodaPayrollExportTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_payroll_exp_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testPayrollOnlyAgendaIsListed(): void
    {
        $zipPath = $this->tmp . '/mzdy.zip';
        $file = SyntheticPohodaPayroll::write($this->tmp . '/src');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($file, SyntheticPohodaPayroll::ICO . '_' . SyntheticPohodaPayroll::YEAR . '/91_mzdy.xml');
        $zip->close();

        $target = $this->tmp . '/out';
        PohodaExport::extractArchive($zipPath, $target);
        $overview = PohodaExport::overview($target);

        self::assertCount(1, $overview);
        $agenda = $overview[0];
        self::assertSame(SyntheticPohodaPayroll::ICO, $agenda['ico']);
        self::assertSame(SyntheticPohodaPayroll::YEAR, $agenda['year']);
        self::assertFalse($agenda['has_accounting']);
        self::assertTrue($agenda['has_payroll']);
        self::assertSame(['employees' => 2, 'months' => 2, 'payslips' => 4, 'first' => '2026-01', 'last' => '2026-02'],
            array_intersect_key($agenda['payroll'], array_flip(['employees', 'months', 'payslips', 'first', 'last'])));
        self::assertSame(0, $agenda['counts']['journal']);
    }

    /**
     * Export uprostřed měsíce nese i mzdy měsíce, který v předchozím programu ještě
     * běží. Takový měsíc se nesmí počítat mezi zpracované: převod by ho převzal
     * a začátek vedení mezd by skočil až za něj, takže by ho nikdo nespočítal.
     */
    public function testMonthNotEndedOnExportDayIsOpenNotProcessed(): void
    {
        $file = SyntheticPohodaPayroll::write($this->tmp);
        $xml = (string) file_get_contents($file);
        file_put_contents($file, str_replace('<mdbExport ', '<mdbExport created="2026-02-15T10:00:00" ', $xml));

        $summary = PohodaExport::payrollSummary($file, (int) SyntheticPohodaPayroll::YEAR);
        self::assertSame(1, $summary['months']);
        self::assertSame('2026-01', $summary['last']);
        self::assertSame('2026-01', $summary['last_overall']);
        self::assertSame('2026-02-15', $summary['exported_on']);
        self::assertSame(['2026-02' => 2], $summary['open']);

        $converter = PohodaPayrollConverter::read($file);
        self::assertSame(['2026-01'], $converter->closedPeriods(2026));
        self::assertSame(['2026-02' => 2], $converter->openPeriods(2026));
    }

    public function testOpenPeriodRule(): void
    {
        self::assertTrue(PohodaPayrollConverter::openPeriod('2026-09', '2026-09-26'));
        self::assertTrue(PohodaPayrollConverter::openPeriod('2026-10', '2026-09-26'));
        self::assertTrue(PohodaPayrollConverter::openPeriod('2026-09', '2026-09-30'));
        self::assertFalse(PohodaPayrollConverter::openPeriod('2026-08', '2026-09-26'));
        self::assertFalse(PohodaPayrollConverter::openPeriod('2026-09', '2026-10-01'));
        self::assertFalse(PohodaPayrollConverter::openPeriod('2026-09', null), 'Bez data exportu se nic nevynechává.');
        self::assertSame('2026-09-26', PohodaPayrollConverter::exportDate('2026-09-26T14:03:11'));
        self::assertNull(PohodaPayrollConverter::exportDate(''));
    }

    public function testPayrollSummaryCountsOnlyAgendaYear(): void
    {
        $file = SyntheticPohodaPayroll::write($this->tmp);
        $summary = PohodaExport::payrollSummary($file, 2025);
        self::assertSame(0, $summary['months']);
        self::assertSame(2, $summary['employees']);
        self::assertSame(SyntheticPohodaPayroll::ICO, $summary['ico']);
    }
}
