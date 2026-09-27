<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollDeductions;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverDeductionsWriter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use PHPUnit\Framework\TestCase;

/**
 * Exekuce, které předchozí program prokazatelně srážel, převod odliší od nových nebo
 * nejistých: protokol je vypíše zvlášť, aby je účetní aktivovala před prvním během.
 */
final class PohodaPayrollEnforcementWithheldTest extends TestCase
{
    public function testWithheldInLastMonthsOfSourceIsProven(): void
    {
        $record = [
            'withheld_periods' => ['2026-05', '2026-07'],
            'source_last_period' => '2026-08',
            'valid_to' => null,
            'total_minor' => 5_000_000,
            'outstanding_minor' => 1_000_000,
        ];
        self::assertSame('2026-07', PayrollTakeoverDeductionsWriter::withheldRecently($record));
        // Výživné bez stanovené celkové částky.
        self::assertSame('2026-07', PayrollTakeoverDeductionsWriter::withheldRecently(['total_minor' => 0, 'outstanding_minor' => 0] + $record));
    }

    public function testOldPaidOffOrEndedDeductionIsNotProven(): void
    {
        $record = [
            'withheld_periods' => ['2026-03'],
            'source_last_period' => '2026-08',
            'valid_to' => null,
            'total_minor' => 5_000_000,
            'outstanding_minor' => 1_000_000,
        ];
        self::assertNull(PayrollTakeoverDeductionsWriter::withheldRecently($record), 'Naposledy před pěti měsíci.');
        self::assertNull(PayrollTakeoverDeductionsWriter::withheldRecently(['withheld_periods' => ['2026-08'], 'outstanding_minor' => 0] + $record), 'Doplacená.');
        self::assertNull(PayrollTakeoverDeductionsWriter::withheldRecently(['withheld_periods' => ['2026-08'], 'valid_to' => '2026-07-31'] + $record), 'Skončila.');
        self::assertNull(PayrollTakeoverDeductionsWriter::withheldRecently(['withheld_periods' => []] + $record), 'Nesráženo nikdy.');
    }

    public function testReaderCarriesMonthsWithPositiveWithholdingAndLastProcessedMonth(): void
    {
        $dir = sys_get_temp_dir() . '/myucto-enforcement-' . bin2hex(random_bytes(4));
        try {
            $file = SyntheticPohodaPayroll::write($dir);
            $records = PohodaPayrollDeductions::read($file, SyntheticPohodaPayroll::YEAR)['deductions'];
            self::assertNotSame([], $records);
            $withSource = array_values(array_filter($records, static fn (array $r): bool => $r['withheld_periods'] !== []));
            self::assertNotSame([], $withSource);
            self::assertSame(['2026-01'], $withSource[0]['withheld_periods']);
            self::assertSame('2026-02', $withSource[0]['source_last_period']);
            // S začátkem vedení mezd v únoru zpracoval předchozí program naposledy leden;
            // pozdější měsíce exportu jsou rozpracované.
            $fromStart = PohodaPayrollDeductions::read($file, SyntheticPohodaPayroll::YEAR, '2026-02-01')['deductions'];
            self::assertSame('2026-01', $fromStart[0]['source_last_period']);
        } finally {
            foreach (glob($dir . '/*/*') ?: [] as $path) {
                unlink($path);
            }
            foreach (glob($dir . '/*') ?: [] as $path) {
                rmdir($path);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }
}
