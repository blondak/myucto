<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payment;

use MyInvoice\Service\Payment\CzechBankAccountValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CzechBankAccountValidatorTest extends TestCase
{
    private CzechBankAccountValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CzechBankAccountValidator();
    }

    public function testParsesAndCanonicalizesValidAccount(): void
    {
        self::assertSame([
            'canonical' => '19-1000000005/0100',
            'account_number' => '19-1000000005',
            'bank_code' => '0100',
            'prefix' => '19',
            'base' => '1000000005',
        ], $this->validator->parse(
            ' 000019 - 1000000005 / 0100 ',
        ));
        self::assertSame(
            '1000000005/0100',
            $this->validator->normalize('000000-1000000005/0100'),
        );
    }

    #[DataProvider('invalidAccountProvider')]
    public function testRejectsInvalidAccount(
        string $account,
        string $message,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->validator->normalize($account);
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidAccountProvider(): iterable
    {
        yield 'formát' => [
            '1000000005-0100',
            'Český účet musí mít formát [předčíslí-]číslo/kód banky.',
        ];
        yield 'modulo 11' => [
            '1000000006/0100',
            'Český bankovní účet neprošel kontrolou modulo 11.',
        ];
        yield 'nulový účet' => [
            '0000000000/0100',
            'Číslo bankovního účtu nesmí být nulové.',
        ];
    }
}
