<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\SupportMatrix;
use PHPUnit\Framework\TestCase;

final class SupportMatrixTest extends TestCase
{
    public function testMatrixSeparatesTargetSupportFromRuntimeAvailability(): void
    {
        $matrix = (new SupportMatrix())->all();
        self::assertSame(SupportMatrix::VERSION, $matrix['version']);
        self::assertSame([2024, 2025, 2026], $matrix['supported_years']);

        $features = array_column($matrix['features'], null, 'key');
        self::assertTrue($features['module_shell']['available']);
        self::assertTrue($features['activation']['available']);
        self::assertFalse($features['payroll_runs']['available']);
        self::assertSame('supported', $features['payroll_runs']['status']);
        self::assertSame('not_supported', $features['direct_submission']['status']);
    }

    public function testEveryCapabilityHasKnownStatusAndEpic(): void
    {
        $matrix = (new SupportMatrix())->all();
        foreach (array_merge($matrix['employment_types'], $matrix['features']) as $capability) {
            self::assertContains($capability['status'], ['supported', 'manual_review', 'not_supported']);
            self::assertMatchesRegularExpression('/^MZ-[0-9]{2}$/', $capability['min_epic']);
            self::assertIsBool($capability['available']);
        }
    }
}
