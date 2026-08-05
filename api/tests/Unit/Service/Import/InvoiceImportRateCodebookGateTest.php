<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InvoiceImportService;
use PHPUnit\Framework\TestCase;

/**
 * F2 — KDY SE IMPORT VŮBEC PTÁ ČÍSELNÍKU SAZEB ČLENSKÝCH STÁTŮ.
 *
 * Od zavedení totálního invariantu ({@see \MyInvoice\Service\Oss\OssItemDeriver}) platí, že
 * do tuzemské větve smí jen řádek, u kterého číselník POZITIVNĚ potvrdil platnost sazby
 * v zemi dodavatele. Chybějící číselník tím přestal být kosmetickou mezerou: odmítl by
 * KAŽDOU položku se sazbou vyšší než 0 %, tedy u zákazníkovy migrace 1 670 nesrozumitelných
 * hlášek místo jedné. Proto se ptáme JEDNOU za běh, ještě než se zapíše první doklad.
 *
 * Tenhle test hlídá VSTUP té otázky — tedy které řádky balíku ji činí nutnou. Je to
 * nejtišší část celého mechanismu: ptát se zbytečně znamená zastavit běžný tuzemský import
 * falešným poplachem, neptat se tam, kde je potřeba, znamená pustit běh do 1 670 odmítnutí.
 *
 * Odpověď na tu otázku (tři rozlišené stavy číselníku a hlášky k nim) se testuje
 * integračně v `Tests\Integration\Import\OssInvoiceImportTest` — potřebuje databázi.
 *
 * Bez konstruktoru: obě volané metody čtou jen své argumenty (`detectRoute()` porovnává
 * IČO, kanonizace je statická), na žádnou závislost nesahají. Týž vzor používá
 * {@see InvoiceImportReportTest}.
 */
final class InvoiceImportRateCodebookGateTest extends TestCase
{
    private const TENANT_IC = '12345678';
    private const OTHER_IC = '87654321';

