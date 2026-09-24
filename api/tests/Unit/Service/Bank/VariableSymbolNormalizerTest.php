<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Normalizace VS — jedno místo pravdy pro QR/platbu (forPayment) i párování
 * (forMatching). Klíčový případ issue #58: číslo dokladu s pomlčkou („2026-00001")
 * musí dát validní číselný VS, jinak ho banka odmítne a platba se nespáruje.
 */
final class VariableSymbolNormalizerTest extends TestCase
{
    /** @return array<string, array{0:string,1:string}> */
    public static function paymentCases(): array
    {
        return [
            'čistě číselný projde beze změny' => ['202604001', '202604001'],
            'pomlčka z čísla dokladu se odstraní' => ['2026-00001', '202600001'],
            'lomítko se odstraní' => ['F-2026/0042', '20260042'],
            'mezery se odstraní' => ['2026 0001', '20260001'],
            'vodicí nuly zůstávají (platné pro banku)' => ['00012345', '00012345'],
            'delší než 10 číslic se ořízne na 10' => ['1234567890123', '1234567890'],
            'přesně 10 číslic projde' => ['1234567890', '1234567890'],
            'nečíselný vstup → prázdno' => ['ABC-XYZ', ''],
            'prázdný vstup → prázdno' => ['', ''],
        ];
    }

    #[DataProvider('paymentCases')]
    public function testForPayment(string $input, string $expected): void
    {
        self::assertSame($expected, VariableSymbolNormalizer::forPayment($input));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function matchingCases(): array
    {
        return [
            'čistě číselný' => ['202604001', '202604001'],
            'pomlčka pryč' => ['2026-00001', '202600001'],
            'vodicí nuly se ořežou' => ['00012345', '12345'],
            'kombinace nečíselných znaků + vodicí nuly' => ['PF-0000-0042', '42'],
            'samé nuly → zachová číslice (VS „0" nezmizí)' => ['000', '000'],
            'nečíselný vstup → prázdno' => ['ABC', ''],
            'prázdný vstup → prázdno' => ['', ''],
        ];
    }

    #[DataProvider('matchingCases')]
    public function testForMatching(string $input, string $expected): void
    {
        self::assertSame($expected, VariableSymbolNormalizer::forMatching($input));
    }

    public function testDigitsKeepsLeadingZerosAndLength(): void
    {
        self::assertSame('00012345', VariableSymbolNormalizer::digits('00-0123-45'));
        self::assertSame('', VariableSymbolNormalizer::digits('abc'));
    }

    /** @return array<string, array{string, ?string, ?string}> */
    public static function importedPaymentOverrideCases(): array
    {
        return [
            'VS proformy u daňového dokladu' => ['20940012', '120940077', '120940077'],
            'VS shodný s číslem se neukládá' => ['20940012', '20940012', null],
            'VS shodný s číslicemi čísla se neukládá' => ['2094-0012', '20940012', null],
            'VS s mezerami se normalizuje' => ['20940012', '1209 40077', '120940077'],
            'bez VS nic' => ['20940012', null, null],
            'prázdný VS nic' => ['20940012', '', null],
            'VS delší než 10 číslic se neukládá' => ['20940012', '12345678901', null],
            'číslo bez číslic, VS se uloží' => ['FV-A', '12345', '12345'],
        ];
    }

    #[DataProvider('importedPaymentOverrideCases')]
    public function testImportedPaymentOverride(string $number, ?string $vs, ?string $expected): void
    {
        self::assertSame($expected, VariableSymbolNormalizer::importedPaymentOverride($number, $vs));
    }

    public function testDashedAndNumericShareMatchingKey(): void
    {
        // Jádro #58: faktura uložená jako „2026-00001" a banka hlásí „202600001" —
        // forMatching musí dát oběma stejný klíč, aby párování sedlo.
        self::assertSame(
            VariableSymbolNormalizer::forMatching('2026-00001'),
            VariableSymbolNormalizer::forMatching('202600001'),
        );
    }
}
