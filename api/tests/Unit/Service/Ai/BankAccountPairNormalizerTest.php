<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use MyInvoice\Service\Ai\BankAccountPairNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankAccountPairNormalizerTest extends TestCase
{
    /** @return iterable<string,array{string,string,string,array{debit:string,credit:string}}> */
    public static function pairs(): iterable
    {
        yield 'příchozí vlastní převod' => ['in', '261', '221', ['debit' => '221', 'credit' => '261']];
        yield 'odchozí vlastní převod' => ['out', '221', '261', ['debit' => '261', 'credit' => '221']];
        yield 'správně orientovaný příjem' => ['in', '221', '662', ['debit' => '221', 'credit' => '662']];
        yield 'správně orientovaný výdaj' => ['out', '518', '221', ['debit' => '518', 'credit' => '221']];
        yield 'bez bankovního účtu' => ['in', '261', '518', ['debit' => '261', 'credit' => '518']];
    }

    #[DataProvider('pairs')]
    public function testOrientsBankAccountToTheSideRequiredByTransactionDirection(
        string $direction,
        string $debit,
        string $credit,
        array $expected,
    ): void {
        self::assertSame($expected, BankAccountPairNormalizer::orient($direction, $debit, $credit));
    }

    public function testRequiresExactlyOneBankSide(): void
    {
        self::assertTrue(BankAccountPairNormalizer::hasExactlyOneBankSide('261', '221'));
        self::assertTrue(BankAccountPairNormalizer::hasExactlyOneBankSide('221', '662'));
        self::assertFalse(BankAccountPairNormalizer::hasExactlyOneBankSide('221', '221001'));
        self::assertFalse(BankAccountPairNormalizer::hasExactlyOneBankSide('261', '662'));
    }
}
