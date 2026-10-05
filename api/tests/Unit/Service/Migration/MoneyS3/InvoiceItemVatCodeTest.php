<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\InvoiceImporter;
use PHPUnit\Framework\TestCase;

/**
 * Členění DPH po položkách faktury Money (`VFaktPol.KodDPH`) a klíče, pod kterými se
 * hledá interní doklad se samovyměřením.
 */
final class InvoiceItemVatCodeTest extends TestCase
{
    /** Nájem bytu (osvobozený, ř. 50) a zabezpečení (21 %, ř. 1) na jedné faktuře. */
    private const HEADER = [
        'Druh' => 'N', 'Zaklad_0' => 5400.0, 'Zaklad_2' => 100.0, 'SazbaDPH2' => 21.0, 'DPH_2' => 21.0, 'CelkemSDPH' => 5521.0,
    ];

    private static function classify(array $r, array $items): array
    {
        $amounts = [
            'items' => [
                ['base' => 5400.0, 'rate' => 0.0, 'vat' => 0.0, 'rate_id' => 1],
                ['base' => 100.0, 'rate' => 21.0, 'vat' => 21.0, 'rate_id' => 2],
            ],
            'base' => 5500.0, 'vat' => 21.0, 'total' => 5521.0, 'rounding' => 0.0,
        ];
        $lineCodes = new \ReflectionMethod(InvoiceImporter::class, 'lineCodes');
        $classifyLines = new \ReflectionMethod(InvoiceImporter::class, 'classifyLines');
        return $classifyLines->invoke(null, $r, true, $amounts, $lineCodes->invoke(null, $r, $items));
    }

    public function testExemptHeaderWithTaxableItemIsNotReview(): void
    {
        // Hlavička 19Ř50, položka zabezpečení 19Ř01,02 — Money ji vykázalo v ř. 1.
        [$class, $amounts] = self::classify(['KodDPH' => '19Ř50'] + self::HEADER, [
            ['SazbaDPH' => 0.0, 'KodDPH' => '19Ř50', 'Cena' => 5400.0],
            ['SazbaDPH' => 21.0, 'KodDPH' => '19Ř01,02', 'Cena' => 100.0],
        ]);

        self::assertSame([], $class['reasons']);
        self::assertSame(['3', null], array_column($amounts['items'], 'code'));
    }

    public function testTaxableHeaderWithExemptItemKeepsRow50(): void
    {
        // Hlavička 19Ř01,02, položka nájmu bez kódu by dřív ztratila ř. 50.
        [$class, $amounts] = self::classify(['KodDPH' => '19Ř01,02'] + self::HEADER, [
            ['SazbaDPH' => 0.0, 'KodDPH' => '19Ř50', 'Cena' => 5400.0],
            ['SazbaDPH' => 21.0, 'KodDPH' => '', 'Cena' => 100.0],
        ]);

        self::assertSame([], $class['reasons']);
        self::assertSame(['3', null], array_column($amounts['items'], 'code'));
    }

    public function testItemsWithoutOwnCodesKeepHeaderClassification(): void
    {
        [$class, $amounts] = self::classify(['KodDPH' => '19Ř50'] + self::HEADER, [
            ['SazbaDPH' => 0.0, 'KodDPH' => '', 'Cena' => 5400.0],
            ['SazbaDPH' => 21.0, 'KodDPH' => '', 'Cena' => 100.0],
        ]);

        self::assertNotSame([], $class['reasons'], 'Plnění bez daně u dokladu s daní zůstává ke kontrole.');
        self::assertArrayNotHasKey('code', $amounts['items'][0]);
    }

    public function testSelfAssessmentKeys(): void
    {
        self::assertSame(['FP241001', '#241001', '@DF-2024-001'], InvoiceImporter::selfAssessmentKeys('PDP k FP241001', 'DF-2024-001'));
        self::assertSame(['FP241002', '#241002'], InvoiceImporter::selfAssessmentKeys('PDP k fp 241002', ''));
        self::assertSame(['Z241003', '#241003'], InvoiceImporter::selfAssessmentKeys('Přiznání daně z 241003 - montáž', ''));
        self::assertSame(['PFZ190001', '#190001'], InvoiceImporter::selfAssessmentKeys('RCH k PFZ190001', ''));
        self::assertSame([''], InvoiceImporter::selfAssessmentKeys('Samovyměření', ''));
    }
}
