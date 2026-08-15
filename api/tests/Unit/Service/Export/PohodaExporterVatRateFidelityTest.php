<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Export\PohodaXmlExporter;
use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Věrnost sazby ve VLASTNÍM Pohoda exportu.
 *
 * Motivace (naměřeno round-tripem přes produkt): export zapisoval jen
 * `<inv:rateVAT>high</inv:rateVAT>` a řetězec „percentVAT" se ve výstupu neobjevil ani
 * jednou. Enum je ale jen sazbová ÚROVEŇ — kdokoli, kdo ho čte, za `high` dosadí sazbu,
 * kterou zrovna považuje za základní. Reimport vlastního souboru tak z 23 % udělal 21 %,
 * shodil `oss_applicable` a doklad skončil na ř. 1 českého přiznání bez varování.
 *
 * Testy tedy hlídají tři věci:
 *   1. ke KAŽDÉ položce se zapíše `<inv:percentVAT>` se skutečnou sazbou (a na místě,
 *      které předepisuje sekvence invoice.xsd — hned za `rateVAT`);
 *   2. doklad v režimu OSS se do Pohoda XML vůbec nepustí (schema pro zahraniční sazbu
 *      nemá místo, obdoba StereoXmlExporter);
 *   3. 3. sazba (10 %) neztrácí daň ani se z ní nestane osvobozené plnění — a nepoužívá
 *      enum `low2`, který ve `typ:vatRateEnum` NEEXISTUJE.
 *
 * Data jsou čistě syntetická, exportér i parser pracují nad polem — DB se nesahá.
 */
final class PohodaExporterVatRateFidelityTest extends TestCase
{
    private const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    private PohodaXmlExporter $exporter;

    protected function setUp(): void
    {
        $tax = $this->createStub(TaxConstantsRepository::class);
        $tax->method('vatBucketThreshold')->willReturn(20.5);

        $this->exporter = new PohodaXmlExporter(
            $this->createStub(InvoiceRepository::class),
            $this->createStub(Connection::class),
            $tax,
        );
    }

    // ─── percentVAT ───