    private InvoiceImportService $service;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(InvoiceImportService::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param  list<array<string,mixed>> $parsed
     * @return list<string>
     */
    private function dates(array $parsed, string $kind = 'issued'): array
    {
        /** @var list<string> */
        return (new \ReflectionMethod(InvoiceImportService::class, 'datesNeedingRateCodebook'))
            ->invokeArgs($this->service, [$parsed, self::TENANT_IC, $kind]);
    }

    /**
     * @param  array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function invoice(array $overrides = []): array
    {
        return $overrides + [
            'supplier'   => ['ic' => self::TENANT_IC],
            'client'     => ['ic' => self::OTHER_IC],
            'issue_date' => '2096-05-15',
            'tax_date'   => '2096-05-15',
            'items'      => [['vat_rate' => 23.0]],
        ];
    }

    /**
     * @param  list<array<string,mixed>> $invoices
     * @return list<array<string,mixed>>
     */
    private function bundle(array $invoices): array
    {
        return [['file' => 'davka.xml', 'invoices' => $invoices]];
    }

    /** Vydaný doklad se sazbou > 0 je jediný, který derivací prochází — na ten se ptáme. */
    public function testIssuedRowWithARealRateMakesTheQuestionNecessary(): void
    {
        self::assertSame(['2096-05-15'], $this->dates($this->bundle([$this->invoice()])));
    }

    /**
     * Otázka zní „platí ta sazba v zemi dodavatele K DATU PLNĚNÍ", takže se ptá kanonickým
     * datem. Nekanonický tvar by se porovnával s `valid_from` jako řetězec a odpověděl na
     * jiné období — přesně mechanismus úniku č. 2, jen o patro výš.
     */
    public function testDateIsCanonicalisedBeforeTheQuestionIsAsked(): void
    {
        $dates = $this->dates($this->bundle([
            $this->invoice(['issue_date' => '2096-5-15', 'tax_date' => '2096-5-15']),
        ]));

        self::assertSame(['2096-05-15'], $dates);
    }

    /** DUZP má přednost, datum vystavení je fallback — stejně jako v `processOne()`. */
    public function testTaxDateWinsOverIssueDateAndEmptyTaxDateFallsBack(): void
    {
        self::assertSame(['2096-05-31'], $this->dates($this->bundle([
            $this->invoice(['issue_date' => '2096-05-15', 'tax_date' => '2096-05-31']),
        ])));

        self::assertSame(['2096-05-15'], $this->dates($this->bundle([
            $this->invoice(['tax_date' => null]),
        ])));
    }

    /**
     * Nulová sazba je z invariantu vyňatá (bez daně není co unikat), takže na ní odpověď
     * číselníku nezáleží. Kdyby se započítávala, zastavil by pre-flight i běh, kde není
     * co zastavovat — a falešný poplach je u brány, která NEIMPORTUJE NIC, drahý.
     */
    public function testZeroRatedRowsDoNotNeedTheCodebook(): void
    {
        self::assertSame([], $this->dates($this->bundle([
            $this->invoice(['items' => [['vat_rate' => 0.0]]]),
        ])));
    }

    /**
     * Neurčená sazba (`historyHigh` bez `percentVAT`) je vada dokladu s vlastní hláškou —
     * na neznámé procento se číselníku stejně není jak zeptat.
     */
    public function testUnknownRateDoesNotNeedTheCodebook(): void
    {
        self::assertSame([], $this->dates($this->bundle([
            $this->invoice(['items' => [['vat_rate' => null]]]),
        ])));
    }

    /** Doklad, který se stejně odmítne kvůli datu, nesmí shodit celý běh. */
    public function testDocumentWithAnUnusableDateIsLeftToItsOwnRejection(): void
    {
        self::assertSame([], $this->dates($this->bundle([
            $this->invoice(['issue_date' => '15. 5. 2096', 'tax_date' => null]),
        ])));
    }

    /**
     * Přijatá faktura derivací neprochází, takže by kontrola číselníku u čistě nákupního
     * importu byla čirý falešný poplach.
     */
    public function testPurchaseImportNeverAsks(): void
    {
        $purchase = $this->invoice(['supplier' => ['ic' => self::OTHER_IC], 'client' => ['ic' => self::TENANT_IC]]);

        self::assertSame([], $this->dates($this->bundle([$purchase]), 'purchase'));
        self::assertSame([], $this->dates($this->bundle([$purchase]), 'auto'));
    }

    /** Doklad cizího plátce se nezpracuje, takže ani neurčuje, na co se ptát. */
    public function testDocumentRoutedAwayIsIgnored(): void
    {
        self::assertSame([], $this->dates($this->bundle([
            $this->invoice(['supplier' => ['ic' => self::OTHER_IC]]),
        ])));
    }

    /** Nerozparsovaný soubor ani vadný doklad nesmí bránu shodit dřív, než se vypíše. */
    public function testBrokenEntriesAreSkippedInsteadOfCrashingTheGate(): void
    {
        $parsed = [
            ['file' => 'rozbite.xml', 'error' => 'Nepodařilo se načíst XML.'],
            ['file' => 'davka.xml', 'invoices' => [
                ['__error' => 'Doklad nemá variabilní symbol.'],
                'tohle není pole',
                $this->invoice(),
            ]],
        ];

        self::assertSame(['2096-05-15'], $this->dates($parsed));
    }

    /**
     * Balík s 1 670 doklady se ptá na UNIKÁTNÍ data, ne na 1 670 hodnot — pre-flight má
     * být jeden dotaz, ne skrytá smyčka přes celý import.
     */
    public function testDatesAreDeduplicatedAcrossTheWholeBundle(): void
    {
        $dates = $this->dates($this->bundle([
            $this->invoice(),
            $this->invoice(),
            $this->invoice(['tax_date' => '2096-06-30']),
        ]));

        sort($dates);
        self::assertSame(['2096-05-15', '2096-06-30'], $dates);
    }

    /** Rozhoduje kterýkoli řádek dokladu, ne ten první — smíšený doklad se musí zeptat. */
    public function testASingleTaxedRowAmongExemptOnesIsEnough(): void
    {
        self::assertSame(['2096-05-15'], $this->dates($this->bundle([
            $this->invoice(['items' => [['vat_rate' => 0.0], ['vat_rate' => null], ['vat_rate' => 23.0]]]),
        ])));
    }
}
