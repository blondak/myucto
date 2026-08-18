<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use PHPUnit\Framework\TestCase;

final class PayrollSheetSnapshotBuilderTest extends TestCase
{
    public function testMapsRoundedTaxBaseAndFinalApprovedPayout(): void
    {
        $builder = (new \ReflectionClass(PayrollSheetSnapshotBuilder::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PayrollSheetSnapshotBuilder::class, 'personAmounts');
        $amounts = $method->invoke($builder, [
            'payslip_document' => [
                'gross_minor_units' => 100_000,
                'employer_social_minor_units' => 24_800,
            ],
            'statutory' => [
                'status' => 'calculated',
                'social_insurance' => [
                    'capped_assessment_base_minor_units' => 100_000,
                    'employee_contribution_minor_units' => 6_500,
                ],
                'health_insurance' => [
                    'assessment_base_minor_units' => 100_000,
                    'employee_contribution_minor_units' => 4_500,
                    'employer_contribution_minor_units' => 9_000,
                    'employee_minimum_top_up_minor_units' => 0,
                ],
                'income_tax' => [
                    'advance_tax' => [
                        'taxable_income_minor_units' => 100_050,
                        'rounded_tax_base_minor_units' => 100_100,
                        'tax_before_credits_minor_units' => 15_000,
                        'non_refundable_credits_minor_units' => 2_570,
                        'child_credit_minor_units' => 0,
                    ],
                    'withholding_base_minor_units' => 5_000,
                    'claimed_child_credit_minor_units' => 1_267,
                    'applied_child_credit_minor_units' => 0,
                ],
                'net_pay' => [
                    'cash_income_minor_units' => 100_000,
                    'non_cash_income_minor_units' => 0,
                    'advance_tax_minor_units' => 12_430,
                    'tax_bonus_minor_units' => 0,
                    'withholding_tax_minor_units' => 0,
                    'deducted_minor_units' => 1_000,
                ],
            ],
            'enforcement' => [
                'result' => [
                    'status' => 'supported',
                    'total_withheld_minor_units' => 2_000,
                ],
            ],
            'payable_after_enforcement_minor' => 73_570,
        ]);

        self::assertIsArray($amounts);
        self::assertSame(100_100, $amounts['advance_tax_base_minor_units']);
        self::assertSame(5_000, $amounts['withholding_tax_base_minor_units']);
        self::assertSame(1_267, $amounts['child_entitlement_minor_units']);
        self::assertSame(3_000, $amounts['other_deductions_minor_units']);
        self::assertSame(73_570, $amounts['net_payable_minor_units']);
    }
}