    public function testEveryItemCarriesPercentVatWithItsRealRate(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item(['vat_rate_snapshot' => 21.0]),
                $this->item(['vat_rate_snapshot' => 12.0]),
                $this->item(['vat_rate_snapshot' => 10.0]),
                $this->item(['vat_rate_snapshot' => 0.0]),
            ],
        ])], $this->cfg());

        self::assertSame(
            ['21.00', '12.00', '10.00', '0.00'],
            $this->xpathAll($xml, '//inv:invoiceItem/inv:percentVAT'),
        );
        // Regrese na naměřený stav: ve výstupu tenhle element dřív nebyl ANI JEDNOU.
        self::assertStringContainsString('percentVAT', $xml);
    }

    public function testPercentVatIsWrittenEvenForExemptLineSoLevelAndPercentAlwaysComeAsPair(): void
    {
        // U `none` je procento nadbytečné jen zdánlivě: teprve dvojice (úroveň, procento)
        // je jednoznačná a chybějící element je pro čtenáře signál „sazba není známá".
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [$this->item(['vat_rate_snapshot' => 0.0, 'total_vat' => 0.0, 'total_with_vat' => 2520.0])],
        ])], $this->cfg());

        self::assertSame('none', $this->xpathOne($xml, '//inv:invoiceItem/inv:rateVAT'));
        self::assertSame('0.00', $this->xpathOne($xml, '//inv:invoiceItem/inv:percentVAT'));
    }

    public function testPercentVatFollowsRateVatAsSchemaSequenceRequires(): void
    {
        // invoice.xsd: payVAT → rateVAT → percentVAT → discountPercentage. Jiné pořadí
        // elementů Pohoda odmítne, i když jsou všechny přítomné.
        $xml = $this->exporter->buildXml([$this->invoice()], $this->cfg());

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $row = $dom->getElementsByTagNameNS(self::NS_INV, 'invoiceItem')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row);

        $order = [];
        foreach ($row->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $order[] = $child->localName;
            }
        }
        $rateIndex = array_search('rateVAT', $order, true);
        $percentIndex = array_search('percentVAT', $order, true);
        self::assertIsInt($rateIndex);
        self::assertIsInt($percentIndex);
        self::assertSame($rateIndex + 1, $percentIndex, 'percentVAT musí následovat hned za rateVAT');
        self::assertLessThan($percentIndex, (int) array_search('payVAT', $order, true));
    }

    public function testForeignCurrencyInvoiceAlsoCarriesPercentVat(): void
    {
        // Cizoměnový doklad má položky v inv:foreignCurrency — percentVAT se na něm
        // neztrácí, sazba je vlastnost položky, ne cenového bloku.
        $xml = $this->exporter->buildXml([$this->invoice([
            'currency'      => 'EUR',
            'exchange_rate' => 24.5,
        ])], $this->cfg());

        self::assertSame('21.00', $this->xpathOne($xml, '//inv:invoiceItem/inv:percentVAT'));
    }

    // ─── Round trip vlastním parserem ───

    public function testOwnParserReadsExactRateFromOurExportNotFromEnumGuess(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item(['vat_rate_snapshot' => 21.0]),
                $this->item(['vat_rate_snapshot' => 10.0, 'total_vat' => 252.0, 'total_with_vat' => 2772.0]),
            ],
            'vat_breakdown' => [
                ['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2],
                ['rate' => 10.0, 'base' => 2520.0, 'vat' => 252.0],
            ],
            'totals' => ['without_vat' => 5040.0, 'with_vat' => 5821.2, 'rounding' => 0.0],
        ])], $this->cfg());

        $parsed = (new PohodaXmlParser())->parse($xml);
        $items = $parsed['invoices'][0]['items'];

        self::assertSame(21.0, $items[0]['vat_rate']);
        self::assertSame(10.0, $items[1]['vat_rate']);
        // Zdroj musí být `percent`, ne dohad z enumu ani dopočet z rekapitulace — jinak
        // by soubor sazbu nesl jen nepřímo a jiný čtenář by ji uhodl špatně.
        self::assertSame('percent', $items[0]['vat_rate_source']);
        self::assertSame('percent', $items[1]['vat_rate_source']);
    }

    // ─── OSS ───

    public function testOssInvoiceIsRefusedInsteadOfBeingExportedAsDomestic(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pohoda XML nepodporuje OSS plnění (doklad #2026001)');

        $this->exporter->buildXml([$this->invoice([
            'items' => [$this->item([
                'vat_rate_snapshot'    => 23.0,
                'oss_applicable'       => 1,
                'oss_consumer_country' => 'PL',
            ])],
        ])], $this->cfg());
    }

    public function testOssRefusalTellsWhereToReportTheLine(): void
    {
        try {
            $this->exporter->buildXml([$this->invoice([
                'items' => [$this->item(['oss_applicable' => true])],
            ])], $this->cfg());
            self::fail('OSS doklad musí export odmítnout.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('OSS přiznání', $e->getMessage());
            self::assertStringContainsString('vyřaďte', $e->getMessage());
        }
    }

    public function testMixedInvoiceWithSingleOssLineIsRefusedToo(): void
    {
        // Smíšený doklad je horší než celý OSS: tuzemská část projde a zahraniční se
        // v Pohodě tiše promění na českou. Odmítáme celý doklad.
        $this->expectException(\RuntimeException::class);

        $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item(['vat_rate_snapshot' => 21.0]),
                $this->item(['vat_rate_snapshot' => 23.0, 'oss_applicable' => 1, 'oss_consumer_country' => 'PL']),
            ],
        ])], $this->cfg());
    }

    public function testOssInvoiceInBulkPackRefusesWholeExportAndNamesTheDocument(): void
    {
        // Balík více dokladů: hláška musí ukázat na TEN doklad, který export blokuje.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('#2026007');

        $this->exporter->buildXml([
            $this->invoice(['id' => 1, 'varsymbol' => '2026001']),
            $this->invoice([
                'id' => 7,
                'varsymbol' => '2026007',
                'items' => [$this->item(['oss_applicable' => 1])],
            ]),
        ], $this->cfg());
    }

    public function testNonOssInvoiceIsStillExported(): void
    {
        // Pojistka, že se guard nechytá na prázdném/nulovém příznaku.
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [$this->item(['oss_applicable' => 0, 'oss_consumer_country' => null])],
        ])], $this->cfg());

        self::assertSame('21.00', $this->xpathOne($xml, '//inv:invoiceItem/inv:percentVAT'));
    }

    // ─── 3. sazba (10 %) ───

    public function testThirdRateUsesSchemaEnumNotNonexistentLow2(): void
    {
        // `low2` ve `typ:vatRateEnum` NENÍ (none|high|low|third|historyHigh|historyLow|
        // historyThird) — Pohoda by na něm odmítla celý dataPack.
        $xml = $this->exporter->buildXml([$this->thirdRateInvoice()], $this->cfg());

        self::assertSame('third', $this->xpathOne($xml, '//inv:invoiceItem/inv:rateVAT'));
        self::assertStringNotContainsString('low2', $xml);
    }

    public function testThirdRateVatReachesSummaryInsteadOfBeingDropped(): void
    {
        // Dřív spadl základ 10% plnění do priceNone a DAŇ se do rekapitulace nedostala
        // vůbec — doklad si sám odporoval (položka s daní, rekapitulace bez ní).
        $xml = $this->exporter->buildXml([$this->thirdRateInvoice()], $this->cfg());

        $home = '//inv:invoiceSummary/inv:homeCurrency/';
        self::assertSame('2520.00', $this->xpathOne($xml, $home . 'typ:price3'));
        self::assertSame('252.00', $this->xpathOne($xml, $home . 'typ:price3VAT'));
        self::assertSame('2772.00', $this->xpathOne($xml, $home . 'typ:price3Sum'));
        self::assertSame('0.00', $this->xpathOne($xml, $home . 'typ:priceNone'));
    }

    public function testThirdRateIsInlandNotExempt(): void
    {
        // maxRate 10 propadala na UNX/nonSubsume = „nezahrnovat do DPH". Zdaněné
        // tuzemské plnění se tím importovalo jako osvobozené.
        $xml = $this->exporter->buildXml([$this->thirdRateInvoice()], $this->cfg());

        self::assertSame('inland', $this->xpathOne(
            $xml, '//inv:invoiceHeader/inv:classificationVAT/typ:classificationVATType'));
        self::assertNull($this->xpathOne(
            $xml, '//inv:invoiceHeader/inv:classificationVAT/typ:ids'),
            'členění pro 3. sazbu je instalace-specifické, kód se nemá vymýšlet');
    }

    public function testItemBucketAndSummaryBucketAgreeOnMixedInvoice(): void
    {
        // Hranice v rateCode() a v přihrádkách rekapitulace musí být tytéž — jinak
        // položka sedí v jiné přihrádce než její vlastní částky.
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item(['vat_rate_snapshot' => 21.0]),
                $this->item(['vat_rate_snapshot' => 12.0, 'total_vat' => 302.4, 'total_with_vat' => 2822.4]),
                $this->item(['vat_rate_snapshot' => 10.0, 'total_vat' => 252.0, 'total_with_vat' => 2772.0]),
            ],
            'vat_breakdown' => [
                ['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2],
                ['rate' => 12.0, 'base' => 2520.0, 'vat' => 302.4],
                ['rate' => 10.0, 'base' => 2520.0, 'vat' => 252.0],
            ],
            'totals' => ['without_vat' => 7560.0, 'with_vat' => 8643.6, 'rounding' => 0.0],
        ])], $this->cfg());

        self::assertSame(['high', 'low', 'third'], $this->xpathAll($xml, '//inv:invoiceItem/inv:rateVAT'));

        $home = '//inv:invoiceSummary/inv:homeCurrency/';
        self::assertSame('2520.00', $this->xpathOne($xml, $home . 'typ:priceHigh'));
        self::assertSame('529.20', $this->xpathOne($xml, $home . 'typ:priceHighVAT'));
        self::assertSame('2520.00', $this->xpathOne($xml, $home . 'typ:priceLow'));
        self::assertSame('302.40', $this->xpathOne($xml, $home . 'typ:priceLowVAT'));
        self::assertSame('2520.00', $this->xpathOne($xml, $home . 'typ:price3'));
        self::assertSame('252.00', $this->xpathOne($xml, $home . 'typ:price3VAT'));
    }

    public function testForeignCzkRecapAlsoFillsThirdBucket(): void
    {
        // Cizoměnová větev (czk_recap) měla stejnou díru jako tuzemská.
        $xml = $this->exporter->buildXml([$this->invoice([
            'currency'      => 'EUR',
            'exchange_rate' => 25.0,
            'items'         => [$this->item(['vat_rate_snapshot' => 10.0, 'total_vat' => 252.0, 'total_with_vat' => 2772.0])],
            'vat_breakdown' => [['rate' => 10.0, 'base' => 2520.0, 'vat' => 252.0]],
            'czk_recap'     => ['breakdown' => [['rate' => 10.0, 'base_czk' => 63000.0, 'vat_czk' => 6300.0]]],
            'totals'        => ['without_vat' => 2520.0, 'with_vat' => 2772.0, 'rounding' => 0.0],
        ])], $this->cfg());

        $home = '//inv:invoiceSummary/inv:homeCurrency/';
        self::assertSame('63000.00', $this->xpathOne($xml, $home . 'typ:price3'));
        self::assertSame('6300.00', $this->xpathOne($xml, $home . 'typ:price3VAT'));
    }

    // ─── Helpers ───

    /** @return list<string> */
    private function xpathAll(string $xml, string $expr): array
    {
        $out = [];
        foreach ($this->xpath($xml)->query($expr) as $node) {
            $out[] = $node->textContent;
        }
        return $out;
    }

    private function xpathOne(string $xml, string $expr): ?string
    {
        return $this->xpath($xml)->query($expr)->item(0)?->textContent;
    }

    private function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('inv', self::NS_INV);
        $xp->registerNamespace('typ', self::NS_TYP);
        return $xp;
    }

    /** @return array<string,mixed> */
    private function cfg(): array
    {
        return ['ic' => '01698401'];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function thirdRateInvoice(array $overrides = []): array
    {
        return $this->invoice(array_merge([
            'issue_date'    => '2023-05-04',
            'tax_date'      => '2023-05-04',
            'items'         => [$this->item(['vat_rate_snapshot' => 10.0, 'total_vat' => 252.0, 'total_with_vat' => 2772.0])],
            'vat_breakdown' => [['rate' => 10.0, 'base' => 2520.0, 'vat' => 252.0]],
            'totals'        => ['without_vat' => 2520.0, 'with_vat' => 2772.0, 'rounding' => 0.0],
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'id'                => 1,
            'invoice_type'      => 'invoice',
            'varsymbol'         => '2026001',
            'issue_date'        => '2026-05-04',
            'tax_date'          => '2026-05-04',
            'due_date'          => '2026-05-18',
            'currency'          => 'CZK',
            'exchange_rate'     => null,
            'reverse_charge'    => false,
            'project_number'    => null,
            'note_above_items'  => null,
            'supplier_snapshot' => [
                'ic' => '01698401', 'dic' => 'CZ01698401', 'company_name' => 'Dodavatel s.r.o.',
                'street' => 'Zkušební 123/4', 'city' => 'Vzorov', 'zip' => '10000',
                'country_iso2' => 'CZ',
            ],
            'client_snapshot'   => [
                'ic' => '27140130', 'dic' => 'CZ27140130', 'company_name' => 'Odběratel a.s.',
                'street' => 'Václavské náměstí 1', 'city' => 'Praha 1', 'zip' => '11000', 'country_iso2' => 'CZ',
            ],
            'items'             => [$this->item()],
            'vat_breakdown'     => [['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2]],
            'totals'            => ['without_vat' => 2520.0, 'with_vat' => 3049.2, 'rounding' => 0.0],
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'description'            => 'Vývoj systému',
            'quantity'               => 1.0,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 2520.0,
            'vat_rate_snapshot'      => 21.0,
            'total_without_vat'      => 2520.0,
            'total_vat'              => 529.2,
            'total_with_vat'         => 3049.2,
        ], $overrides);
    }
}
