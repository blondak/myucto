<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment;

use MyInvoice\Service\Payment\IbanValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IbanValidatorTest extends TestCase
{
    private IbanValidator $v;

    protected function setUp(): void
    {
        $this->v = new IbanValidator();
    }

    #[DataProvider('validIbans')]
    public function testValidIbansPass(string $iban): void
    {
        self::assertTrue($this->v->isValid($iban));
    }

    /** @return list<array{0:string}> */
    public static function validIbans(): array
    {
        return [
            // CZ1801000000001000000005 = mod-11 ověřený testovací účet 1000000005/0100 (viz AGENTS.md).
            ['CZ1801000000001000000005'],
            ['DE89370400440532013000'],
            ['FR1420041010050500013M02606'],
            ['SK3112000000198742637541'],
            // Mezery jsou v bankovních formulářích běžné a musí projít po normalizaci.
            ['CZ18 0100 0000 0010 0000 0005'],
        ];
    }

    #[DataProvider('invalidIbans')]
    public function testInvalidIbansFail(string $iban): void
    {
        self::assertFalse($this->v->isValid($iban));
    }

    /** @return list<array{0:string}> */
    public static function invalidIbans(): array
    {
        return [
            ['CZ1801000000001000000006'], // špatný kontrolní součet (o 1 jinde)
            ['CZ0000000000000000000000'],
            [''],
            ['not an iban'],
            ['CZ18'], // příliš krátké
        ];
    }

    public function testValidBicFormats(): void
    {
        self::assertTrue($this->v->isValidBic('GIBACZPX'));      // 8 znaků
        self::assertTrue($this->v->isValidBic('COBADEFFXXX'));   // 11 znaků s pobočkou
        self::assertTrue($this->v->isValidBic('  gibaczpx  ')); // trim + case-insensitive
    }

    public function testInvalidBicFormats(): void
    {
        self::assertFalse($this->v->isValidBic('not-a-bic'));
        self::assertFalse($this->v->isValidBic('GIBA'));
        self::assertFalse($this->v->isValidBic(''));
    }

    public function testNormalizeStripsSpacesAndUppercases(): void
    {
        self::assertSame('CZ1801000000001000000005', $this->v->normalize('cz18 0100 0000 0010 0000 0005'));
    }
}
