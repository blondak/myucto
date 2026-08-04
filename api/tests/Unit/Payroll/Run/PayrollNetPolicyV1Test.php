<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Net\PayrollNetPolicyV1;
use PHPUnit\Framework\TestCase;

final class PayrollNetPolicyV1Test extends TestCase
{
    public function testPolicyIdentityIsStableAndSelfVerifying(): void
    {
        $first = PayrollNetPolicyV1::create();
        $second = PayrollNetPolicyV1::create();

        self::assertSame('cz-payroll-net.domain.v1', $first->id);
        self::assertSame($first->canonicalHash, $second->canonicalHash);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $first->canonicalHash,
        );
        self::assertSame([
            'gross_cash_income',
            'correction',
            'employee_social_insurance',
            'employee_health_insurance',
            'advance_income_tax',
            'withholding_income_tax',
            'income_tax_bonus',
            'ordered_deductions',
        ], $first->calculationOrder);
    }
}
