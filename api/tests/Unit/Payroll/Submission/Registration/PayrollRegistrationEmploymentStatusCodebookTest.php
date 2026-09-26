<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEmploymentStatusCodebook;
use PHPUnit\Framework\TestCase;

/**
 * Nabídka postavení v zaměstnání jde do formuláře profilu A1 jako JSON.
 * Kód z ní musí být řetězec, jinak formulář uložený kód „1111" v nabídce
 * nenajde a ukáže ho jako „kód mimo číselník" vedle téže platné volby.
 */
final class PayrollRegistrationEmploymentStatusCodebookTest extends TestCase
{
    public function testOptionCodesAreStringsInJson(): void
    {
        $options = PayrollRegistrationEmploymentStatusCodebook::options();

        self::assertNotSame([], $options);
        foreach ($options as $option) {
            self::assertIsString($option['code']);
        }
        self::assertStringContainsString(
            '"code":"1111"',
            json_encode($options, JSON_THROW_ON_ERROR),
        );
    }
}
