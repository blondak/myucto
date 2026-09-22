<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\MoneyS3\CodebookImporter;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter as PohodaPartners;
use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Normalizace identity partnera společná převodům.
 */
final class PartnerIdentityMatcherTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function icos(): iterable
    {
        yield 'bez vodicí nuly' => ['1234567', '01234567'];
        yield 's mezerami' => ['452 74 649', '45274649'];
        yield 'prázdné' => ['', ''];
        yield 'jen mezery' => ['   ', ''];
    }

    #[DataProvider('icos')]
    public function testIco(string $input, string $expected): void
    {
        self::assertSame($expected, PartnerIdentityMatcher::ico($input));
        // Fasády převodů vracejí totéž.
        self::assertSame($expected, PohodaPartners::ico($input));
        self::assertSame($expected, CodebookImporter::ico($input));
    }

    /** @return iterable<string,array{string,string}> */
    public static function vatIds(): iterable
    {
        yield 'tuzemské' => ['CZ 45274649', 'CZ45274649'];
        yield 'malými' => ['sk2020202020', 'SK2020202020'];
        yield 'řecké' => ['EL123456789', 'EL123456789'];
        yield 'značka skupiny DPH' => ['SKUPINOVE_DPH', ''];
        yield 'bez číslice' => ['DEABCDEF', ''];
        yield 'bez kódu státu' => ['45274649', ''];
        yield 'prázdné' => ['', ''];
    }

    #[DataProvider('vatIds')]
    public function testVatId(string $input, string $expected): void
    {
        self::assertSame($expected, PartnerIdentityMatcher::vatId($input));
        self::assertSame($expected, PohodaPartners::vatId($input));
    }

    public function testDocumentKeyPrefersIco(): void
    {
        self::assertSame('ico:01234567', PartnerIdentityMatcher::documentKey('01234567', 'Firma'));
        self::assertSame('name:syntetická firma s.r.o.', PartnerIdentityMatcher::documentKey('', 'Syntetická FIRMA s.r.o.'));
    }
}
