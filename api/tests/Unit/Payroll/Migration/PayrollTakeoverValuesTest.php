<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverFormat;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverOpeningMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Hodnoty sdílené vrstvy převzatých mezd bez databáze: řádek počátečních stavů,
 * poznámka převzatého údaje a formát do protokolu.
 */
final class PayrollTakeoverValuesTest extends TestCase
{
    public function testOpeningRowWithoutHealthKeepsTheOrderOfTheTaxSide(): void
    {
        $row = (new PayrollTakeoverOpeningMonth(1, 100, 200, 30, 40, 5, 6, 7, 8))->toRow();

        self::assertSame([
            'month' => 1,
            'social_assessment_base_minor_units' => 100,
            'advance_base_minor_units' => 200,
            'advance_tax_minor_units' => 30,
            'withholding_base_minor_units' => 40,
            'withholding_tax_minor_units' => 5,
            'applied_non_refundable_credits_minor_units' => 6,
            'applied_child_credit_minor_units' => 7,
            'tax_bonus_minor_units' => 8,
            'bonus_qualifying_income_minor_units' => 200,
        ], $row);
    }

    public function testOpeningRowWithHealthPutsItRightAfterSocial(): void
    {
        $row = (new PayrollTakeoverOpeningMonth(2, 100, 200, 30, 0, 0, 0, 0, 0, 110, 9, 18, 1))->toRow();

        self::assertSame([
            'month',
            'social_assessment_base_minor_units',
            'health_assessment_base_minor_units',
            'health_employee_contribution_minor_units',
            'health_employer_contribution_minor_units',
            'health_minimum_top_up_minor_units',
            'advance_base_minor_units',
            'advance_tax_minor_units',
            'withholding_base_minor_units',
            'withholding_tax_minor_units',
            'applied_non_refundable_credits_minor_units',
            'applied_child_credit_minor_units',
            'tax_bonus_minor_units',
            'bonus_qualifying_income_minor_units',
        ], array_keys($row));
        self::assertSame([110, 9, 18, 1], [
            $row['health_assessment_base_minor_units'],
            $row['health_employee_contribution_minor_units'],
            $row['health_employer_contribution_minor_units'],
            $row['health_minimum_top_up_minor_units'],
        ]);
    }

    public function testPolicyNoteNamesTheSource(): void
    {
        self::assertSame('Převzato z PREMIER: vztah skončil.', (new PayrollTakeoverPolicy('premier', 'PREMIER'))->note('vztah skončil.'));
    }

    public function testFormat(): void
    {
        self::assertSame('5. 3. 2026', PayrollTakeoverFormat::czechDate('2026-03-05'));
        self::assertSame('3/2026', PayrollTakeoverFormat::czechPeriod('2026-03'));
        self::assertSame('7,5', PayrollTakeoverFormat::decimal(7.5));
        self::assertSame('16', PayrollTakeoverFormat::decimal(16.0));
        self::assertNull(PayrollTakeoverFormat::text('  '));
        self::assertSame('x', PayrollTakeoverFormat::text(' x '));
    }
}
