<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationPostalCode;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationTaxResidencyRule;
use PHPUnit\Framework\TestCase;

final class PayrollRegistrationPostalCodeTest extends TestCase
{
    public function testSpacesAreRemovedIncludingNonBreakingOnes(): void
    {
        self::assertSame('11000', PayrollRegistrationPostalCode::normalize('110 00'));
        self::assertSame("11000", PayrollRegistrationPostalCode::normalize("110\u{00A0}00"));
        self::assertSame('11000', PayrollRegistrationPostalCode::normalize(" 110\u{202F}00 "));
    }

    public function testCzechPostalCodeMustHaveFiveDigits(): void
    {
        self::assertSame('60200', PayrollRegistrationPostalCode::valid('602 00', 'CZ'));
        self::assertNull(PayrollRegistrationPostalCode::valid('6020', 'CZ'));
        self::assertNull(PayrollRegistrationPostalCode::valid('602 0A', 'CZ'));
        self::assertNull(PayrollRegistrationPostalCode::valid('   ', 'CZ'));
    }

    public function testForeignPostalCodeKeepsOnlyWhatTheSchemaAllows(): void
    {
        self::assertSame('SW1A1AA', PayrollRegistrationPostalCode::valid('SW1A 1AA', 'GB'));
        self::assertSame('1010', PayrollRegistrationPostalCode::valid('1010', 'AT'));
        self::assertNull(PayrollRegistrationPostalCode::valid('12<45', 'US'));
    }

    public function testTaxResidencyAddressIsRequiredOutsideCzechiaOnly(): void
    {
        self::assertFalse(PayrollRegistrationTaxResidencyRule::requiresResidenceAddress('CZ'));
        self::assertFalse(PayrollRegistrationTaxResidencyRule::requiresResidenceAddress(''));
        self::assertFalse(PayrollRegistrationTaxResidencyRule::requiresResidenceAddress(null));
        self::assertTrue(PayrollRegistrationTaxResidencyRule::requiresResidenceAddress('SK'));
    }
}
