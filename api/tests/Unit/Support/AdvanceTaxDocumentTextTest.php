<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Support\AdvanceTaxDocumentText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SSOT pro otázku „je to daňový doklad k platbě/záloze (DDKP, § 28 ZDPH)?".
 *
 * Zavírá regresi: přijatá faktura od operátora má v hlavičce „Daňový doklad" a
 * dostala druh `tax_document` — zaúčtovala by se 343/314 a vypadla z nákladů,
 * ze závazků i z příkazu k úhradě. Samotné „daňový doklad" DDKP NEZNAMENÁ;
 * rozhoduje kvalifikátor („k přijaté platbě", „k záloze", …).
 */
final class AdvanceTaxDocumentTextTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function ordinaryInvoiceTitles(): array
    {
        return [
            'holý nadpis'              => ['Daňový doklad'],
            'bez diakritiky'           => ['DANOVY DOKLAD'],
            'verzálky s diakritikou'   => ['DAŇOVÝ DOKLAD'],
            'faktura — daňový doklad'  => ['Faktura — daňový doklad'],
            'faktura/daňový doklad'    => ['Faktura / daňový doklad č. 4402512345'],
            'daňový doklad č.'         => ['Daňový doklad č. 2026000123'],
            'k objednávce'             => ['Daňový doklad k objednávce 55123'],
            'k faktuře'                => ['Daňový doklad k faktuře 2026000123'],
            'běžná položka'            => ['Základní služby za období 06/2026'],
            'prázdný text'             => [''],
        ];
    }

    #[DataProvider('ordinaryInvoiceTitles')]
    public function testOrdinaryInvoiceTextIsNotAdvanceTaxDocument(string $text): void
    {
        self::assertFalse(
            AdvanceTaxDocumentText::indicatesAdvanceTaxDocument($text),
            "„{$text}" . '" je běžná faktura, ne daňový doklad k platbě',
        );
    }

    /** @return array<string, array{string}> */
    public static function advanceTaxDocumentTitles(): array
    {
        return [
            'k přijaté platbě'      => ['Daňový doklad k přijaté platbě'],
            'bez diakritiky'        => ['DANOVY DOKLAD K PRIJATE PLATBE'],
            'k provedené platbě'    => ['Daňový doklad k provedené platbě č. 12'],
            'k záloze'              => ['Daňový doklad k záloze'],
            'přijatá platba pomlčka' => ['Daňový doklad – přijatá platba'],
            'doklad o přijaté úhradě' => ['Doklad o přijaté úhradě k záloze 2026001'],
            'ze zálohy'             => ['DPH ze zálohy dle zálohové faktury 2026001'],
            'zaplacená záloha'      => ['Vyúčtování zaplacené zálohy'],
            'před uskutečněním'     => ['Platba před uskutečněním plnění'],
            'anglicky'              => ['Tax document — advance payment'],
        ];
    }

    #[DataProvider('advanceTaxDocumentTitles')]
    public function testQualifiedTextIsAdvanceTaxDocument(string $text): void
    {
        self::assertTrue(
            AdvanceTaxDocumentText::indicatesAdvanceTaxDocument($text),
            "„{$text}" . '" je daňový doklad k platbě / záloze',
        );
    }

    public function testAnyIndicatesScansWholeCollectionAndIgnoresNonStrings(): void
    {
        self::assertFalse(AdvanceTaxDocumentText::anyIndicatesAdvanceTaxDocument([
            'Daňový doklad', 'Základní Vodafone služby', null, 42,
        ]));
        self::assertTrue(AdvanceTaxDocumentText::anyIndicatesAdvanceTaxDocument([
            'Daňový doklad', null, 'Záloha — daňový doklad k přijaté platbě',
        ]));
    }
}
