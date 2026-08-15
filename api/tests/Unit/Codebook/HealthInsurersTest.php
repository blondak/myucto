<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Codebook;

use MyInvoice\Service\Codebook\HealthInsurers;
use PHPUnit\Framework\TestCase;

final class HealthInsurersTest extends TestCase
{
    public function testContainsAllSevenCzechInsurers(): void
    {
        self::assertSame(
            ['111', '201', '205', '207', '209', '211', '213'],
            HealthInsurers::codes(),
        );
        self::assertCount(7, HealthInsurers::all());
        self::assertSame(
            HealthInsurers::codes(),
            array_map(strval(...), array_keys(HealthInsurers::all())),
        );
        self::assertSame('Všeobecná zdravotní pojišťovna ČR (VZP)', HealthInsurers::name('111'));
        self::assertSame('Revírní bratrská pokladna (RBP)', HealthInsurers::name('213'));
    }

    public function testRejectsCodesThatOnlyLookLikeInsurers(): void
    {
        self::assertTrue(HealthInsurers::isValid('205'));
        self::assertFalse(HealthInsurers::isValid('999'));
        self::assertFalse(HealthInsurers::isValid(''));
        self::assertFalse(HealthInsurers::isValid('11'));
        self::assertFalse(HealthInsurers::isValid('0111'));
        self::assertNull(HealthInsurers::name('999'));
    }

    public function testInvalidCodeMessageNamesTheCodeAndTheOptions(): void
    {
        $message = HealthInsurers::invalidCodeMessage('999');

        self::assertStringContainsString('999', $message);
        self::assertStringContainsString('111 VZP', $message);
        self::assertStringContainsString('213 RBP', $message);
    }

    public function testEmptyCodeIsDescribedInWords(): void
    {
        self::assertStringContainsString('(prázdný)', HealthInsurers::invalidCodeMessage(''));
    }
}
